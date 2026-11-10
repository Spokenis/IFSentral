-- Documentação editável pelo administrador (página /documentacao)
--
-- O conteúdo inicial não vem aqui: na primeira abertura da página, as seções
-- de src/docs/documentacao-padrao.html são copiadas para a tabela.
-- Instalações novas não precisam: src/db/ifsentral_bd.sql já tem estas tabelas.

CREATE TABLE IF NOT EXISTS `documentation_sections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) NOT NULL COMMENT 'Âncora da seção na página (/documentacao#slug)',
  `title` varchar(150) NOT NULL,
  `icon` varchar(60) NOT NULL DEFAULT '' COMMENT 'Classes do Font Awesome, ex.: fas fa-key',
  `color` enum('primary','secondary','success','info','warning','danger') NOT NULL DEFAULT 'primary',
  `outline` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = só a borda superior colorida',
  `content` mediumtext NOT NULL COMMENT 'HTML já sanitizado',
  `position` int(11) NOT NULL DEFAULT 0,
  `visible` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_documentation_slug` (`slug`),
  KEY `idx_documentation_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `documentation_revisions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `section_id` int(10) unsigned NOT NULL,
  `title` varchar(150) NOT NULL,
  `content` mediumtext NOT NULL COMMENT 'Versão anterior à edição',
  `edited_by` int(10) unsigned DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_documentation_revisions_section` (`section_id`,`id`),
  CONSTRAINT `documentation_revisions_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `documentation_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
