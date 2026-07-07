<?php
/**
 * AuthMiddleware.php - Middleware de autorização centralizado
 * Garante validação consistente de permissões em toda a API
 */

namespace App\Core;

class AuthMiddleware
{
    private $conn;
    private $user_id;
    private $project_id;

    public function __construct($pdo_connection)
    {
        $this->conn = $pdo_connection;
    }

    /**
     * Valida se usuário está autenticado
     * Retorna user_id ou lança exceção
     */
    public static function requireAuth()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Não autenticado. Faça login primeiro.']);
            exit;
        }

        return intval($_SESSION['user_id']);
    }

    /**
     * Valida se API Key é válida
     * Retorna device_id ou false
     */
    public static function validateApiKey($pdo_connection)
    {
        $headers = getallheaders();
        $api_key = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? null;

        if (!$api_key || strlen($api_key) < 32) {
            return false;
        }

        try {
            $sql = "SELECT id, project_id, api_key FROM devices WHERE api_key = ? AND deletedAt IS NULL LIMIT 1";
            $stmt = $pdo_connection->prepare($sql);
            $stmt->execute([$api_key]);

            if ($stmt->rowCount() === 0) {
                return false;
            }

            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Valida se usuário tem acesso a um projeto
     */
    public static function hasProjectAccess($pdo_connection, $user_id, $project_id)
    {
        try {
            $sql = "SELECT 1
                FROM users_projects up
                JOIN projects p ON p.id = up.project_id
                WHERE up.user_id = ? AND up.project_id = ? AND p.deletedAt IS NULL
                LIMIT 1";
            $stmt = $pdo_connection->prepare($sql);
            $stmt->execute([$user_id, $project_id]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Valida se usuário é owner do projeto
     */
    public static function isProjectOwner($pdo_connection, $user_id, $project_id)
    {
        try {
            $sql = "SELECT 1
                FROM users_projects up
                JOIN roles r ON r.id = up.role_id
                JOIN projects p ON p.id = up.project_id
                WHERE up.project_id = ? AND up.user_id = ?
                  AND r.name = 'Gerente'
                  AND p.deletedAt IS NULL
                LIMIT 1";
            $stmt = $pdo_connection->prepare($sql);
            $stmt->execute([$project_id, $user_id]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Valida se usuário é owner ou manager do projeto
     */
    public static function isProjectManager($pdo_connection, $user_id, $project_id)
    {
        try {
            $sql = "SELECT 1
                FROM users_projects up
                JOIN roles r ON r.id = up.role_id
                JOIN projects p ON p.id = up.project_id
                WHERE up.user_id = ? AND up.project_id = ?
                  AND r.name = 'Gerente'
                  AND p.deletedAt IS NULL
                LIMIT 1";
            $stmt = $pdo_connection->prepare($sql);
            $stmt->execute([$user_id, $project_id]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Valida se usuário tem acesso a um dispositivo
     */
    public static function hasDeviceAccess($pdo_connection, $user_id, $device_id)
    {
        try {
            // Usuário tem acesso ao dispositivo se tem acesso ao projeto do dispositivo
            $sql = "SELECT 1 FROM devices d
                    JOIN users_projects up ON d.project_id = up.project_id
                    WHERE d.id = ? AND up.user_id = ? AND d.deletedAt IS NULL LIMIT 1";
            $stmt = $pdo_connection->prepare($sql);
            $stmt->execute([$device_id, $user_id]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Valida se usuário tem acesso a um gráfico
     */
    public static function hasChartAccess($pdo_connection, $user_id, $chart_id)
    {
        try {
            $sql = "SELECT 1 FROM charts c
                    JOIN users_projects up ON c.project_id = up.project_id
                    JOIN projects p ON p.id = c.project_id
                    WHERE c.id = ? AND up.user_id = ? AND p.deletedAt IS NULL LIMIT 1";
            $stmt = $pdo_connection->prepare($sql);
            $stmt->execute([$chart_id, $user_id]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Middleware que protege endpoints públicos contra acesso não autorizado
     */
    public static function requireAccess($pdo_connection, $user_id, $resource_type, $resource_id, $min_role = 'member')
    {
        switch ($resource_type) {
            case 'project':
                if ($min_role === 'manager' || $min_role === 'owner') {
                    $hasAccess = self::isProjectManager($pdo_connection, $user_id, $resource_id);
                } else {
                    $hasAccess = self::hasProjectAccess($pdo_connection, $user_id, $resource_id);
                }

                if (!$hasAccess) {
                    throw new \Exception('Acesso ao projeto negado', 403);
                }
                break;

            case 'device':
                if (!self::hasDeviceAccess($pdo_connection, $user_id, $resource_id)) {
                    throw new \Exception('Acesso ao dispositivo negado', 403);
                }
                break;

            case 'chart':
                if (!self::hasChartAccess($pdo_connection, $user_id, $resource_id)) {
                    throw new \Exception('Acesso ao gráfico negado', 403);
                }
                break;

            default:
                throw new \Exception('Tipo de recurso inválido', 400);
        }
    }
}
?>
