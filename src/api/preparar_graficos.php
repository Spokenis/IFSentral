<?php
/**
 * api/preparar_graficos.php - Cópia dos dados antigos para as tabelas dos gráficos
 *
 * GET  -> situação: {tabelas, pronto, cursor, ate}
 * POST -> cria as tabelas, se faltarem, e copia lotes por até ~5 s
 *
 * Só para Admin (POST exige CSRF). Na edição completa a cópia já roda sozinha
 * quando o contêiner web inicia; no Lite, onde não há linha de comando, o
 * cartão "Desempenho dos gráficos" do painel de administração chama este
 * endpoint até terminar. Ver src/core/Agregados.php.
 */

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';
require_once '../core/Csrf.php';
require_once '../core/Agregados.php';

use App\Core\Agregados;
use App\Core\Csrf;

header('Content-Type: application/json; charset=UTF-8');

require '../config/db.php';
require '../auth/auth_check.php';

$stmt = $conn->prepare("SELECT profile FROM users WHERE id = ? AND deletedAt IS NULL");
$stmt->execute([$_SESSION['user_id'] ?? 0]);
if ($stmt->fetchColumn() !== 'Admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas administradores.']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::requireValidToken();
        if (!Agregados::tabelasExistem($conn) && !Agregados::criarTabelas($conn)) {
            http_response_code(503);
            echo json_encode(['error' => 'Não foi possível criar as tabelas (sem permissão no banco?). Importe src/db/migracoes/2026-11-08b_agregados_graficos.sql pelo phpMyAdmin.']);
            exit;
        }
        $processadas = 0;
        $fim = microtime(true) + 5;
        do {
            $r = Agregados::preencherLote($conn, 2000);
            $processadas += $r['processadas'];
        } while (!$r['pronto'] && empty($r['ocupado']) && microtime(true) < $fim);
        echo json_encode(Agregados::situacao($conn) + ['processadas' => $processadas, 'ocupado' => !empty($r['ocupado'])]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido.']);
        exit;
    }
    echo json_encode(Agregados::situacao($conn));
} catch (PDOException $e) {
    api_error_response('Erro ao preparar os gráficos', $e, 500);
}
