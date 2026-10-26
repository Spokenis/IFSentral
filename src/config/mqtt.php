<?php
/**
 * mqtt.php - Configuração MQTT
 * Define as variáveis de conexão e tópicos MQTT
 */

// Configurações do broker MQTT
define('MQTT_HOST', getenv('MQTT_HOST') ?: ($_ENV['MQTT_HOST'] ?? 'localhost'));
define('MQTT_PORT', getenv('MQTT_PORT') ?: ($_ENV['MQTT_PORT'] ?? 1883));
define('MQTT_USERNAME', getenv('MQTT_USERNAME') ?: ($_ENV['MQTT_USERNAME'] ?? null));
define('MQTT_PASSWORD', getenv('MQTT_PASSWORD') ?: ($_ENV['MQTT_PASSWORD'] ?? null));
// ID estável por instância do worker (hostname do contêiner): ao reiniciar, o
// worker retoma a mesma sessão persistente no broker. Um ID novo a cada início
// deixava sessões órfãs inscritas no grupo compartilhado ($share/...), e o
// broker repartia as mensagens entre elas — que nunca mais as liam.
define('MQTT_CLIENT_ID', 'ifsentral-worker-' . gethostname());

// Tópicos MQTT (padrão)
// Estrutura: mqtt/projects/{project_id}/devices/{device_id}
define('MQTT_TOPIC_PATTERN', 'mqtt/projects/+/devices/+');

// Lista de tópicos que o subscriber irá escutar
// Pode ser customizado por projeto se necessário
define('MQTT_TOPICS', [
    'mqtt/projects/+/devices/+'  // Todos os projetos e dispositivos
]);

// Configurações de reconexão
define('MQTT_KEEP_ALIVE', 60);
define('MQTT_RECONNECT_DELAY', 5);  // segundos
define('MQTT_MAX_RECONNECT_ATTEMPTS', 10);

?>
