-- Tabelas de valores já extraídos para os gráficos (src/core/Agregados.php)
--
-- Os gráficos liam cada variável de dentro do JSON de device_payloads; com
-- meses de dados isso saturava a CPU do MySQL. As leituras novas passam a
-- gravar aqui também. Os dados antigos são copiados depois por
-- src/db/preencher-agregados.php (chamado no fim do manutencao/atualizar.sh)
-- ou pelo botão em Administração; até terminar, os gráficos usam o caminho
-- antigo (AGREGADOS_PRONTOS = 0).

CREATE TABLE IF NOT EXISTS `device_payload_values` (
  `device_id` int(10) unsigned NOT NULL,
  `variavel` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL,
  `payload_id` bigint(20) unsigned NOT NULL,
  `valor` double NOT NULL,
  PRIMARY KEY (`device_id`,`variavel`,`created_at`,`payload_id`),
  KEY `idx_values_payload` (`payload_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Uma linha por variável numérica de cada leitura (gráficos de até 7 dias)';

CREATE TABLE IF NOT EXISTS `device_payload_hourly` (
  `device_id` int(10) unsigned NOT NULL,
  `variavel` varchar(100) NOT NULL,
  `hora` int(10) unsigned NOT NULL COMMENT 'FLOOR(UNIX_TIMESTAMP(created_at) / 3600)',
  `soma` double NOT NULL,
  `qtd` int(10) unsigned NOT NULL,
  `minimo` double NOT NULL,
  `maximo` double NOT NULL,
  PRIMARY KEY (`device_id`,`variavel`,`hora`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Soma e quantidade por hora (gráficos de mais de 7 dias)';

INSERT IGNORE INTO `api_settings` (`setting_key`, `setting_value`, `description`, `is_editable`)
VALUES ('AGREGADOS_PRONTOS', '0', 'Gráficos: cópia dos dados para as tabelas de agregados (automático)', 0);
