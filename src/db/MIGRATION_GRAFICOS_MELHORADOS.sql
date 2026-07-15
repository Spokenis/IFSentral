-- =====================================================
-- MIGRATION: Melhorias no Sistema de Gráficos
-- Adiciona suporte a múltiplos dispositivos/variáveis
-- =====================================================

-- ✅ Modificar tabela 'charts' para adicionar novos campos
ALTER TABLE `charts` 
ADD COLUMN `date_start` DATETIME DEFAULT NULL,
ADD COLUMN `date_end` DATETIME DEFAULT NULL,
ADD COLUMN `time_range` VARCHAR(50) DEFAULT 'all' COMMENT 'all, 24h, 7d, 30d, custom',
ADD COLUMN `x_axis_var` VARCHAR(100) DEFAULT NULL COMMENT 'Nome da variável no eixo X',
ADD COLUMN `y_axis_vars` JSON DEFAULT NULL COMMENT 'Array de variáveis do eixo Y',
ADD COLUMN `description` TEXT DEFAULT NULL,
ADD COLUMN `is_multi_device` TINYINT(1) DEFAULT 0,
ADD COLUMN `config` JSON DEFAULT NULL COMMENT 'Configurações adicionais (cores, estilos, etc)',
ADD COLUMN `createdAt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN `updatedAt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ✅ Criar tabela para gerenciar múltiplos dispositivos por gráfico
CREATE TABLE IF NOT EXISTS `chart_datasets` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `chart_id` INT(10) UNSIGNED NOT NULL,
  `device_id` INT(10) UNSIGNED NOT NULL,
  `variable_name` VARCHAR(100) NOT NULL,
  `alias` VARCHAR(100) DEFAULT NULL COMMENT 'Nome exibido no gráfico',
  `color` VARCHAR(7) DEFAULT NULL,
  `line_style` ENUM('solid', 'dashed', 'dotted') DEFAULT 'solid',
  `axis` ENUM('x', 'y') DEFAULT 'y',
  `sort_order` INT(10) UNSIGNED DEFAULT 0,
  FOREIGN KEY (`chart_id`) REFERENCES `charts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ✅ Criar índices para melhor performance
ALTER TABLE `chart_datasets` 
ADD INDEX `idx_chart_id` (`chart_id`),
ADD INDEX `idx_device_id` (`device_id`);

ALTER TABLE `charts` 
ADD INDEX `idx_project_id` (`project_id`),
ADD INDEX `idx_is_multi_device` (`is_multi_device`);
