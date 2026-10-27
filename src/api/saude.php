<?php
/**
 * API: Saúde do contêiner web
 *
 * GET /api/saude -> 200 {"ok": true} se o PHP carrega a configuração e alcança
 * o banco; 503 caso contrário. Usado pelo healthcheck do docker-compose.yml e
 * por manutencao/atualizar.sh para decidir se uma versão nova pode ficar no ar.
 * Não expõe detalhes do erro.
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $conn = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );
    $conn->query('SELECT 1');
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    error_log('api/saude: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
?>
