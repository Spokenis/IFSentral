-- Integração com o The Things Network (TTN)
--
-- Para bancos instalados antes de 26/10/2026. Importe UMA vez, pelo
-- phpMyAdmin (Importar) ou com:  mysql -u USUARIO -p ifsentral_bd < este_arquivo.sql
-- Instalações novas não precisam: src/db/ifsentral_bd.sql já tem estas mudanças.

ALTER TABLE `devices`
  ADD COLUMN `ttn_device_id` varchar(36) DEFAULT NULL COMMENT 'ID do end device no The Things Network' AFTER `api_key`,
  ADD KEY `idx_devices_ttn` (`project_id`,`ttn_device_id`);

ALTER TABLE `device_payloads`
  ADD COLUMN `external_id` varchar(100) DEFAULT NULL COMMENT 'Identificador do dado na origem (evita gravar o mesmo uplink duas vezes)' AFTER `source`,
  ADD UNIQUE KEY `uq_payload_external` (`device_id`,`external_id`);

CREATE TABLE IF NOT EXISTS `ttn_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `cluster` varchar(64) NOT NULL COMMENT 'Ex.: eu1.cloud.thethings.network',
  `application_id` varchar(36) NOT NULL,
  `api_key` varchar(255) NOT NULL COMMENT 'Chave do TTN com direito de ler o tráfego da aplicação',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_received_at` varchar(40) DEFAULT NULL COMMENT 'received_at (RFC 3339) do último uplink lido do Storage do TTN',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ttn_project` (`project_id`),
  CONSTRAINT `ttn_applications_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
