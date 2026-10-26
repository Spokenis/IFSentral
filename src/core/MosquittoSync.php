<?php
/**
 * MosquittoSync.php
 * Classe para sincronização automática de credenciais MQTT com Mosquitto
 * 
 * Sincroniza credenciais do banco de dados para arquivos do Mosquitto
 * sem causar downtime no serviço.
 */

class MosquittoSync {
    
    private $conn;
    private $silent;
    private $passwd_file;
    private $acl_file;
    
    /**
     * @param PDO $conn Conexão com banco de dados
     * @param bool $silent Se true, não exibe mensagens (para uso em APIs)
     */
    public function __construct($conn, $silent = false) {
        $this->conn = $conn;
        $this->silent = $silent;
        // Deve apontar para o volume compartilhado 'mqtt_config' (ver docker-compose.yml),
        // montado em /mosquitto/config nos containers web, worker E mosquitto — não existe
        // /etc/mosquitto na imagem do app, então esses caminhos eram inacessíveis.
        $this->passwd_file = '/mosquitto/config/passwords.txt';
        $this->acl_file = '/mosquitto/config/acl.acl';
    }
    
    /**
     * Sincroniza credenciais do banco com Mosquitto
     * @return array ['success' => bool, 'message' => string, 'devices_synced' => int]
     */
    public function sync() {
        try {
            // Lê credenciais do banco
            $stmt = $this->conn->prepare("
                SELECT 
                    mc.device_id,
                    mc.mqtt_username,
                    mc.mqtt_password_hash,
                    d.name as device_name,
                    d.project_id
                FROM mqtt_credentials mc
                JOIN devices d ON mc.device_id = d.id
                WHERE mc.enabled = 1
                ORDER BY mc.device_id
            ");
            $stmt->execute();
            
            $credentials = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            if (empty($credentials)) {
                return [
                    'success' => true,
                    'message' => 'Nenhuma credencial MQTT para sincronizar',
                    'devices_synced' => 0
                ];
            }
            
            $this->log("Sincronizando " . count($credentials) . " credenciais MQTT...");
            
            // Gera arquivos
            $passwd_content = $this->generatePasswdFile($credentials);
            $acl_content = $this->generateAclFile($credentials);
            
            // Salva arquivos
            $write_result = $this->writeFiles($passwd_content, $acl_content);
            
            if (!$write_result['success']) {
                return $write_result;
            }
            
            // Reload Mosquitto (sem downtime)
            $reload_result = $this->reloadMosquitto();
            
            $final_message = count($credentials) . " credenciais sincronizadas com sucesso!";
            if (!$reload_result['success']) {
                $final_message .= " (Aviso: Mosquitto não foi recarregado - " . $reload_result['message'] . ")";
            }
            
            return [
                'success' => true,
                'message' => $final_message,
                'devices_synced' => count($credentials),
                'reload_status' => $reload_result
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao sincronizar: ' . $e->getMessage(),
                'devices_synced' => 0
            ];
        }
    }
    
    /**
     * Gera conteúdo do arquivo passwd
     */
    private function generatePasswdFile($credentials) {
        $content = "# Auto-gerado por MosquittoSync\n";
        $content .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n";
        $content .= "# Total de dispositivos: " . count($credentials) . "\n\n";
        
        foreach ($credentials as $cred) {
            $content .= "{$cred['mqtt_username']}:{$cred['mqtt_password_hash']}\n";
        }
        
        // Usuário admin do worker: vem do ambiente (MQTT_USERNAME/MQTT_PASSWORD),
        // não da tabela mqtt_credentials. Sem ele, cada sincronização derrubava
        // o worker, que só voltava quando o contêiner reiniciava e o entrypoint
        // regravava o admin (mesma regra de generate_mosquitto_passwd.php).
        $admin_salt = random_bytes(12);
        $admin_hash_raw = hash_pbkdf2('sha512', $this->adminPassword(), $admin_salt, 101, 64, true);
        $admin_hash = sprintf('$7$%d$%s$%s', 101, base64_encode($admin_salt), base64_encode($admin_hash_raw));
        $content .= "\n# Admin user\n{$this->adminUsername()}:{$admin_hash}\n";
        
        return $content;
    }
    
    /**
     * Gera conteúdo do arquivo ACL
     */
    private function generateAclFile($credentials) {
        $content = "# Auto-gerado por MosquittoSync\n";
        $content .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n";
        $content .= "# Total de dispositivos: " . count($credentials) . "\n\n";
        
        foreach ($credentials as $cred) {
            $device_id = $cred['device_id'];
            $username = $cred['mqtt_username'];
            $project_id = $cred['project_id'];
            
            $content .= "user {$username}\n";
            $content .= "topic readwrite mqtt/projects/{$project_id}/devices/{$device_id}\n";
            $content .= "topic read mqtt/projects/{$project_id}/status\n";
            $content .= "topic read mqtt/projects/{$project_id}/config\n";
            $content .= "\n";
        }
        
        // Admin com acesso total (usuário do worker)
        $content .= "user {$this->adminUsername()}\n";
        $content .= "topic readwrite #\n\n";
        
        return $content;
    }

    private function adminUsername() {
        return env('MQTT_USERNAME', 'admin');
    }

    private function adminPassword() {
        return env('MQTT_PASSWORD', 'ifsentral_admin_2024');
    }
    
    /**
     * Escreve arquivos passwd e ACL
     */
    private function writeFiles($passwd_content, $acl_content) {
        $passwd_tmp = '/tmp/mosquitto_passwd_' . uniqid();
        $acl_tmp = '/tmp/mosquitto_acl_' . uniqid() . '.acl';
        
        try {
            // Escreve em /tmp primeiro
            file_put_contents($passwd_tmp, $passwd_content);
            file_put_contents($acl_tmp, $acl_content);
            
            chmod($passwd_tmp, 0600);
            chmod($acl_tmp, 0644);
            
            // Tenta mover para o volume compartilhado /mosquitto/config
            $config_dir = dirname($this->passwd_file);
            if (is_writable($config_dir)) {
                // Move passwd
                $mv_passwd = "mv $passwd_tmp $this->passwd_file 2>&1";
                exec($mv_passwd, $output1, $code1);

                if ($code1 === 0) {
                    // 1883:1883 é o usuário/grupo "mosquitto" na imagem eclipse-mosquitto
                    // (mesmo uid usado por docker-entrypoint.sh na geração inicial)
                    exec("chown 1883:1883 $this->passwd_file 2>&1");
                    exec("chmod 600 $this->passwd_file 2>&1");
                }

                // Move ACL
                $mv_acl = "mv $acl_tmp $this->acl_file 2>&1";
                exec($mv_acl, $output2, $code2);

                if ($code2 === 0) {
                    exec("chown 1883:1883 $this->acl_file 2>&1");
                    exec("chmod 644 $this->acl_file 2>&1");
                }

                if ($code1 === 0 && $code2 === 0) {
                    $this->log("Arquivos salvos em $config_dir/");
                    return ['success' => true];
                }
            }

            // Se falhou ou não tem permissão, mantém em /tmp
            $this->log("⚠️ Arquivos em /tmp (sem permissão em $config_dir)");
            $this->log("Execute: mv $passwd_tmp $this->passwd_file");
            $this->log("Execute: mv $acl_tmp $this->acl_file");
            $this->log("Execute: chown 1883:1883 $this->passwd_file $this->acl_file");
            $this->log("Reinicie o container mosquitto para recarregar as credenciais");
            
            return [
                'success' => false,
                'message' => 'Arquivos gerados em /tmp. Necessário copiar manualmente com sudo.',
                'passwd_file' => $passwd_tmp,
                'acl_file' => $acl_tmp
            ];
            
        } catch (\Exception $e) {
            // Limpa arquivos temporários
            @unlink($passwd_tmp);
            @unlink($acl_tmp);
            
            return [
                'success' => false,
                'message' => 'Erro ao escrever arquivos: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Recarrega Mosquitto sem downtime
     */
    private function reloadMosquitto() {
        // No Docker não há systemctl: o contêiner mosquitto vigia os arquivos
        // do volume mqtt_config e envia SIGHUP ao broker quando eles mudam
        // (ver mosquitto-entrypoint.sh).
        if (getenv('ROLE') !== false || file_exists('/.dockerenv')) {
            $this->log("✅ Arquivos atualizados; o contêiner mosquitto recarrega o broker automaticamente");
            return ['success' => true, 'message' => 'Recarga automática pelo contêiner mosquitto'];
        }

        // 'reload' preserva conexões existentes (sem downtime)
        exec("systemctl reload mosquitto 2>&1", $output, $code);
        
        if ($code === 0) {
            $this->log("✅ Mosquitto recarregado (sem downtime)");
            return ['success' => true, 'message' => 'Recarregado com sucesso'];
        }
        
        // Se falhou, pode ser falta de permissão
        $this->log("⚠️ Falha ao recarregar Mosquitto (precisa sudo)");
        
        return [
            'success' => false,
            'message' => 'Falha ao recarregar (código: ' . $code . '). Execute: sudo systemctl reload mosquitto'
        ];
    }
    
    /**
     * Log condicional
     */
    private function log($message) {
        if (!$this->silent) {
            echo $message . "\n";
        }
    }
}
?>
