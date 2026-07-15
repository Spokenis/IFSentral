<?php
/**
 * SchemaValidator.php - Valida se o esquema do banco está correto
 * Garante que todas as tabelas necessárias existem
 */

namespace App\Core;

class SchemaValidator
{
    private $conn;
    private $errors = [];
    private $warnings = [];

    public function __construct($pdo_connection)
    {
        $this->conn = $pdo_connection;
    }

    /**
     * Valida schema completo do banco
     * Retorna: ['valid' => bool, 'errors' => [], 'warnings' => []]
     */
    public function validateSchema()
    {
        $this->errors = [];
        $this->warnings = [];

        // Tabelas essenciais
        $essential_tables = [
            'users',
            'projects',
            'devices',
            'device_payloads',
            'users_projects',
        ];

        // Tabelas de segurança
        $security_tables = [
            'api_settings',
            'device_rate_limits',
            'rate_limit_violations',
            'mqtt_credentials',
        ];

        // Verificar tabelas essenciais
        foreach ($essential_tables as $table) {
            if (!$this->tableExists($table)) {
                $this->errors[] = "Tabela essencial ausente: `$table`";
            }
        }

        // Verificar tabelas de segurança (com fallback)
        foreach ($security_tables as $table) {
            if (!$this->tableExists($table)) {
                $this->warnings[] = "Tabela de segurança ausente: `$table` - Rate limiting e segurança podem não funcionar corretamente";
            }
        }

        // Verificar colunas críticas
        $this->validateColumns();

        return [
            'valid' => count($this->errors) === 0,
            'errors' => $this->errors,
            'warnings' => $this->warnings
        ];
    }

    /**
     * Verifica se tabela existe
     */
    private function tableExists($table_name)
    {
        try {
            $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$table_name]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Valida colunas críticas em tabelas
     */
    private function validateColumns()
    {
        $critical_columns = [
            'users' => ['id', 'email', 'password_hash'],
            'devices' => ['id', 'api_key', 'project_id'],
            'device_payloads' => ['id', 'device_id', 'payload', 'created_at'],
            'api_settings' => ['id', 'setting_key', 'setting_value'],
        ];

        foreach ($critical_columns as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue; // Já foi reportado como erro
            }

            foreach ($columns as $column) {
                if (!$this->columnExists($table, $column)) {
                    $this->errors[] = "Coluna crítica ausente: `$table`.`$column`";
                }
            }
        }
    }

    /**
     * Verifica se coluna existe em uma tabela
     */
    private function columnExists($table_name, $column_name)
    {
        try {
            $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = ? AND COLUMN_NAME = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$table_name, $column_name]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Retorna se há erros
     */
    public function hasErrors()
    {
        return count($this->errors) > 0;
    }

    /**
     * Retorna se há warnings
     */
    public function hasWarnings()
    {
        return count($this->warnings) > 0;
    }

    /**
     * Retorna todos os erros
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Retorna todos os warnings
     */
    public function getWarnings()
    {
        return $this->warnings;
    }

    /**
     * Retorna relatório formatado
     */
    public function getFormattedReport()
    {
        $report = [];

        if (count($this->errors) > 0) {
            $report[] = "❌ ERROS CRÍTICOS:";
            foreach ($this->errors as $error) {
                $report[] = "  - $error";
            }
        }

        if (count($this->warnings) > 0) {
            $report[] = "⚠️  AVISOS:";
            foreach ($this->warnings as $warning) {
                $report[] = "  - $warning";
            }
        }

        if (empty($report)) {
            $report[] = "✅ Schema validado com sucesso!";
        }

        return implode(PHP_EOL, $report);
    }
}
?>
