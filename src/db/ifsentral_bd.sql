/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: ifsentral_bd
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `api_settings`
--

DROP TABLE IF EXISTS `api_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_editable` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_settings`
--

LOCK TABLES `api_settings` WRITE;
/*!40000 ALTER TABLE `api_settings` DISABLE KEYS */;
INSERT INTO `api_settings` VALUES
(1,'RATE_LIMIT_ENABLED','1','Habilitar rate limiting (1=sim, 0=não)',1,'2026-03-02 02:55:17','2026-03-02 02:55:17'),
(2,'RATE_LIMIT_REQUESTS_PER_MINUTE','60','Máximo de requisições HTTP por minuto por dispositivo',1,'2026-03-02 02:55:17','2026-03-02 02:55:17'),
(3,'RATE_LIMIT_WINDOW_MINUTES','1','Janela de tempo para contagem (em minutos)',1,'2026-03-02 02:55:17','2026-03-02 02:55:17'),
(4,'MQTT_AUTH_ENABLED','1','Habilitar autenticação MQTT (1=sim, 0=não)',1,'2026-03-02 02:55:17','2026-03-02 02:55:17'),
(5,'MQTT_ACL_ENABLED','1','Habilitar ACLs no MQTT (cada device só publica no seu tópico)',1,'2026-03-02 02:55:17','2026-03-02 02:55:17'),
(6,'RATE_LIMIT_SOFT_LIMIT_PERCENT','80','Percentual do limite para começar a alertar (0-100)',1,'2026-03-02 02:55:17','2026-03-02 02:55:17'),
(7,'LOG_RATE_LIMIT_VIOLATIONS','1','Registrar violações de rate limit nos logs',1,'2026-03-02 02:55:17','2026-03-02 02:55:17');
/*!40000 ALTER TABLE `api_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chart_datasets`
--

DROP TABLE IF EXISTS `chart_datasets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chart_datasets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `chart_id` int(10) unsigned NOT NULL,
  `device_id` int(10) unsigned NOT NULL,
  `variable_name` varchar(100) NOT NULL,
  `alias` varchar(100) DEFAULT NULL COMMENT 'Nome exibido no gráfico',
  `color` varchar(7) DEFAULT NULL,
  `line_style` enum('solid','dashed','dotted') DEFAULT 'solid',
  `axis` enum('x','y') DEFAULT 'y',
  `sort_order` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_chart_id` (`chart_id`),
  KEY `idx_device_id` (`device_id`),
  CONSTRAINT `chart_datasets_ibfk_1` FOREIGN KEY (`chart_id`) REFERENCES `charts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chart_datasets_ibfk_2` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chart_datasets`
--

