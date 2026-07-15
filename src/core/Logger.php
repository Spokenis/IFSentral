<?php
/**
 * Logger.php - Sistema de logging estruturado
 * Suporta múltiplos níveis de severidade
 */

namespace App\Core;

class Logger
{
    const ERROR = 'ERROR';
    const WARNING = 'WARNING';
    const INFO = 'INFO';
    const DEBUG = 'DEBUG';

    private static $log_dir = null;
    private static $instance = null;

    public static function getInstance($log_directory = null)
    {
        if (self::$instance === null) {
            self::$instance = new self($log_directory);
        }
        return self::$instance;
    }

    public function __construct($log_directory = null)
    {
        if ($log_directory === null) {
            $log_directory = realpath(__DIR__ . '/../../logs');
        }

        if (!is_dir($log_directory)) {
            mkdir($log_directory, 0755, true);
        }

        self::$log_dir = $log_directory;
    }

    /**
     * Registra erro
     */
    public static function error($message, $context = [])
    {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * Registra warning
     */
    public static function warning($message, $context = [])
    {
        self::log(self::WARNING, $message, $context);
    }

    /**
     * Registra info
     */
    public static function info($message, $context = [])
    {
        self::log(self::INFO, $message, $context);
    }

    /**
     * Registra debug (apenas em desenvolvimento)
     */
    public static function debug($message, $context = [])
    {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * Log genérico
     */
    private static function log($level, $message, $context = [])
    {
        $instance = self::getInstance();

        $timestamp = date('Y-m-d H:i:s');
        $context_str = '';

        if (!empty($context)) {
            $context_str = ' | ' . json_encode($context);
        }

        $log_message = "[$timestamp] [$level] $message$context_str" . PHP_EOL;

        // Determina o arquivo de log baseado no nível
        $log_file_name = strtolower($level) . '.log';
        $log_file_path = self::$log_dir . '/' . $log_file_name;

        file_put_contents($log_file_path, $log_message, FILE_APPEND);

        // Também escreve em arquivo geral
        $general_log = self::$log_dir . '/app.log';
        file_put_contents($general_log, $log_message, FILE_APPEND);

        // Em desenvolvimento, mostra no console
        if (defined('APP_ENV') && APP_ENV === 'development') {
            echo $log_message;
        }
    }

    /**
     * Rotação de logs (manter apenas últimos N dias)
     */
    public static function rotateOldLogs($days = 30)
    {
        if (!self::$log_dir || !is_dir(self::$log_dir)) {
            return;
        }

        $cutoff_time = time() - ($days * 86400);
        $files = glob(self::$log_dir . '/*.log');

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff_time) {
                unlink($file);
            }
        }
    }
}
?>
