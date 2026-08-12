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
        $this->passwd_file = '/etc/mosquitto/passwd';
        $this->acl_file = '/etc/mosquitto/conf.d/acl.acl';
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
        
        // Adiciona admin (se existir)
        $adminStmt = $this->conn->prepare("
            SELECT mqtt_username, mqtt_password_hash 
            FROM mqtt_credentials 
            WHERE mqtt_username = 'admin' AND enabled = 1
        ");
        $adminStmt->execute();
        $admin = $adminStmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($admin) {
            $content .= "\n# Admin user\n{$admin['mqtt_username']}:{$admin['mqtt_password_hash']}\n";
        }
        
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
        
        // Admin com acesso total
        $adminStmt = $this->conn->prepare("
            SELECT mqtt_username 
            FROM mqtt_credentials 
            WHERE mqtt_username = 'admin' AND enabled = 1
        ");
        $adminStmt->execute();
        
        if ($adminStmt->rowCount() > 0) {
            $content .= "user admin\n";
            $content .= "topic readwrite #\n\n";
        }
        
        return $content;
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
            
            // Tenta mover para /etc/mosquitto
            if (is_writable('/etc/mosquitto')) {
                // Move passwd
                $mv_passwd = "mv $passwd_tmp $this->passwd_file 2>&1";
                exec($mv_passwd, $output1, $code1);
                
                if ($code1 === 0) {
                    exec("chown mosquitto:mosquitto $this->passwd_file 2>&1");
                    exec("chmod 600 $this->passwd_file 2>&1");
                }
                
                // Move ACL
                $mv_acl = "mv $acl_tmp $this->acl_file 2>&1";
                exec($mv_acl, $output2, $code2);
                
                if ($code2 === 0) {
                    exec("chown mosquitto:mosquitto $this->acl_file 2>&1");
                    exec("chmod 644 $this->acl_file 2>&1");
                }
                
                if ($code1 === 0 && $code2 === 0) {
                    $this->log("Arquivos salvos em /etc/mosquitto/");
                    return ['success' => true];
                }
            }
            
            // Se falhou ou não tem permissão, mantém em /tmp
            $this->log("⚠️ Arquivos em /tmp (sem permissão em /etc/mosquitto)");
            $this->log("Execute: sudo mv $passwd_tmp $this->passwd_file");
            $this->log("Execute: sudo mv $acl_tmp $this->acl_file");
            $this->log("Execute: sudo chown mosquitto:mosquitto $this->passwd_file $this->acl_file");
            $this->log("Execute: sudo systemctl reload mosquitto");
            
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