LOCK TABLES `chart_datasets` WRITE;
/*!40000 ALTER TABLE `chart_datasets` DISABLE KEYS */;
INSERT INTO `chart_datasets` VALUES
(5,7,5,'temperatura','temperatura','#1b7d3d','solid','y',0),
(8,9,9,'temperatura','temperatura','#1b7d3d','solid','y',0),
(9,10,9,'temperatura','temperatura','#57e389','solid','y',0),
(10,10,8,'umidade','umidade','#1b7d3d','solid','x',1),
(11,11,9,'temperatura','temperatura','#1b7d3d','solid','y',0);
/*!40000 ALTER TABLE `chart_datasets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `charts`
--

DROP TABLE IF EXISTS `charts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `charts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `chart_type` enum('line','bar','pie','doughnut','area','scatter') NOT NULL DEFAULT 'line',
  `device_id` int(10) unsigned NOT NULL,
  `json_key` varchar(100) NOT NULL,
  `date_start` datetime DEFAULT NULL,
  `date_end` datetime DEFAULT NULL,
  `time_range` varchar(50) DEFAULT 'all' COMMENT 'all, 24h, 7d, 30d, custom',
  `x_axis_var` varchar(100) DEFAULT NULL COMMENT 'Nome da variável no eixo X',
  `y_axis_vars` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array de variáveis do eixo Y' CHECK (json_valid(`y_axis_vars`)),
  `description` text DEFAULT NULL,
  `is_multi_device` tinyint(1) DEFAULT 0,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Configurações adicionais (cores, estilos, etc)' CHECK (json_valid(`config`)),
  `is_public` tinyint(1) DEFAULT 0,
  `createdAt` timestamp NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_is_multi_device` (`is_multi_device`),
  CONSTRAINT `charts_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `charts_ibfk_2` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `charts`
--

LOCK TABLES `charts` WRITE;
/*!40000 ALTER TABLE `charts` DISABLE KEYS */;
INSERT INTO `charts` VALUES
(1,3,'Teste','bar',3,'status',NULL,NULL,'all',NULL,NULL,NULL,0,NULL,0,'2026-02-18 20:43:52','2026-02-18 20:43:52'),
(2,3,'Teste2','bar',3,'status',NULL,NULL,'all',NULL,NULL,NULL,0,NULL,0,'2026-02-18 20:43:52','2026-02-18 20:43:52'),
(7,5,'dfgh','line',5,'temperatura',NULL,NULL,'all',NULL,'[\"temperatura\"]',NULL,0,'{\"theme\":\"light\",\"show_legend\":true,\"show_grid\":true}',1,'2026-02-19 01:56:19','2026-02-19 03:00:18'),
(9,6,'ghjgh','line',9,'temperatura',NULL,NULL,'all',NULL,'[\"temperatura\"]',NULL,0,'{\"theme\":\"light\",\"show_legend\":true,\"show_grid\":true}',0,'2026-07-09 01:04:04','2026-07-09 01:04:04'),
(10,6,'sdfgdf','line',9,'temperatura',NULL,NULL,'all',NULL,'[\"temperatura\"]',NULL,1,'{\"theme\":\"light\",\"show_legend\":true,\"show_grid\":true}',0,'2026-07-09 01:08:51','2026-07-09 01:08:51'),
(11,6,'teste2','line',9,'temperatura',NULL,NULL,'24h',NULL,'[\"temperatura\"]',NULL,0,'{\"theme\":\"light\",\"show_legend\":true,\"show_grid\":true}',0,'2026-07-09 01:11:16','2026-07-09 01:11:16');
/*!40000 ALTER TABLE `charts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_mqtt_status`
--

DROP TABLE IF EXISTS `device_mqtt_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_mqtt_status` (
  `device_id` int(10) unsigned NOT NULL,
  `last_seen` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_online` tinyint(1) DEFAULT 0,
  `signal_strength` int(11) DEFAULT NULL,
  PRIMARY KEY (`device_id`),
  CONSTRAINT `device_mqtt_status_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_mqtt_status`
--

LOCK TABLES `device_mqtt_status` WRITE;
/*!40000 ALTER TABLE `device_mqtt_status` DISABLE KEYS */;
/*!40000 ALTER TABLE `device_mqtt_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_payloads`
--

DROP TABLE IF EXISTS `device_payloads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_payloads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` int(10) unsigned NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `source` varchar(20) DEFAULT 'http',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_device_time` (`device_id`,`created_at` DESC),
  KEY `idx_device_payloads_source` (`source`,`created_at`),
  CONSTRAINT `device_payloads_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_payloads`
--

LOCK TABLES `device_payloads` WRITE;
/*!40000 ALTER TABLE `device_payloads` DISABLE KEYS */;
INSERT INTO `device_payloads` VALUES
(1,2,'{\"temperatura\":25.5,\"umidade\":60,\"Teste\":\"teste\"}','http','2025-10-20 14:25:41'),
(2,3,'{\"temperatura\":25,\"status\":\"teste\"}','http','2025-11-06 01:05:50'),
(3,3,'{\"temperatura\":25,\"status\":\"teste\"}','http','2025-11-06 01:05:52'),
(4,3,'{\"temperatura\":25,\"status\":\"teste\"}','http','2025-11-06 01:05:53'),
(5,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 20:22:42'),
(6,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 20:22:45'),
(7,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 20:22:45'),
(8,5,'{\"temperatura\":25,\"statusi\":\"teste\"}','http','2026-02-18 20:22:50'),
(9,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 20:22:54'),
(10,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:50'),
(11,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:51'),
(12,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:52'),
(13,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:52'),
(14,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:52'),
(15,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:53'),
(16,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:53'),
(17,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:53'),
(18,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:53'),
(19,5,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-02-18 21:16:54'),
(20,5,'{\"temperatura\":24,\"status\":\"teste\"}','http','2026-02-19 01:40:19'),
(21,5,'{\"temperatura\":2123,\"status\":\"teste\"}','http','2026-02-19 01:40:23'),
(22,6,'{\"umidade\":25,\"status\":\"teste\"}','http','2026-02-19 01:48:27'),
(23,2,'{\"temperatura\":26.5,\"umidade\":65,\"teste\":\"mqtt\"}','mqtt','2026-03-02 02:38:54'),
(24,2,'{\"teste_final\":true,\"hora\":\"23:39:33\"}','mqtt','2026-03-02 02:39:34'),
(25,6,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-03-03 00:16:18'),
(26,6,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-03-03 00:16:18'),
(27,6,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-03-03 00:16:19'),
(28,8,'{\"temperatura\":24.5,\"umidade\":60}','mqtt','2026-07-08 02:23:01'),
(29,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:49'),
(30,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:50'),
(31,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:50'),
(32,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:50'),
(33,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:51'),
(34,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:51'),
(35,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:51'),
(36,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:51'),
(37,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:51'),
(38,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:52'),
(39,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:52'),
(40,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:52'),
(41,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:52'),
(42,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:52'),
(43,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:53'),
(44,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:53'),
(45,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:53'),
(46,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:53'),
(47,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:53'),
(48,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:54'),
(49,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:54'),
(50,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:54'),
(51,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:54'),
(52,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:54'),
(53,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:55'),
(54,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:55'),
(55,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:55'),
(56,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:55'),
(57,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 16:28:55'),
(58,9,'{\"temperatura\":26,\"status\":\"teste\"}','http','2026-07-08 16:29:01'),
(59,9,'{\"temperatura\":21,\"status\":\"teste\"}','http','2026-07-08 16:29:04'),
(60,9,'{\"temperatura\":28,\"status\":\"teste\"}','http','2026-07-08 16:29:08'),
(61,9,'{\"temperatura\":45,\"status\":\"teste\"}','http','2026-07-08 16:29:11'),
(62,9,'{\"temperatura\":4575,\"status\":\"teste\"}','http','2026-07-08 16:29:14'),
(63,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:21'),
(64,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:25'),
(65,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:25'),
(66,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:25'),
(67,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:26'),
(68,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:26'),
(69,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:26'),
(70,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:26'),
(71,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:27'),
(72,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:27'),
(73,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:27'),
(74,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:27'),
(75,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:28'),
(76,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:28'),
(77,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:28'),
(78,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:29'),
(79,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:29'),
(80,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:29'),
(81,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-08 23:46:29'),
(82,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:05'),
(83,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:07'),
(84,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:07'),
(85,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:07'),
(86,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:07'),
(87,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:07'),
(88,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:08'),
(89,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:08'),
(90,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:08'),
(91,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:08'),
(92,11,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:09:08'),
(93,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:11:50'),
(94,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:11:52'),
(95,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:11:52'),
(96,9,'{\"temperatura\":25,\"status\":\"teste\"}','http','2026-07-09 01:11:53');
/*!40000 ALTER TABLE `device_payloads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_rate_limits`
--

DROP TABLE IF EXISTS `device_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_rate_limits` (
  `device_id` int(10) unsigned NOT NULL,
  `custom_requests_per_minute` int(11) DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`device_id`),
  CONSTRAINT `device_rate_limits_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_rate_limits`
--

LOCK TABLES `device_rate_limits` WRITE;
/*!40000 ALTER TABLE `device_rate_limits` DISABLE KEYS */;
/*!40000 ALTER TABLE `device_rate_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_tags`
--

DROP TABLE IF EXISTS `device_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_tags` (
  `device_id` int(10) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`device_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `device_tags_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_tags`
--

LOCK TABLES `device_tags` WRITE;
/*!40000 ALTER TABLE `device_tags` DISABLE KEYS */;
/*!40000 ALTER TABLE `device_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `devices`
--

DROP TABLE IF EXISTS `devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(200) DEFAULT NULL,
  `api_key` varchar(64) DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deletedAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `project_id` (`project_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `devices_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `devices_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `devices`
--

LOCK TABLES `devices` WRITE;
/*!40000 ALTER TABLE `devices` DISABLE KEYS */;
INSERT INTO `devices` VALUES
(2,2,2,'Dispositivo Teste 2','Dispositivo para teste 2','dcf7989841ee8387f5f0d65a3dbf3d9a090ff06835bcf5f330742a35a759d120','2025-10-20 14:24:06','2025-10-20 14:24:06',NULL),
(3,3,4,'Dispositivo Teste','Um dispositivo teste para esse projeto','4c96f15ae6a1e89b53181bb024ec895401cce3e6a774718178369fe56073fd02','2025-11-05 23:55:17','2025-11-05 23:55:17',NULL),
(4,3,4,'Dispositivo para teste do gráfico','testando os gráficos','61d90387d0685d93ab88951af607bbcf1367b59aff321c0af30447f1a7b35855','2025-11-06 02:44:17','2025-11-06 02:44:17',NULL),
(5,5,5,'teste','teste','36a14f52da53b70f463552bc139c2df45451219e411f2fc4425173619567723b','2026-02-13 21:54:38','2026-02-13 21:54:38',NULL),
(6,5,5,'fghdfgh','dfghdfg','9dcd3ba867cada03c18573686ab877800d8cfabad1294de6487492ed66b79efc','2026-02-19 00:08:11','2026-02-19 00:08:11',NULL),
(7,5,5,'sfagsdf','sdfgsdf','79e06f6b8883b681a93e9dcd2ceeb5bd2bc3eac247d44e3abcff234e69552ff9','2026-03-03 00:48:22','2026-03-03 00:48:22',NULL),
(8,6,5,'MQTT Teste','Dispositivo para testar o MQTT','3c4b7414b5bfc34a745ea81c9628d675d81a2a7980118e72012940fae48cf2f0','2026-07-07 21:46:46','2026-07-07 21:46:46',NULL),
(9,6,5,'TEste2','fdgsdf','77f62f5dc3a32573046d2538cfd4ca080219c27014eb278ccd2b074b8a0d397c','2026-07-08 02:42:46','2026-07-08 02:42:46',NULL),
(10,7,5,'fgh','fghfg','41e525b949db958146263fe998191605c53f934152c8609c85ff40fa161a891f','2026-07-08 02:46:41','2026-07-08 02:46:41',NULL),
(11,6,5,'ghjfghj','fghjfgh','1d8a138ffbe8100d3896c745912abe5cb1d6ef7f3696f99cb2634f15f54af857','2026-07-08 16:27:28','2026-07-08 16:27:28',NULL);
/*!40000 ALTER TABLE `devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invitations`
--

DROP TABLE IF EXISTS `invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invitations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `invited_by` int(10) unsigned NOT NULL,
  `invited_user_id` int(10) unsigned DEFAULT NULL,
  `invited_email` varchar(255) NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `status` enum('pending','accepted','rejected','cancelled') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pending_invitation` (`project_id`,`invited_email`,`status`),
  KEY `invited_by` (`invited_by`),
  KEY `role_id` (`role_id`),
  KEY `idx_invited_email` (`invited_email`),
  KEY `idx_invited_user` (`invited_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `invitations_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invitations_ibfk_2` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invitations_ibfk_3` FOREIGN KEY (`invited_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invitations_ibfk_4` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invitations`
--

LOCK TABLES `invitations` WRITE;
/*!40000 ALTER TABLE `invitations` DISABLE KEYS */;
INSERT INTO `invitations` VALUES
(1,5,5,2,'denisribeiro120@gmail.com',2,'pending','2026-02-18 22:39:02','2026-02-25 22:39:02',NULL,NULL);
/*!40000 ALTER TABLE `invitations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mqtt_acl`
--

DROP TABLE IF EXISTS `mqtt_acl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mqtt_acl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(10) unsigned NOT NULL,
  `allow_subscribe` tinyint(1) DEFAULT 0,
  `allow_publish` tinyint(1) DEFAULT 1,
  `topic_filter` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device_topic` (`device_id`,`topic_filter`),
  KEY `idx_device` (`device_id`),
  CONSTRAINT `mqtt_acl_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mqtt_acl`
--

LOCK TABLES `mqtt_acl` WRITE;
/*!40000 ALTER TABLE `mqtt_acl` DISABLE KEYS */;
/*!40000 ALTER TABLE `mqtt_acl` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mqtt_credentials`
--

DROP TABLE IF EXISTS `mqtt_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mqtt_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(10) unsigned NOT NULL,
  `mqtt_username` varchar(100) NOT NULL,
  `mqtt_password` varchar(255) DEFAULT NULL,
  `mqtt_password_hash` varchar(255) NOT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_id` (`device_id`),
  UNIQUE KEY `mqtt_username` (`mqtt_username`),
  KEY `idx_username` (`mqtt_username`),
  CONSTRAINT `mqtt_credentials_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mqtt_credentials`
--

LOCK TABLES `mqtt_credentials` WRITE;
/*!40000 ALTER TABLE `mqtt_credentials` DISABLE KEYS */;
INSERT INTO `mqtt_credentials` VALUES
(1,2,'device_2','2ptg8jI_5T00TDlltCcraRv&','$7$101$1H2WsNz/z1zis8yg$kstdtxzjRJ9qPd6a2N5DqrB8THkfqWZF5p2bfs+0MoPs5oxbr/x9Hgx3F+sdMWFck5jV5pqMrCIf3GsO3yr+bg==',1,'2026-03-02 03:03:27','2026-03-03 01:08:13'),
(2,3,'device_3','0S@LqqviAhPkV8r@w)V*jlD2','$7$101$mlQ+4L63I7nkUgTz$L1AJP33LgojbQLT2R+pQw744bHP78tdgshHFgXntUDOZTvcf327Ie1P5w1wziyAD6EyYCHZ4lMLC/1aCtiCfeg==',1,'2026-03-02 03:03:27','2026-03-03 01:08:13'),
(3,4,'device_4','+Q&@iIyD9hVBWTU7+lZHb6w8','$7$101$E2enihV5tlLX1wDl$ElnahBxybolizzuzb9eApxbXd/i9KdvDhGvxuhIK726iSYCRUnFK7RxlDK8sXwii2hAmXHd+MrSy4PGde3msqQ==',1,'2026-03-02 03:03:27','2026-03-03 01:08:13'),
(4,5,'device_5','+TxEr@YD_aVqU@S$5S#+c^Hz','$7$101$LH6R8Lk4pLyZ5qDM$SiAl110xZDxiNuZYrS2t9x/pnkzw3f/JpWFDjhTmjaTL+iETOek86WmAkHQmtcHVsYs9YWGiYoZb9A/rJms6HA==',1,'2026-03-02 03:03:27','2026-03-03 01:08:13'),
(5,6,'device_6','LQp%5EE*EC&o*GjZq6WA5mS!','$7$101$LkmpH1H8lYTUuZHJ$vZnsGGxIrxisYRxuC7FhzXUAINZBvyeGochiq+4R4fDx0LzOTaKlYcsmn59RoX7ElxcvMQ/QCNPkzD5hSO83Zg==',1,'2026-03-02 03:03:27','2026-03-03 01:08:13'),
(6,7,'device_7','f19775fdfd80bdcc343a08ad','$7$101$WyBWzdKbdcN6mw17$aJp1yUWZJbBgsoHwR2pKcl1nYdku8/qQh6AOU6GU92XhUYmEzYJ/MqiJ2M9zf3AtRYg0pU2PXS+RU2jMb2CoXg==',1,'2026-03-03 00:48:22','2026-03-03 01:08:13'),
(7,8,'mqdev_3c4b7414b5bfc34a',NULL,'$2y$12$qzl0YZ1P7Qpj9ioZ5HMdPeI/.0OuZPqrepUYyZg/0gsmTGljyPUQ6',1,'2026-07-07 21:46:46','2026-07-08 02:48:22'),
(8,9,'mqdev_77f62f5dc3a32573',NULL,'$7$101$oDWydhLytsOE2Ptk$jI+xRBUcS0qbW/bFzBRzMcv2r3Hgzq3XK7GcTkJApyLKKH4ag8s5B0KHcdTTFtrC+G2ipr+LIQbFh4080kQmJg==',1,'2026-07-08 02:42:46','2026-07-08 02:42:46'),
(9,10,'mqdev_41e525b949db9581',NULL,'$2y$12$Ncy/3n1/tEeXVVeDD7Uglusx4MCKq6GekQwnazQjB4NdLZepUbSPa',1,'2026-07-08 02:46:41','2026-07-08 03:07:12'),
(10,11,'mqdev_1d8a138ffbe8100d','4b6175bf7d9f68b164f5e010','$7$101$inGJR52Ikx7fBSEf$cDiVI1UFmWtidVN1tkG9rFukaPvbMjwhJzAW/asjR4iqIHzwqohXGDUesaknLG3l5NXoZPmXi5Ok8psHB52yhw==',1,'2026-07-08 16:27:28','2026-07-08 16:27:45');
/*!40000 ALTER TABLE `mqtt_credentials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `profile_requests`
--

DROP TABLE IF EXISTS `profile_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `profile_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `status` enum('pendente','aprovado','rejeitado') NOT NULL DEFAULT 'pendente',
  `message` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `profile_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profile_requests`
--

LOCK TABLES `profile_requests` WRITE;
/*!40000 ALTER TABLE `profile_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `profile_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_join_requests`
--

DROP TABLE IF EXISTS `project_join_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_join_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `status` enum('pendente','aceito','rejeitado') NOT NULL DEFAULT 'pendente',
  `message` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `respondedAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_request` (`project_id`,`user_id`,`status`),
  KEY `idx_status` (`status`),
  KEY `idx_project` (`project_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_project_status` (`project_id`,`status`),
  CONSTRAINT `project_join_requests_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_join_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_join_requests`
--

LOCK TABLES `project_join_requests` WRITE;
/*!40000 ALTER TABLE `project_join_requests` DISABLE KEYS */;
INSERT INTO `project_join_requests` VALUES
(1,3,5,'pendente','Gostaria de participar deste projeto','2026-03-03 00:27:48','2026-03-03 00:27:48',NULL),
(2,4,5,'pendente','Gostaria de participar deste projeto','2026-07-10 02:37:34','2026-07-10 02:37:34',NULL);
/*!40000 ALTER TABLE `project_join_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_tags`
--

DROP TABLE IF EXISTS `project_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_tags` (
  `project_id` int(10) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`project_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `project_tags_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_tags`
--

LOCK TABLES `project_tags` WRITE;
/*!40000 ALTER TABLE `project_tags` DISABLE KEYS */;
INSERT INTO `project_tags` VALUES
(4,1),
(6,1);
/*!40000 ALTER TABLE `project_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(30) NOT NULL,
  `description` varchar(200) DEFAULT NULL,
  `public` tinyint(1) NOT NULL DEFAULT 0,
  `maxUsers` int(10) unsigned DEFAULT NULL,
  `invitation` varchar(15) DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deletedAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invitation` (`invitation`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `projects`
--

LOCK TABLES `projects` WRITE;
/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
INSERT INTO `projects` VALUES
(2,'Teste','Projeto teste.',1,5,'5431cb0a5ad4','2025-10-20 14:20:31','2025-10-20 14:20:31',NULL),
(3,'Teste V2','Um teste depois de eu fazer várias modificações no código',1,NULL,'22017a45525b','2025-11-05 23:54:41','2025-11-05 23:54:41',NULL),
(4,'Teste das tags','Testando adicionar e romover tags',1,NULL,'89e4bf68557d','2025-11-06 00:41:33','2025-11-06 00:41:33',NULL),
(5,'teste','teste',1,NULL,'2d491c4a37a5','2026-02-13 21:35:13','2026-03-03 00:22:32',NULL),
(6,'Testando o MQTT','Testando a funcionalidade do MQTT',1,3,'41bdb3990ac1','2026-07-07 21:43:42','2026-07-07 21:43:42',NULL),
(7,'dfgh','dfghdfg',0,3243,'5835498690c6','2026-07-08 02:46:29','2026-07-08 02:46:29',NULL);
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rate_limit_violations`
--

DROP TABLE IF EXISTS `rate_limit_violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limit_violations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `endpoint` varchar(100) DEFAULT NULL,
  `requests_in_window` int(11) DEFAULT NULL,
  `limit_value` int(11) DEFAULT NULL,
  `source` varchar(20) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_device_time` (`device_id`,`created_at` DESC),
  KEY `idx_violations_recent` (`created_at` DESC),
  CONSTRAINT `rate_limit_violations_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rate_limit_violations_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rate_limit_violations`
--

LOCK TABLES `rate_limit_violations` WRITE;
/*!40000 ALTER TABLE `rate_limit_violations` DISABLE KEYS */;
/*!40000 ALTER TABLE `rate_limit_violations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` enum('Gerente','Participante') NOT NULL,
  `canDeleteSensor` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'Gerente',1),
(2,'Participante',0);
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `state_mappings`
--

DROP TABLE IF EXISTS `state_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `state_mappings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` int(10) unsigned NOT NULL,
  `json_key` varchar(100) NOT NULL,
  `value_read` varchar(100) NOT NULL,
  `description` varchar(200) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_key_value` (`device_id`,`json_key`,`value_read`),
  CONSTRAINT `state_mappings_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `state_mappings`
--

LOCK TABLES `state_mappings` WRITE;
/*!40000 ALTER TABLE `state_mappings` DISABLE KEYS */;
/*!40000 ALTER TABLE `state_mappings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tags`
--

LOCK TABLES `tags` WRITE;
/*!40000 ALTER TABLE `tags` DISABLE KEYS */;
INSERT INTO `tags` VALUES
(1,'Iot');
/*!40000 ALTER TABLE `tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `email` varchar(50) NOT NULL,
  `profile` enum('Admin','Moderator','User') NOT NULL DEFAULT 'User',
  `username` varchar(20) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deletedAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(2,'Denis Ribeiro','denisribeiro120@gmail.com','Admin','Denis',NULL,'$2y$10$nK4qdUwsnBqIRoRkoNlsousMpjDrACleNGeTzTn2yeI86U0lLGs.a',1,'2025-10-20 14:19:55','2025-10-20 14:19:55',NULL),
(4,'Denis Ribeiro','denisribeiro120@hotmail.com','User','Denis2',NULL,'$2y$10$eRph.lJLoK6XYNZzHXq8ne1vusgrj3avMWBkNWe.YODrFX21Vm866',1,'2025-11-05 22:54:01','2025-11-05 22:54:01',NULL),
(5,'Teste da Silva','teste@teste.com','Admin','Testonho','uploads/profile/user_5_1771468603.png','$2y$10$P4HKCZqsjlzUSF27zEc4WOCYPqvlTOLrefbl4VFfpVNBlFRfVuFzu',1,'2026-02-13 21:31:55','2026-07-09 02:14:09',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_verifications`
--

DROP TABLE IF EXISTS `email_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `email_to_verify` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `type` enum('REGISTER','PASSWORD_RESET') DEFAULT 'REGISTER',
  `used` tinyint(1) DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_email_verifications_user` (`user_id`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_verifications`
--

LOCK TABLES `email_verifications` WRITE;
/*!40000 ALTER TABLE `email_verifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_verifications` ENABLE KEYS */;
UNLOCK TABLES;


--
-- Table structure for table `users_projects`
--

DROP TABLE IF EXISTS `users_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_projects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_project` (`user_id`,`project_id`),
  KEY `project_id` (`project_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_projects_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_projects_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_projects_ibfk_3` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users_projects`
--

LOCK TABLES `users_projects` WRITE;
/*!40000 ALTER TABLE `users_projects` DISABLE KEYS */;
INSERT INTO `users_projects` VALUES
(1,3,4,1),
(2,4,4,1),
(3,5,5,1),
(4,6,5,1),
(5,7,5,1);
/*!40000 ALTER TABLE `users_projects` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-14 22:44:42