<?php
// src/api/salvar_grafico_avancado.php
// Novo endpoint para criar gráficos com múltiplos dispositivos e variáveis

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

// Validação
if (
    !isset($data->project_id) ||
    !isset($data->name) ||
    !isset($data->chart_type) ||
    !isset($data->datasets) || !is_array($data->datasets) || count($data->datasets) === 0
) {
    http_response_code(400);
    echo json_encode(['error' => 'Campos obrigatórios: project_id, name, chart_type, datasets (array).']);
    exit;
}

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
    // Inicia transação para consistência
    $conn->beginTransaction();
    
    // Validar se usuário tem acesso ao projeto
    $authSql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ?";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$data->project_id, $user_id]);
    if ($authStmt->rowCount() == 0) {
        $conn->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada.']);
        exit;
    }
    
    // Preparar dados do gráfico
    $chart_config = [
        'theme' => $data->theme ?? 'light',
        'show_legend' => $data->show_legend ?? true,
        'show_grid' => $data->show_grid ?? true
    ];
    
    // Inserir gráfico principal
    $sql = "INSERT INTO charts (
                project_id, 
                name, 
                chart_type, 
                device_id, 
                json_key,
                time_range,
                date_start,
                date_end,
                x_axis_var,
                y_axis_vars,
                is_multi_device,
                description,
                config,
                is_public
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    
    // Para compatibilidade com campos antigos
    $first_device = $data->datasets[0];
    $x_axis = isset($data->x_axis) ? $data->x_axis : null;
    $y_axis_vars = [];
    
    // Coletar todas as variáveis Y
    foreach ($data->datasets as $ds) {
        if ($ds->axis === 'y') {
            $y_axis_vars[] = $ds->variable_name;
        }
    }
    
    $stmt->execute([
        $data->project_id,
        $data->name,
        $data->chart_type,
        $first_device->device_id, // Compatibilidade
        $first_device->variable_name, // Compatibilidade
        $data->time_range ?? 'all',
        $data->date_start ?? null,
        $data->date_end ?? null,
        $x_axis,
        json_encode($y_axis_vars),
        count($data->datasets) > 1 ? 1 : 0,
        $data->description ?? null,
        json_encode($chart_config),
        isset($data->is_public) ? (int)$data->is_public : 0
    ]);
    
    $chart_id = $conn->lastInsertId();
    
    // Inserir datasets (múltiplos dispositivos/variáveis)
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
    
    $conn->commit();
    
    http_response_code(201);
    echo json_encode([
        'message' => 'Gráfico salvo com sucesso!',
        'chart_id' => $chart_id,
        'datasets_count' => count($data->datasets)
    ]);

} catch (PDOException $e) {
    $conn->rollBack();
    http_response_code(500);
    if (APP_ENV === 'production') {
        echo json_encode(['error' => 'Erro ao salvar gráfico.']);
    } else {
        echo json_encode(['error' => 'Erro ao salvar: ' . $e->getMessage()]);
    }
}
?>
