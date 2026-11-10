<?php
/**
 * reparar_senhas_mqtt.php - Alinha o hash MQTT com a senha mostrada na plataforma
 *
 *   php src/mqtt/reparar_senhas_mqtt.php            # só lista o que está errado
 *   php src/mqtt/reparar_senhas_mqtt.php --aplicar  # corrige e atualiza o broker
 *   php src/mqtt/reparar_senhas_mqtt.php --aplicar --regenerar-sem-senha
 *       # também gera senha nova para quem não tem senha guardada (o firmware
 *       # desses dispositivos precisa receber a senha nova, vista na página)
 *
 * No Docker:  sudo docker compose exec web php src/mqtt/reparar_senhas_mqtt.php --aplicar
 *
 * A página do dispositivo mostra mqtt_password; o Mosquitto confere
 * mqtt_password_hash. Scripts antigos (generate_mqtt_passwd_file.php,
 * generate_mqtt_credentials.php) regeravam o hash sem trocar a senha guardada,
 * e o get_mqtt_credentials.php copiava senhas de arquivos de backup sem
 * conferir — resultado: a senha exibida era recusada pelo broker ("not
 * authorised", rc=5 no ESP32). Em 08/11/2026 nenhum dos dispositivos do banco
 * de desenvolvimento conseguia entrar.
 *
 * A correção recalcula o hash A PARTIR DA SENHA EXIBIDA, porque é ela que o
 * usuário copiou para o firmware. Credenciais sem senha guardada só são
 * listadas: para elas, use "Regenerar senha" na página do dispositivo.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/MosquittoSync.php';
require_once __DIR__ . '/MqttHash.php';

$aplicar = in_array('--aplicar', $argv, true);
$regenerar = in_array('--regenerar-sem-senha', $argv, true);

$creds = $conn->query(
    "SELECT mc.id, mc.device_id, mc.mqtt_username, mc.mqtt_password, mc.mqtt_password_hash
     FROM mqtt_credentials mc
     JOIN devices d ON d.id = mc.device_id
     WHERE mc.enabled = 1 AND d.deletedAt IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$incoerentes = [];
$semSenha = [];
foreach ($creds as $c) {
    if ($c['mqtt_password'] === null || $c['mqtt_password'] === '') {
        $semSenha[] = $c;
    } elseif (!mqtt_senha_confere($c['mqtt_password'], (string) $c['mqtt_password_hash'])) {
        $incoerentes[] = $c;
    }
}

printf("%d credenciais ativas: %d corretas, %d com senha que o broker recusa, %d sem senha guardada\n",
    count($creds), count($creds) - count($incoerentes) - count($semSenha), count($incoerentes), count($semSenha));
foreach ($incoerentes as $c) {
    printf("  dispositivo %d (%s): senha exibida não confere com o hash\n", $c['device_id'], $c['mqtt_username']);
}
foreach ($semSenha as $c) {
    printf("  dispositivo %d (%s): sem senha guardada — use \"Regenerar senha\" na página do dispositivo\n", $c['device_id'], $c['mqtt_username']);
}

if (!$incoerentes && !($regenerar && $semSenha)) {
    exit(0);
}
if (!$aplicar) {
    echo "\nNada foi alterado. Rode com --aplicar para corrigir.\n";
    exit(0);
}

$upd = $conn->prepare("UPDATE mqtt_credentials SET mqtt_password_hash = ?, updated_at = NOW() WHERE id = ?");
foreach ($incoerentes as $c) {
    $upd->execute([mqtt_hash_senha($c['mqtt_password']), $c['id']]);
}
$regeneradas = 0;
if ($regenerar) {
    $nova = $conn->prepare("UPDATE mqtt_credentials SET mqtt_password = ?, mqtt_password_hash = ?, updated_at = NOW() WHERE id = ?");
    foreach ($semSenha as $c) {
        $senha = bin2hex(random_bytes(12)); // 24 caracteres, como no cadastro
        $nova->execute([$senha, mqtt_hash_senha($senha), $c['id']]);
        printf("  dispositivo %d: senha nova gerada (veja na página do dispositivo)\n", $c['device_id']);
        $regeneradas++;
    }
}
$sync = (new MosquittoSync($conn, true))->sync();
printf("\n%d credenciais corrigidas, %d senhas novas. Broker: %s\n", count($incoerentes), $regeneradas,
    !empty($sync['success']) ? 'arquivo de senhas atualizado (recarrega sozinho)' : 'falha ao sincronizar — veja os logs');
