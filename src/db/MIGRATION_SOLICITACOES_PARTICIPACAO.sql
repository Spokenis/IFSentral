-- Migration: Adicionar tabela de solicitações de participação em projetos
-- Data: 19/02/2026

-- Criar tabela de solicitações
CREATE TABLE IF NOT EXISTS `project_join_requests` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `status` enum('pendente', 'aceito', 'rejeitado') NOT NULL DEFAULT 'pendente',
  `message` text,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `respondedAt` timestamp NULL DEFAULT NULL,
  
  UNIQUE KEY `unique_request` (`project_id`, `user_id`, `status`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  
  KEY `idx_status` (`status`),
  KEY `idx_project` (`project_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Índice para melhorar performance ao buscar solicitações pendentes
CREATE INDEX IF NOT EXISTS `idx_project_status` ON `project_join_requests` (`project_id`, `status`);
