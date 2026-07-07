<?php
// src/api/atualizar_grafico_avancado.php
// Atualiza configuração de gráfico com múltiplos datasets

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use PUT.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->chart_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'chart_id é obrigatório.']);
    exit;
}

$chart_id = intval($data->chart_id);

// Obter user_id da sessão ou do banco de dados basado no email
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id && isset($_SESSION['email'])) {
    try {
        $sql_user = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->execute([$_SESSION['email']]);
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $user_id = $user_data['id'];
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Erro ao validar usuário']);
        exit;
    }
}

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

try {
    // Validar acesso ao gráfico
    $authSql = "
        SELECT c.project_id FROM charts c
        JOIN users_projects up ON c.project_id = up.project_id
        WHERE c.id = ? AND up.user_id = ?
    ";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$chart_id, $user_id]);
    
    if ($authStmt->rowCount() == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada.']);
        exit;
    }
    
    $conn->beginTransaction();
    
    // Atualizar campos do gráfico
    $update_sql = "UPDATE charts SET ";
    $updates = [];
    $params = [];
    
    if (isset($data->name)) {
        $updates[] = "name = ?";
        $params[] = $data->name;
    }
    if (isset($data->chart_type)) {
        $updates[] = "chart_type = ?";
        $params[] = $data->chart_type;
    }
    if (isset($data->time_range)) {
        $updates[] = "time_range = ?";
        $params[] = $data->time_range;
    }
    if (isset($data->date_start)) {
        $updates[] = "date_start = ?";
        $params[] = $data->date_start;
    }
    if (isset($data->date_end)) {
        $updates[] = "date_end = ?";
        $params[] = $data->date_end;
    }
    if (isset($data->description)) {
        $updates[] = "description = ?";
        $params[] = $data->description;
    }
    if (isset($data->config)) {
        $updates[] = "config = ?";
        $params[] = json_encode($data->config);
    }
    
    if (!empty($updates)) {
        $update_sql .= implode(", ", $updates);
        $update_sql .= " WHERE id = ?";
        $params[] = $chart_id;
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->execute($params);
    }
    
    // Se houver datasets, atualizar
    if (isset($data->datasets) && is_array($data->datasets)) {
        // Deletar datasets antigos
        $delete_sql = "DELETE FROM chart_datasets WHERE chart_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->execute([$chart_id]);
        
        // Inserir novos datasets
        $dataset_sql = "INSERT INTO chart_datasets (
                            chart_id,
                            device_id,
                            variable_name,
                            alias,
                            color,
                            line_style,
                            axis,
                            sort_order
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $dataset_stmt = $conn->prepare($dataset_sql);
        
        foreach ($data->datasets as $index => $dataset) {
            $dataset_stmt->execute([
                $chart_id,
                $dataset->device_id,
                $dataset->variable_name,
                $dataset->alias ?? $dataset->variable_name,
                $dataset->color ?? null,
                $dataset->line_style ?? 'solid',
                $dataset->axis ?? 'y',
                $index
            ]);
        }
    }
    
    $conn->commit();
    
    http_response_code(200);
    echo json_encode(['message' => 'Gráfico atualizado com sucesso!']);

} catch (PDOException $e) {
    $conn->rollBack();
    http_response_code(500);
    if (APP_ENV === 'production') {
        echo json_encode(['error' => 'Erro ao atualizar gráfico.']);
    } else {
        echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
    }
}
?>
