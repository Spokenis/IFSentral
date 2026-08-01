-- Migration: Email Verification System
-- Criado em: $(date +%Y-%m-%d)
-- Descrição: Adiciona suporte a verificação de email (tabela de tokens + coluna is_verified)

-- 1. Criar tabela para armazenar tokens de verificação de email
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `email_to_verify` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `type` enum('REGISTER','RECOVER','CHANGE_EMAIL') NOT NULL DEFAULT 'REGISTER',
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_used` (`used`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Adicionar coluna is_verified na tabela users (se não existir)
SET @dbname = DATABASE();
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = @dbname 
               AND TABLE_NAME = 'users' 
               AND COLUMN_NAME = 'is_verified');

SET @sql = IF(@exists = 0, 
    'ALTER TABLE users ADD COLUMN `is_verified` tinyint(1) NOT NULL DEFAULT 0 AFTER `profile_picture`',
    'SELECT "Column is_verified already exists" AS status');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

