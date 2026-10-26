<?php
// criar_projeto.php (Atualizado com Tags)



header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php'; // $username_logado e $_SESSION['user_id'] estão disponíveis
require '../core/Csrf.php';
require_once '../core/ApiError.php';

use App\Core\Csrf;

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

Csrf::requireValidToken();

$data = json_decode(file_get_contents("php://input"));

if (isset($data->name) && is_string($data->name)) {
    $data->name = trim($data->name);
}

if (!isset($data->name) || empty($data->name)) {
    http_response_code(400);
    echo json_encode(['error' => 'Campo "name" (nome do projeto) é obrigatório.']);
    exit;
}

// Limites das colunas da tabela `projects`
if (!is_string($data->name) || mb_strlen($data->name) > 30) {
    http_response_code(400);
    echo json_encode(['error' => 'O nome do projeto deve ter no máximo 30 caracteres.']);
    exit;
}

if (isset($data->description) && (!is_string($data->description) || mb_strlen($data->description) > 200)) {
    http_response_code(400);
    echo json_encode(['error' => 'A descrição deve ter no máximo 200 caracteres.']);
    exit;
}

if (isset($data->maxUsers) && (filter_var($data->maxUsers, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]) === false)) {
    http_response_code(400);
    echo json_encode(['error' => 'Valor inválido para "Máximo de Usuários".']);
    exit;
}

$gerente_user_id = $_SESSION['user_id'];
$id_funcao_gerente = 1; // Assumindo que 1 = Gerente

// Validar se o usuário logado pode ser gerente (precisa ser Moderator ou Admin)
try {
    $checkProfileSql = "SELECT profile FROM users WHERE id = ? AND deletedAt IS NULL";
    $checkProfileStmt = $conn->prepare($checkProfileSql);
    $checkProfileStmt->execute([$gerente_user_id]);
    $userProfile = $checkProfileStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userProfile || ($userProfile['profile'] !== 'Moderator' && $userProfile['profile'] !== 'Admin')) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas Moderadores podem criar projetos como Gerente.']);
        exit;
    }
} catch (PDOException $e) {
    api_error_response('Erro ao validar permissões', $e, 500);
}

$description = isset($data->description) ? $data->description : null;
$public = isset($data->public) ? intval($data->public) : 0; // Converter para inteiro (0 ou 1)
$maxUsers = isset($data->maxUsers) ? intval($data->maxUsers) : null;
$invitation_code = bin2hex(random_bytes(6));
// *** NOVO: Pega o array de tags (pode estar vazio) ***
$tags = isset($data->tags) ? (array)$data->tags : [];

// tags.name é varchar(50)
foreach ($tags as $tag_input) {
    if (is_scalar($tag_input) && mb_strlen(trim((string)$tag_input)) > 50) {
        http_response_code(400);
        echo json_encode(['error' => 'Cada tag deve ter no máximo 50 caracteres.']);
        exit;
    }
}


// 3. USA UMA TRANSAÇÃO
try {
    $conn->beginTransaction();

    // Passo 1: Inserir na tabela 'projects'
    $sql_project = "INSERT INTO projects (name, description, public, maxUsers, invitation) 
                    VALUES (?, ?, ?, ?, ?)";
    $stmt_project = $conn->prepare($sql_project);
    $stmt_project->execute([
        $data->name,
        $description,
        $public,
        $maxUsers,
        $invitation_code
    ]);
    $new_project_id = $conn->lastInsertId();

    // Passo 2: Inserir o gerente na tabela 'users_projects'
    $sql_user_project = "INSERT INTO users_projects (project_id, user_id, role_id) 
                         VALUES (?, ?, ?)";
    $stmt_user_project = $conn->prepare($sql_user_project);
    $stmt_user_project->execute([
        $new_project_id,
        $gerente_user_id,
        $id_funcao_gerente
    ]);

    // *** NOVO: Passo 3: Processar e Inserir as Tags ***
    if (!empty($tags)) {
        $tag_ids = [];
        
        // Preparar statements para reutilização
        $stmt_find_tag = $conn->prepare("SELECT id FROM tags WHERE name = ?");
        $stmt_find_tag_id = $conn->prepare("SELECT id FROM tags WHERE id = ?");
        $stmt_insert_tag = $conn->prepare("INSERT INTO tags (name) VALUES (?)");
        
        foreach ($tags as $tag_input) {
            $tag_input = is_string($tag_input) ? trim($tag_input) : $tag_input;
            if ($tag_input === '' || $tag_input === null) {
                continue;
            }

            // Numérico e existente: é o ID de uma tag selecionada no Select2.
            // Senão é uma tag nova — o Select2 usa o próprio texto como ID, então
            // uma tag nova "2026" também chega como número.
            $is_existing_id = false;
            if (is_numeric($tag_input)) {
                $stmt_find_tag_id->execute([intval($tag_input)]);
                $is_existing_id = (bool)$stmt_find_tag_id->fetchColumn();
                $tag_input = $is_existing_id ? $tag_input : (string)$tag_input;
            }

            if ($is_existing_id) {
                $tag_ids[] = intval($tag_input);
            } else {
                // Se for string, é uma nova tag.
                // 1. Tenta encontrar
                $stmt_find_tag->execute([$tag_input]);
                $existing_tag = $stmt_find_tag->fetch(PDO::FETCH_ASSOC);
                
                if ($existing_tag) {
                    // Tag já existe, usa o ID dela
                    $tag_ids[] = $existing_tag['id'];
                } else {
                    // Tag é nova, insere no banco
                    $stmt_insert_tag->execute([$tag_input]);
                    $tag_ids[] = $conn->lastInsertId(); // Pega o ID da tag recém-criada
                }
            }
        }
        
        // Remove IDs duplicados, caso haja
        $tag_ids = array_unique($tag_ids);
        
        // Passo 4: Associar as tags ao projeto na nova tabela 'project_tags'
        $sql_project_tag = "INSERT INTO project_tags (project_id, tag_id) VALUES (?, ?)";
        $stmt_project_tag = $conn->prepare($sql_project_tag);
        
        foreach ($tag_ids as $tag_id) {
            $stmt_project_tag->execute([$new_project_id, $tag_id]);
        }
    }

    // Se tudo deu certo, confirma as mudanças
    $conn->commit();

    http_response_code(201);
    echo json_encode([
        'message' => 'Projeto criado, gerente e tags associados com sucesso!',
        'insertedId' => $new_project_id,
        'invitation_code' => $invitation_code
    ]);

} catch (PDOException $e) {
    // Se algo deu errado, desfaz tudo
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    api_error_response('Erro ao salvar projeto', $e, 500);
}
?>