#!/usr/bin/env php
<?php
/**
 * generate_mosquitto_passwd.php
 * Gera arquivo passwd do Mosquitto com base em mqtt_credentials
 * 
 * Nota: Mosquitto espera hashes PBKDF2 no formato $7$iterations$salt$hash.
 * Consolida os hashes já gravados em mqtt_credentials pelo
 * generate_mqtt_credentials.php num único arquivo passwords.txt.
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('CONFIG_DIR', ROOT_DIR . '/src/config');
// Volume compartilhado com o container mosquitto (ver docker-compose.yml,
// volume 'mqtt_config' montado em /mosquitto/config). /etc/mosquitto não
// existe na imagem do app.
define('MOSQUITTO_CONF_DIR', '/mosquitto/config');

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';

echo "=== Gerador de Arquivo Passwd do Mosquitto ===\n\n";

try {
    // Lê credenciais do banco
    $stmt = $conn->prepare("
        SELECT 
            mqtt_username,
            mqtt_password_hash
        FROM mqtt_credentials
        WHERE enabled = 1
        ORDER BY mqtt_username
    ");
    $stmt->execute();
    
    $credentials = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    if (empty($credentials)) {
        echo "❌ Nenhuma credencial MQTT encontrada. Execute generate_mqtt_credentials.php primeiro.\n";
        exit(1);
    }
    
    echo "🔐 Gerando arquivo passwd para " . count($credentials) . " usuários...\n\n";

    // O nome do arquivo tem que ser exatamente o que mosquitto.conf espera
    // (password_file /mosquitto/config/passwords.txt)
    $passwd_file = MOSQUITTO_CONF_DIR . '/passwords.txt';

    if (!is_dir(MOSQUITTO_CONF_DIR)) {
        $passwd_file = '/tmp/mosquitto_passwd';
        echo "⚠️  Diretório do Mosquitto não existe. Salvando em: $passwd_file\n";
    }

    // mqtt_credentials.mqtt_password_hash já é gravado no formato PBKDF2 do
    // Mosquitto ($7$iterations$salt$hash) por generate_mqtt_credentials.php /
    // generate_mqtt_passwd_file.php — aqui só consolidamos num único arquivo.
    $passwd_content = "";
    $skipped = 0;

    foreach ($credentials as $cred) {
        $username = $cred['mqtt_username'];
        $hash = $cred['mqtt_password_hash'];

        if (empty($hash) || strpos($hash, '$7$') !== 0) {
            echo "⚠️  Pulando $username: hash ausente ou em formato incompatível com o Mosquitto (esperado \$7\$...). Rode generate_mqtt_credentials.php novamente para este dispositivo.\n";
            $skipped++;
            continue;
        }

        $passwd_content .= "{$username}:{$hash}\n";
        echo "📝 Usuário: $username\n";
    }

    if (file_put_contents($passwd_file, $passwd_content) === false) {
        echo "❌ Erro: Impossível escrever em $passwd_file\n";
        exit(1);
    }
    chmod($passwd_file, 0600);

    echo "\n✅ Arquivo passwd gerado com sucesso!\n";
    echo "📄 Arquivo: $passwd_file\n";
    echo "📊 Usuários gravados: " . (count($credentials) - $skipped) . " (pulados: $skipped)\n\n";

    // Este script sobrescreve o arquivo inteiro a cada execução, então o
    // usuário admin (usado pelo worker MQTT e por scripts administrativos)
    // precisa ser regravado aqui sempre — caso contrário toda regeneração
    // (inclusive as automáticas feitas por docker-entrypoint.sh a cada boot
    // do container worker) apaga o admin e derruba a autenticação do worker.
    // O binário mosquitto_passwd não está disponível nas imagens web/worker
    // (só nos pacotes do broker), então o hash é gerado em PHP com a mesma
    // função usada para os dispositivos (PBKDF2-SHA512, formato $7$...).
    $admin_user = env('MQTT_USERNAME', 'admin');
    $admin_pass = env('MQTT_PASSWORD', 'ifsentral_admin_2024');
    $admin_salt = random_bytes(12);
    $admin_hash_raw = hash_pbkdf2('sha512', $admin_pass, $admin_salt, 101, 64, true);
    $admin_hash = sprintf('$7$%d$%s$%s', 101, base64_encode($admin_salt), base64_encode($admin_hash_raw));

    if (file_put_contents($passwd_file, "{$admin_user}:{$admin_hash}\n", FILE_APPEND) === false) {
        echo "⚠️  Não foi possível sincronizar o usuário admin ('$admin_user') no arquivo de senhas.\n\n";
    } else {
        chmod($passwd_file, 0600);
        echo "🔑 Usuário '$admin_user' (admin) sincronizado no arquivo de senhas.\n\n";
    }

    echo "=== Próximos Passos ===\n";
    echo "1. Gerar arquivo ACL:\n";
    echo "   php src/mqtt/generate_mosquitto_acl.php\n\n";
    echo "2. Se está em /tmp, copie para o volume compartilhado:\n";
    echo "   cp $passwd_file " . MOSQUITTO_CONF_DIR . "/passwords.txt\n\n";
    echo "3. Reinicie o container mosquitto para recarregar as credenciais:\n";
    echo "   docker-compose restart mosquitto\n\n";
    
} catch (\Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
?>
