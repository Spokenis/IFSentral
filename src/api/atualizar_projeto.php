<?php
/**
 * API: Atualizar Informações Gerais do Projeto (nome, descrição e tags)
 *
 * POST /api/atualizar-projeto
 * {
 *   "project_id": 1,
 *   "name": "Estação Meteorológica",
 *   "description": "Sensores do bloco B",
 *   "tags": ["temperatura", "umidade"]
 * }
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/AuthMiddleware.php';
setupSecureCORS();
require_once __DIR__ . '/../core/ApiError.php';
require_once __DIR__ . '/../core/Csrf.php';

use App\Core\AuthMiddleware;
use App\Core\Csrf;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

Csrf::requireValidToken();

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['project_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id é obrigatório']);
        exit;
    }

    $project_id = intval($input['project_id']);
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $tags = is_array($input['tags'] ?? null) ? $input['tags'] : [];
    $user_id = AuthMiddleware::requireAuth();

    // Limites das colunas: projects.name varchar(30), projects.description varchar(200), tags.name varchar(50)
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'O nome do projeto é obrigatório.']);
        exit;
    }
    if (mb_strlen($name) > 30) {
        http_response_code(400);
        echo json_encode(['error' => 'O nome do projeto deve ter no máximo 30 caracteres.']);
        exit;
    }
    if (mb_strlen($description) > 200) {
        http_response_code(400);
        echo json_encode(['error' => 'A descrição deve ter no máximo 200 caracteres.']);
        exit;
    }

    $tag_names = [];
    foreach ($tags as $tag) {
        $tag = trim((string)$tag);
        if ($tag === '') {
            continue;
        }
        if (mb_strlen($tag) > 50) {
            http_response_code(400);
            echo json_encode(['error' => 'Cada tag deve ter no máximo 50 caracteres.']);
            exit;
        }
        $tag_names[mb_strtolower($tag)] ??= $tag; // remove repetidas, mantém a primeira
    }

    // Verificar se o projeto existe
    $projectSql = "SELECT id FROM projects WHERE id = ? AND deletedAt IS NULL";
    $projectStmt = $conn->prepare($projectSql);
    $projectStmt->execute([$project_id]);

    if (!$projectStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['error' => 'Projeto não encontrado']);
        exit;
    }

    if (!AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas o gerente do projeto pode alterar as informações do projeto']);
        exit;
    }

    $conn->beginTransaction();

    $updateSql = "UPDATE projects SET name = ?, description = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$name, $description !== '' ? $description : null, $project_id]);

    // Substitui as tags do projeto (cria as que ainda não existem, como em criar_projeto.php)
    $conn->prepare("DELETE FROM project_tags WHERE project_id = ?")->execute([$project_id]);

    $stmt_find_tag = $conn->prepare("SELECT id, name FROM tags WHERE name = ?");
    $stmt_insert_tag = $conn->prepare("INSERT INTO tags (name) VALUES (?)");
    $stmt_project_tag = $conn->prepare("INSERT IGNORE INTO project_tags (project_id, tag_id) VALUES (?, ?)");

    $saved_tags = [];
    foreach ($tag_names as $tag) {
        $stmt_find_tag->execute([$tag]);
        $existing = $stmt_find_tag->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $tag_id = $existing['id'];
            $saved_tags[] = $existing['name']; // a busca ignora maiúsculas: usa o nome já cadastrado
        } else {
            $stmt_insert_tag->execute([$tag]);
            $tag_id = $conn->lastInsertId();
            $saved_tags[] = $tag;
        }
        $stmt_project_tag->execute([$project_id, $tag_id]);
    }

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Projeto atualizado',
        'name' => $name,
        'description' => $description,
        'project_tags' => implode(',', $saved_tags)
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    api_error_response('Erro ao atualizar projeto', $e, 500);
}
?>
