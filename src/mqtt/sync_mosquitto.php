#!/usr/bin/env php
<?php
/**
 * sync_mosquitto.php
 * Sincroniza credenciais MQTT do banco com arquivos do Mosquitto
 * 
 * Script standalone que usa MosquittoSync class
 * Útil para sincronização manual ou via cron
 * 
 * Uso: php src/mqtt/sync_mosquitto.php
 * Com sudo: sudo php src/mqtt/sync_mosquitto.php
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('CONFIG_DIR', ROOT_DIR . '/src/config');

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';
require_once ROOT_DIR . '/src/core/MosquittoSync.php';

echo "=== Sincronização MQTT → Mosquitto ===\n\n";

// Usa a classe MosquittoSync
$sync = new MosquittoSync($conn, false); // false = mostra mensagens
$result = $sync->sync();

if ($result['success']) {
    echo "\n✅ Sincronização concluída!\n";
    echo "📊 Dispositivos sincronizados: " . $result['devices_synced'] . "\n";
    exit(0);
} else {
    echo "\n❌ Erro: " . $result['message'] . "\n";
    exit(1);
}
?>
