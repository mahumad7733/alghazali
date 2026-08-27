/*M!999999\- enable the sandbox mode */
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: 127.0.0.1    Database: rihla_latest_local
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
-- Table structure for table `account_transactions`
--

DROP TABLE IF EXISTS `account_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_type` varchar(64) NOT NULL,
  `debit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reference_type` varchar(64) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `note_ar` varchar(500) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account_transactions_account_date` (`account_id`,`created_at`),
  KEY `fk_account_transactions_booking` (`booking_id`),
  KEY `fk_account_transactions_user` (`created_by_user_id`),
  CONSTRAINT `fk_account_transactions_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_account_transactions_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_account_transactions_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_transactions`
--

LOCK TABLES `account_transactions` WRITE;
/*!40000 ALTER TABLE `account_transactions` DISABLE KEYS */;
INSERT INTO `account_transactions` VALUES
(1,1,35,'booking_payment_received',20000.00,0.00,'payment',1,'استلام دفعة الحجز BK-2026-754122',1,'2026-08-27 01:14:39'),
(2,1,36,'booking_payment_received',20000.00,0.00,'payment',2,'استلام دفعة الحجز BK-2026-257818',1,'2026-08-27 01:14:39'),
(3,1,38,'booking_payment_received',20000.00,0.00,'payment',3,'استلام دفعة الحجز BK-2026-112062',1,'2026-08-27 01:15:23'),
(4,1,39,'booking_payment_received',20000.00,0.00,'payment',4,'استلام دفعة الحجز BK-2026-091397',1,'2026-08-27 01:15:23');
/*!40000 ALTER TABLE `account_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `account_code` varchar(64) NOT NULL,
  `name_ar` varchar(180) NOT NULL,
  `account_type` enum('asset','liability','income','expense','equity') NOT NULL,
  `current_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_company_code_currency` (`company_id`,`account_code`,`currency_id`),
  KEY `fk_accounts_currency` (`currency_id`),
  CONSTRAINT `fk_accounts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_accounts_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
INSERT INTO `accounts` VALUES
(1,1,1,'customer_collections','متحصلات العملاء','asset',80000.00,1);
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agent_commissions`
--

DROP TABLE IF EXISTS `agent_commissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_commissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `commission_type` enum('percentage','fixed') NOT NULL,
  `rate_value` decimal(12,4) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `status` enum('pending','payable','paid','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_commission_booking` (`agent_id`,`booking_id`),
  KEY `fk_agent_commissions_booking` (`booking_id`),
  KEY `fk_agent_commissions_currency` (`currency_id`),
  CONSTRAINT `fk_agent_commissions_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`),
  CONSTRAINT `fk_agent_commissions_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agent_commissions_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agent_commissions`
--

LOCK TABLES `agent_commissions` WRITE;
/*!40000 ALTER TABLE `agent_commissions` DISABLE KEYS */;
INSERT INTO `agent_commissions` VALUES
(1,3,40,1,'percentage',0.0000,0.00,'payable','2026-08-27 01:15:23');
/*!40000 ALTER TABLE `agent_commissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agent_wallet_transactions`
--

DROP TABLE IF EXISTS `agent_wallet_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_wallet_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_wallet_id` bigint(20) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_type` enum('top_up','deduction','booking','credit_usage','debt_payment','refund','settlement','commission','admin_adjustment') NOT NULL,
  `debit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_before` decimal(14,2) NOT NULL,
  `balance_after` decimal(14,2) NOT NULL,
  `debt_before` decimal(14,2) NOT NULL,
  `debt_after` decimal(14,2) NOT NULL,
  `performed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wallet_transactions_wallet_date` (`agent_wallet_id`,`created_at`),
  KEY `fk_wallet_transactions_booking` (`booking_id`),
  KEY `fk_wallet_transactions_user` (`performed_by_user_id`),
  CONSTRAINT `fk_wallet_transactions_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wallet_transactions_user` FOREIGN KEY (`performed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wallet_transactions_wallet` FOREIGN KEY (`agent_wallet_id`) REFERENCES `agent_wallets` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agent_wallet_transactions`
--

LOCK TABLES `agent_wallet_transactions` WRITE;
/*!40000 ALTER TABLE `agent_wallet_transactions` DISABLE KEYS */;
INSERT INTO `agent_wallet_transactions` VALUES
(1,4,NULL,'top_up',0.00,50000.00,0.00,50000.00,0.00,0.00,1,'تحويل','2026-08-27 00:40:40'),
(2,4,NULL,'top_up',0.00,50000.00,50000.00,100000.00,0.00,0.00,1,'تحويل','2026-08-27 00:40:41'),
(3,4,40,'booking',20000.00,0.00,100000.00,80000.00,0.00,0.00,1,'تأكيد الحجز BK-2026-677629','2026-08-27 01:15:23');
/*!40000 ALTER TABLE `agent_wallet_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agent_wallets`
--

DROP TABLE IF EXISTS `agent_wallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_wallets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit_limit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `used_debt` decimal(14,2) NOT NULL DEFAULT 0.00,
  `minimum_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_wallet_currency` (`agent_id`,`currency_id`),
  KEY `fk_agent_wallet_currency` (`currency_id`),
  CONSTRAINT `fk_agent_wallet_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agent_wallet_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agent_wallets`
--

LOCK TABLES `agent_wallets` WRITE;
/*!40000 ALTER TABLE `agent_wallets` DISABLE KEYS */;
INSERT INTO `agent_wallets` VALUES
(3,3,2,0.00,0.00,0.00,0.00,'2026-08-27 00:13:35'),
(4,3,1,80000.00,0.00,0.00,0.00,'2026-08-27 01:35:57');
/*!40000 ALTER TABLE `agent_wallets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agents`
--

DROP TABLE IF EXISTS `agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `agent_code` char(10) DEFAULT NULL,
  `country_id` bigint(20) unsigned NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `commission_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `commission_value` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `status` enum('active','financially_blocked','suspended') NOT NULL DEFAULT 'active',
  `credit_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `block_at_minimum_balance` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `uq_agents_agent_code` (`agent_code`),
  KEY `fk_agents_company` (`company_id`),
  KEY `fk_agents_country` (`country_id`),
  CONSTRAINT `fk_agents_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agents_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`),
  CONSTRAINT `fk_agents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agents`
--

LOCK TABLES `agents` WRITE;
/*!40000 ALTER TABLE `agents` DISABLE KEYS */;
INSERT INTO `agents` VALUES
(3,1,12,'7381558611',1,NULL,NULL,'percentage',0.0000,'active',0,0,'2026-08-27 00:13:35','2026-08-27 00:13:35');
/*!40000 ALTER TABLE `agents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `api_tokens`
--

DROP TABLE IF EXISTS `api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_name` varchar(120) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `fk_api_tokens_user` (`user_id`),
  CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_tokens`
--

LOCK TABLES `api_tokens` WRITE;
/*!40000 ALTER TABLE `api_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `api_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_company_date` (`company_id`,`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `fk_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=166 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES
(1,1,NULL,'user_logged_in','user',1,NULL,NULL,'127.0.0.1','','2026-08-26 19:52:11'),
(2,1,NULL,'user_logged_in','user',1,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 19:54:42'),
(3,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 19:58:09'),
(4,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 19:58:23'),
(5,1,NULL,'customer_created_by_admin','user',2,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 19:59:26'),
(6,1,2,'company_created','company',2,NULL,'{\"trade_name\":\"شركة المتصدار\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:24:10'),
(7,1,NULL,'user_logged_out','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:25:07'),
(8,1,NULL,'user_logged_out','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 20:30:58'),
(9,1,NULL,'user_logged_in','user',1,NULL,NULL,'192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:33:50'),
(10,1,2,'company_updated','company',2,NULL,'{\"trade_name\":\"شركة المتصدار\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:35:58'),
(11,1,2,'company_logo_updated','company',2,NULL,'{\"logo_path\":\"uploads\\/companies\\/2\\/logo.png\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:35:59'),
(12,1,2,'company_updated','company',2,NULL,'{\"trade_name\":\"شركة المتصدار\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:36:49'),
(13,1,2,'company_cover_updated','company',2,NULL,'{\"cover_image_path\":\"uploads\\/companies\\/2\\/cover.jpg\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:36:50'),
(14,1,2,'company_gallery_updated','company',2,NULL,'{\"image_id\":3,\"image_order\":1,\"image_path\":\"uploads\\/companies\\/2\\/gallery\\/image-1.jpg\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:36:50'),
(15,1,2,'company_gallery_updated','company',2,NULL,'{\"image_id\":4,\"image_order\":2,\"image_path\":\"uploads\\/companies\\/2\\/gallery\\/image-2.jpg\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:36:51'),
(16,1,2,'company_gallery_updated','company',2,NULL,'{\"image_id\":5,\"image_order\":3,\"image_path\":\"uploads\\/companies\\/2\\/gallery\\/image-3.jpg\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:36:52'),
(17,1,2,'company_gallery_updated','company',2,NULL,'{\"image_id\":6,\"image_order\":4,\"image_path\":\"uploads\\/companies\\/2\\/gallery\\/image-4.jpg\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:36:53'),
(18,1,2,'company_gallery_updated','company',2,NULL,'{\"image_id\":7,\"image_order\":5,\"image_path\":\"uploads\\/companies\\/2\\/gallery\\/image-5.jpg\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:36:53'),
(19,1,2,'company_gallery_updated','company',2,NULL,'{\"image_id\":8,\"image_order\":6,\"image_path\":\"uploads\\/companies\\/2\\/gallery\\/image-6.jpg\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:36:54'),
(20,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:37:13'),
(21,3,NULL,'customer_registered','user',3,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 20:38:35'),
(22,1,NULL,'country_status_updated','country',1,NULL,'{\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:39:07'),
(23,1,NULL,'user_logged_out','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:40:39'),
(24,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:41:44'),
(25,1,NULL,'country_created','country',2,NULL,'{\"code\":\"SR\",\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:43:09'),
(26,1,NULL,'user_logged_out','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:44:04'),
(27,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:45:03'),
(28,1,NULL,'country_created','country',3,NULL,'{\"code\":\"FF\",\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 20:49:01'),
(29,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 20:49:40'),
(30,1,NULL,'city_created','city',3,NULL,'{\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:05:57'),
(31,1,NULL,'user_logged_out','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:17:21'),
(32,1,NULL,'city_created','city',4,NULL,'{\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:23:12'),
(33,1,NULL,'city_created','city',5,NULL,'{\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:23:23'),
(34,1,NULL,'city_created','city',6,NULL,'{\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:23:34'),
(35,4,NULL,'customer_registered','user',4,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:24:23'),
(36,4,NULL,'user_logged_out','user',4,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:27:15'),
(37,1,NULL,'city_updated','city',3,NULL,'{\"name_ar\":\"جده\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:28:26'),
(38,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:30:40'),
(39,1,NULL,'user_logged_out','user',1,NULL,NULL,'192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 21:30:57'),
(40,1,NULL,'country_created','country',4,NULL,'{\"code\":\"ZZ\",\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:31:00'),
(41,1,NULL,'user_logged_out','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:35:47'),
(42,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:38:04'),
(43,1,NULL,'city_created','city',7,NULL,'{\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:39:49'),
(44,1,NULL,'city_created','city',8,NULL,'{\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:40:09'),
(45,1,NULL,'city_status_updated','city',7,NULL,'{\"status\":\"inactive\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:40:29'),
(46,1,NULL,'city_status_updated','city',7,NULL,'{\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:40:35'),
(47,1,NULL,'city_updated','city',8,NULL,'{\"name_ar\":\"مدينة اختبار محدثة\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:41:15'),
(48,1,NULL,'subroute_created','route_subroute',2,NULL,'{\"origin_city_id\":1,\"destination_city_id\":3,\"company_amount\":40000,\"amount\":50000,\"currency_id\":1,\"times\":{\"origin_arrival_time\":null,\"origin_departure_time\":\"22:14:00\",\"destination_arrival_time\":\"21:44:00\",\"destination_departure_time\":null}}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:44:23'),
(49,1,NULL,'subroute_created','route_subroute',3,NULL,'{\"origin_city_id\":4,\"destination_city_id\":3,\"company_amount\":40000,\"amount\":45000,\"currency_id\":1,\"times\":{\"origin_arrival_time\":null,\"origin_departure_time\":\"23:15:00\",\"destination_arrival_time\":\"22:45:00\",\"destination_departure_time\":null}}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 21:45:13'),
(50,1,NULL,'reference_created','currencies',2,NULL,'{\"code\":\"TST\",\"name_ar\":\"عملة اختبار\",\"symbol_ar\":\"ت\",\"decimal_places\":2}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:46:03'),
(51,1,NULL,'reference_created','exchange-rates',1,NULL,'{\"base_currency_id\":1,\"quote_currency_id\":2,\"rate\":250,\"effective_at\":\"2026-08-26 18:46:03\",\"expires_at\":null,\"created_by\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 21:46:03'),
(52,1,2,'route_composed_from_subroutes','route',7,NULL,'{\"code\":\"RT-000007\",\"subroute_ids\":[2,3],\"currency_id\":1,\"composition\":\"common_destination_branch\"}','','','2026-08-26 22:02:29'),
(53,1,2,'main_route_created','route',7,NULL,'{\"code\":\"RT-000007\",\"route_type\":\"normal\",\"status\":\"active\",\"subroute_ids\":[2,3]}','','','2026-08-26 22:02:29'),
(54,1,2,'main_route_updated','route',7,'{\"id\":7,\"company_id\":2,\"code\":\"RT-000007\",\"name_ar\":\"مسار تجريبي المتصدار\",\"route_type\":\"normal\",\"journey_type\":\"indirect\",\"status\":\"active\"}','{\"name_ar\":\"صنعاء - جده\",\"route_type\":\"normal\",\"status\":\"active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 22:03:43'),
(55,1,2,'recurring_trips_created','trip_recurrence',NULL,NULL,'{\"recurrence_group\":\"RG-5A24659939F9\",\"count\":2,\"route_id\":7,\"route_subroute_id\":2,\"bus_id\":null}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 22:04:31'),
(56,1,NULL,'user_logged_out','user',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 22:19:38'),
(57,1,NULL,'user_logged_in','user',1,NULL,NULL,'192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:24:40'),
(58,1,NULL,'site_brand_media_updated','site_settings',1,NULL,'{\"slot\":\"logo\",\"path\":\"uploads\\/site\\/logo.png\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:25'),
(59,5,NULL,'customer_registered','user',5,NULL,NULL,'::1','curl/8.21.0','2026-08-26 22:25:28'),
(60,1,NULL,'site_brand_media_updated','site_settings',1,NULL,'{\"slot\":\"icon\",\"path\":\"uploads\\/site\\/icon.png\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:31'),
(61,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:34'),
(62,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:38'),
(63,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:42'),
(64,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:43'),
(65,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:44'),
(66,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:45'),
(67,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:45'),
(68,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:46'),
(69,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:52'),
(70,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:58'),
(71,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:59'),
(72,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','192.168.0.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:25:59'),
(73,5,NULL,'user_profile_updated','user',5,NULL,'{\"password_changed\":false}','::1','curl/8.21.0','2026-08-26 22:26:06'),
(74,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلة\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 22:26:10'),
(75,5,NULL,'user_logged_in','user',5,NULL,NULL,'::1','curl/8.21.0','2026-08-26 22:26:22'),
(76,1,NULL,'user_logged_in','user',1,NULL,NULL,'::1','curl/8.21.0','2026-08-26 22:26:57'),
(77,5,2,'booking_created','booking',1,NULL,'{\"status\":\"pending\",\"booking_number\":\"BK-2026-001171\"}','::1','curl/8.21.0','2026-08-26 22:29:56'),
(78,6,NULL,'customer_registered','user',6,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 22:30:00'),
(79,6,NULL,'user_profile_image_updated','user',6,NULL,'{\"path\":\"uploads\\/profiles\\/user_6_62b69ea3d2.jpg\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 22:30:01'),
(80,5,NULL,'user_logged_in','user',5,NULL,NULL,'::1','curl/8.21.0','2026-08-26 22:30:34'),
(81,1,NULL,'customer_updated_by_admin','customer',4,NULL,NULL,'::1','curl/8.21.0','2026-08-26 22:31:46'),
(82,1,NULL,'customer_updated_by_admin','customer',4,NULL,NULL,'::1','curl/8.21.0','2026-08-26 22:31:49'),
(83,1,NULL,'customer_updated_by_admin','customer',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-26 22:33:50'),
(84,1,NULL,'user_logged_in','user',1,NULL,NULL,'10.158.235.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 20:40:27'),
(85,1,NULL,'user_logged_in','user',1,NULL,NULL,'10.158.235.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36; Manus-User/1.0','2026-08-26 20:49:37'),
(86,1,NULL,'user_logged_in','user',1,NULL,NULL,'10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 21:27:36'),
(87,1,2,'trip_prices_bulk_updated','trip',3,NULL,'{\"from\":\"2026-08-27\",\"to\":\"2026-08-29\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 21:39:24'),
(88,1,1,'trip_created','trip',4,NULL,'{\"trip_number\":\"TEST-COMM-004442\",\"route_subroute_id\":1}','','','2026-08-26 21:44:42'),
(93,1,1,'trip_prices_bulk_updated','trip',1,NULL,'{\"from\":\"2026-08-27\",\"to\":\"2026-08-27\"}','','','2026-08-26 22:14:10'),
(94,1,1,'trip_prices_bulk_updated','trip',1,NULL,'{\"from\":\"2026-08-27\",\"to\":\"2026-08-27\"}','','','2026-08-26 22:14:10'),
(95,9,NULL,'customer_registered','user',9,NULL,NULL,'10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-26 22:15:48'),
(96,9,2,'booking_created','booking',5,NULL,'{\"status\":\"pending\",\"booking_number\":\"BK-2026-860382\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-26 22:18:09'),
(97,1,2,'booking_confirmed','booking',5,'{\"status\":\"pending\"}','{\"status\":\"confirmed\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 22:20:53'),
(98,1,NULL,'user_logged_in','user',1,NULL,NULL,'127.0.0.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36; Manus-User/1.0','2026-08-26 22:28:26'),
(99,1,NULL,'user_logged_out','user',1,NULL,NULL,'127.0.0.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36; Manus-User/1.0','2026-08-26 22:50:22'),
(100,NULL,NULL,'customer_registered','user',10,NULL,NULL,'127.0.0.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36; Manus-User/1.0','2026-08-26 22:51:48'),
(101,1,NULL,'user_logged_in','user',1,NULL,NULL,'127.0.0.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36; Manus-User/1.0','2026-08-26 22:53:10'),
(102,NULL,2,'booking_expired_on_request','booking',1,'{\"status\":\"pending\"}','{\"status\":\"expired\"}','127.0.0.1','curl/8.5.0','2026-08-26 23:02:34'),
(103,1,2,'ticket_updated','ticket',1,'{\"seat_id\":33}','{\"seat_id\":33,\"gender\":\"female\"}','10.16.114.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36; Manus-User/1.0','2026-08-26 23:05:20'),
(104,1,2,'ticket_updated','ticket',1,'{\"seat_id\":33}','{\"seat_id\":33,\"gender\":\"female\"}','10.16.114.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36; Manus-User/1.0','2026-08-26 23:06:58'),
(105,11,NULL,'customer_registered','user',11,NULL,NULL,'10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/143.0.0.0 Mobile Safari/537.36','2026-08-26 23:10:33'),
(106,11,2,'booking_created','booking',31,NULL,'{\"status\":\"pending\",\"booking_number\":\"BK-2026-761472\",\"customer_name\":\"محمد محمد حسين الغزالي\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/143.0.0.0 Mobile Safari/537.36','2026-08-26 23:11:51'),
(107,1,2,'booking_confirmed','booking',31,'{\"status\":\"pending\"}','{\"status\":\"confirmed\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-26 23:12:33'),
(108,11,NULL,'user_profile_updated','user',11,NULL,'{\"password_changed\":false}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/143.0.0.0 Mobile Safari/537.36','2026-08-26 23:25:37'),
(109,11,NULL,'user_profile_image_updated','user',11,NULL,'{\"path\":\"uploads\\/profiles\\/user_11_cb0974350f.jpg\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/143.0.0.0 Mobile Safari/537.36','2026-08-26 23:25:38'),
(110,1,1,'agent_created_by_admin','agent',3,NULL,NULL,'10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-27 00:13:35'),
(111,1,NULL,'reference_created','stations',5,NULL,'{\"city_id\":4,\"name_ar\":\"الغزالي معبر\",\"address\":\"الغزالي معبر\",\"latitude\":null,\"longitude\":null,\"station_type\":\"company\",\"company_id\":2,\"agent_id\":null}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-27 00:14:51'),
(112,12,1,'user_logged_in','user',12,NULL,NULL,'10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-27 00:23:27'),
(113,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلتي\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-27 00:29:39'),
(114,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلتي\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-27 00:29:43'),
(115,1,1,'agent_wallet_credited','agent_wallet',4,'{\"balance\":0}','{\"balance\":\"50000.00\",\"reason\":\"تحويل\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-27 00:40:40'),
(116,1,1,'agent_wallet_credited','agent_wallet',4,'{\"balance\":50000}','{\"balance\":\"100000.00\",\"reason\":\"تحويل\"}','10.16.114.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36','2026-08-27 00:40:41'),
(117,1,1,'booking_created','booking',35,NULL,'{\"status\":\"pending\",\"booking_number\":\"BK-2026-754122\",\"customer_name\":\"عميل غير مسمى\"}','','','2026-08-27 01:14:39'),
(118,1,1,'payment_received','payment',1,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"company\"}','','','2026-08-27 01:14:39'),
(119,1,1,'booking_confirmed','booking',35,'{\"status\":\"pending\"}','{\"status\":\"confirmed\"}','','','2026-08-27 01:14:39'),
(120,1,1,'booking_created','booking',36,NULL,'{\"status\":\"pending\",\"booking_number\":\"BK-2026-257818\",\"customer_name\":\"عميل غير مسمى\"}','','','2026-08-27 01:14:39'),
(121,1,1,'payment_received','payment',2,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"bank_transfer\"}','','','2026-08-27 01:14:39'),
(122,1,1,'booking_confirmed','booking',36,'{\"status\":\"pending\"}','{\"status\":\"confirmed\"}','','','2026-08-27 01:14:39'),
(123,1,1,'booking_created','booking',38,NULL,'{\"status\":\"pending\",\"booking_number\":\"BK-2026-112062\",\"customer_name\":\"عميل غير مسمى\"}','','','2026-08-27 01:15:23'),
(124,1,1,'payment_received','payment',3,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"company\"}','','','2026-08-27 01:15:23'),
(125,1,1,'booking_confirmed','booking',38,'{\"status\":\"pending\"}','{\"status\":\"confirmed\"}','','','2026-08-27 01:15:23'),
(126,1,1,'booking_created','booking',39,NULL,'{\"status\":\"pending\",\"booking_number\":\"BK-2026-091397\",\"customer_name\":\"عميل غير مسمى\"}','','','2026-08-27 01:15:23'),
(127,1,1,'payment_received','payment',4,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"bank_transfer\"}','','','2026-08-27 01:15:23'),
(128,1,1,'booking_confirmed','booking',39,'{\"status\":\"pending\"}','{\"status\":\"confirmed\"}','','','2026-08-27 01:15:23'),
(129,12,1,'booking_created','booking',40,NULL,'{\"status\":\"pending\",\"booking_number\":\"BK-2026-677629\",\"customer_name\":\"عميل غير مسمى\"}','','','2026-08-27 01:15:23'),
(130,1,1,'payment_received','payment',5,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"agent\"}','','','2026-08-27 01:15:23'),
(131,1,1,'booking_confirmed','booking',40,'{\"status\":\"pending\"}','{\"status\":\"confirmed\"}','','','2026-08-27 01:15:23'),
(133,1,1,'payment_received','payment',6,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"company\"}','','','2026-08-27 01:15:59'),
(136,1,1,'payment_received','payment',7,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"bank_transfer\"}','','','2026-08-27 01:15:59'),
(139,1,1,'payment_received','payment',8,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"agent\"}','','','2026-08-27 01:15:59'),
(142,1,1,'payment_received','payment',9,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"company\"}','','','2026-08-27 01:16:14'),
(145,1,1,'payment_received','payment',10,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"bank_transfer\"}','','','2026-08-27 01:16:14'),
(148,1,1,'payment_received','payment',11,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"agent\"}','','','2026-08-27 01:16:14'),
(155,1,1,'payment_received','payment',14,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"company\"}','','','2026-08-27 01:35:57'),
(158,1,1,'payment_received','payment',15,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"bank_transfer\"}','','','2026-08-27 01:35:57'),
(161,1,1,'payment_received','payment',16,'{\"status\":\"pending\"}','{\"status\":\"completed\",\"channel\":\"agent\"}','','','2026-08-27 01:35:57'),
(163,1,NULL,'site_settings_updated','site_settings',1,NULL,'{\"site_name_ar\":\"منصة رحلتي\",\"tagline_ar\":\"احجز رحلتك بسهولة وأمان\",\"footer_text_ar\":\"© 2026 منصة رحلة\"}','127.0.0.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36; Manus-User/1.0','2026-08-27 01:51:52');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `banks`
--

DROP TABLE IF EXISTS `banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `banks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(180) NOT NULL,
  `account_name_ar` varchar(180) NOT NULL,
  `account_number` varchar(128) NOT NULL,
  `iban` varchar(128) DEFAULT NULL,
  `branch_name_ar` varchar(180) DEFAULT NULL,
  `notes_ar` varchar(500) DEFAULT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_banks_active_name` (`is_active`,`name_ar`),
  KEY `fk_banks_currency` (`currency_id`),
  CONSTRAINT `fk_banks_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `banks`
--

LOCK TABLES `banks` WRITE;
/*!40000 ALTER TABLE `banks` DISABLE KEYS */;
INSERT INTO `banks` VALUES
(1,'الكريمي','الغزالي للسفريات والسياحة','75767864',NULL,'معبر',NULL,1,1,'2026-08-27 01:07:20','2026-08-27 01:07:20');
/*!40000 ALTER TABLE `banks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_passengers`
--

DROP TABLE IF EXISTS `booking_passengers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_passengers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `passenger_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_passenger` (`booking_id`,`passenger_id`),
  KEY `fk_booking_passengers_passenger` (`passenger_id`),
  CONSTRAINT `fk_booking_passengers_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_passengers_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_passengers`
--

LOCK TABLES `booking_passengers` WRITE;
/*!40000 ALTER TABLE `booking_passengers` DISABLE KEYS */;
INSERT INTO `booking_passengers` VALUES
(1,1,1,'2026-08-26 22:29:56'),
(4,5,4,'2026-08-26 22:18:09'),
(5,31,5,'2026-08-26 23:11:51'),
(6,35,6,'2026-08-27 01:14:39'),
(7,36,7,'2026-08-27 01:14:39'),
(8,38,8,'2026-08-27 01:15:23'),
(9,39,9,'2026-08-27 01:15:23'),
(10,40,10,'2026-08-27 01:15:23'),
(11,41,11,'2026-08-27 01:15:59'),
(12,42,12,'2026-08-27 01:15:59'),
(13,43,13,'2026-08-27 01:15:59');
/*!40000 ALTER TABLE `booking_passengers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_seats`
--

DROP TABLE IF EXISTS `booking_seats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_seats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `booking_passenger_id` bigint(20) unsigned NOT NULL,
  `bus_seat_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_seat_passenger` (`booking_id`,`booking_passenger_id`),
  KEY `idx_booking_seats_conflict` (`bus_seat_id`,`booking_id`),
  KEY `fk_booking_seats_passenger` (`booking_passenger_id`),
  CONSTRAINT `fk_booking_seats_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_seats_bus_seat` FOREIGN KEY (`bus_seat_id`) REFERENCES `bus_seats` (`id`),
  CONSTRAINT `fk_booking_seats_passenger` FOREIGN KEY (`booking_passenger_id`) REFERENCES `booking_passengers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_seats`
--

LOCK TABLES `booking_seats` WRITE;
/*!40000 ALTER TABLE `booking_seats` DISABLE KEYS */;
INSERT INTO `booking_seats` VALUES
(1,1,1,32,'2026-08-26 22:29:56'),
(4,5,4,33,'2026-08-26 22:18:09'),
(5,31,5,32,'2026-08-26 23:11:51'),
(6,35,6,1,'2026-08-27 01:14:39'),
(7,36,7,2,'2026-08-27 01:14:39'),
(8,38,8,3,'2026-08-27 01:15:23'),
(9,39,9,4,'2026-08-27 01:15:23'),
(10,40,10,5,'2026-08-27 01:15:23'),
(11,41,11,6,'2026-08-27 01:15:59'),
(12,42,12,7,'2026-08-27 01:15:59'),
(13,43,13,8,'2026-08-27 01:15:59');
/*!40000 ALTER TABLE `booking_seats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_segments`
--

DROP TABLE IF EXISTS `booking_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_segments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `route_segment_id` bigint(20) unsigned NOT NULL,
  `origin_stop_order` smallint(5) unsigned NOT NULL,
  `destination_stop_order` smallint(5) unsigned NOT NULL,
  `origin_name_ar` varchar(180) NOT NULL,
  `destination_name_ar` varchar(180) NOT NULL,
  `company_unit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `unit_amount` decimal(14,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_segment` (`booking_id`,`route_segment_id`),
  KEY `idx_booking_segments_overlap` (`route_segment_id`,`origin_stop_order`,`destination_stop_order`),
  CONSTRAINT `fk_booking_segments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_segments_route_segment` FOREIGN KEY (`route_segment_id`) REFERENCES `route_segments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_segments`
--

LOCK TABLES `booking_segments` WRITE;
/*!40000 ALTER TABLE `booking_segments` DISABLE KEYS */;
INSERT INTO `booking_segments` VALUES
(1,1,3,2,3,'محطة معبر','محطة جده',40000.00,45000.00,'2026-08-26 22:29:56'),
(5,5,3,2,3,'محطة معبر','محطة جده',40000.00,50000.00,'2026-08-26 22:18:09'),
(31,31,2,1,3,'محطة صنعاء المركزية','محطة جده',40000.00,60000.00,'2026-08-26 23:11:51'),
(35,35,1,1,2,'محطة صنعاء المركزية','محطة تعز الرئيسية',15000.00,20000.00,'2026-08-27 01:14:39'),
(36,36,1,1,2,'محطة صنعاء المركزية','محطة تعز الرئيسية',15000.00,20000.00,'2026-08-27 01:14:39'),
(38,38,1,1,2,'محطة صنعاء المركزية','محطة تعز الرئيسية',15000.00,20000.00,'2026-08-27 01:15:23'),
(39,39,1,1,2,'محطة صنعاء المركزية','محطة تعز الرئيسية',15000.00,20000.00,'2026-08-27 01:15:23'),
(40,40,1,1,2,'محطة صنعاء المركزية','محطة تعز الرئيسية',15000.00,20000.00,'2026-08-27 01:15:23'),
(41,41,1,1,2,'محطة صنعاء المركزية','محطة تعز الرئيسية',15000.00,20000.00,'2026-08-27 01:15:59'),
(42,42,1,1,2,'محطة صنعاء المركزية','محطة تعز الرئيسية',15000.00,20000.00,'2026-08-27 01:15:59'),
(43,43,1,1,2,'محطة صنعاء المركزية','محطة تعز الرئيسية',15000.00,20000.00,'2026-08-27 01:15:59');
/*!40000 ALTER TABLE `booking_segments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_number` varchar(32) NOT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  `trip_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `source` enum('website','admin','agent','android','iphone','api') NOT NULL DEFAULT 'website',
  `currency_id` bigint(20) unsigned NOT NULL,
  `subtotal_amount` decimal(14,2) NOT NULL,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `agent_commission_type` enum('percentage','fixed') DEFAULT NULL,
  `agent_commission_rate` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `company_cost_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `company_payable_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `platform_commission_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL,
  `exchange_rate_used` decimal(18,8) DEFAULT NULL,
  `status` enum('pending','confirmed','cancelled','rejected','completed','expired') NOT NULL DEFAULT 'pending',
  `payment_status` enum('unpaid','pending','paid','refunded') NOT NULL DEFAULT 'unpaid',
  `held_until` datetime NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_number` (`booking_number`),
  KEY `idx_bookings_company_status` (`company_id`,`status`,`created_at`),
  KEY `idx_bookings_customer` (`customer_id`,`created_at`),
  KEY `idx_bookings_agent` (`agent_id`,`created_at`),
  KEY `idx_bookings_trip_status` (`trip_id`,`status`,`held_until`),
  KEY `fk_bookings_creator` (`created_by_user_id`),
  KEY `fk_bookings_currency` (`currency_id`),
  CONSTRAINT `fk_bookings_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_bookings_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_bookings_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_bookings_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
INSERT INTO `bookings` VALUES
(1,'BK-2026-001171',2,3,4,NULL,5,'website',1,45000.00,0.00,0.00,NULL,0.0000,40000.00,40000.00,5000.00,45000.00,NULL,'expired','unpaid','2026-08-26 22:59:56',NULL,NULL,'2026-08-26 23:02:34','انتهت مهلة تأكيد الحجز وتم تحرير المقاعد.','2026-08-26 22:29:56','2026-08-26 23:02:34'),
(5,'BK-2026-860382',2,3,6,NULL,9,'website',1,50000.00,0.00,0.00,NULL,0.0000,40000.00,40000.00,10000.00,50000.00,NULL,'confirmed','pending','2026-08-27 01:48:09','2026-08-26 22:20:53',NULL,NULL,NULL,'2026-08-26 22:18:09','2026-08-26 22:20:53'),
(31,'BK-2026-761472',2,3,8,NULL,11,'website',1,60000.00,0.00,0.00,NULL,0.0000,40000.00,40000.00,20000.00,60000.00,NULL,'confirmed','pending','2026-08-27 02:41:51','2026-08-26 23:12:33',NULL,NULL,NULL,'2026-08-26 23:11:51','2026-08-26 23:12:33'),
(35,'BK-2026-754122',1,1,NULL,NULL,1,'admin',1,20000.00,0.00,0.00,NULL,0.0000,15000.00,15000.00,5000.00,20000.00,NULL,'confirmed','paid','2026-08-27 04:44:39','2026-08-27 01:14:39',NULL,NULL,NULL,'2026-08-27 01:14:39','2026-08-27 01:14:39'),
(36,'BK-2026-257818',1,1,NULL,NULL,1,'admin',1,20000.00,0.00,0.00,NULL,0.0000,15000.00,15000.00,5000.00,20000.00,NULL,'confirmed','paid','2026-08-27 04:44:39','2026-08-27 01:14:39',NULL,NULL,NULL,'2026-08-27 01:14:39','2026-08-27 01:14:39'),
(38,'BK-2026-112062',1,1,NULL,NULL,1,'admin',1,20000.00,0.00,0.00,NULL,0.0000,15000.00,15000.00,5000.00,20000.00,NULL,'confirmed','paid','2026-08-27 04:45:23','2026-08-27 01:15:23',NULL,NULL,NULL,'2026-08-27 01:15:23','2026-08-27 01:15:23'),
(39,'BK-2026-091397',1,1,NULL,NULL,1,'admin',1,20000.00,0.00,0.00,NULL,0.0000,15000.00,15000.00,5000.00,20000.00,NULL,'confirmed','paid','2026-08-27 04:45:23','2026-08-27 01:15:23',NULL,NULL,NULL,'2026-08-27 01:15:23','2026-08-27 01:15:23'),
(40,'BK-2026-677629',1,1,NULL,3,12,'agent',1,20000.00,0.00,0.00,'percentage',0.0000,15000.00,15000.00,5000.00,20000.00,NULL,'confirmed','paid','2026-08-27 04:45:23','2026-08-27 01:15:23',NULL,NULL,NULL,'2026-08-27 01:15:23','2026-08-27 01:15:23'),
(41,'BK-2026-757733',1,1,NULL,NULL,1,'admin',1,20000.00,0.00,0.00,NULL,0.0000,15000.00,15000.00,5000.00,20000.00,NULL,'confirmed','paid','2026-08-27 04:45:59','2026-08-27 01:15:59',NULL,NULL,NULL,'2026-08-27 01:15:59','2026-08-27 01:15:59'),
(42,'BK-2026-395192',1,1,NULL,NULL,1,'admin',1,20000.00,0.00,0.00,NULL,0.0000,15000.00,15000.00,5000.00,20000.00,NULL,'confirmed','paid','2026-08-27 04:45:59','2026-08-27 01:15:59',NULL,NULL,NULL,'2026-08-27 01:15:59','2026-08-27 01:15:59'),
(43,'BK-2026-364073',1,1,NULL,3,12,'agent',1,20000.00,0.00,0.00,'percentage',0.0000,15000.00,15000.00,5000.00,20000.00,NULL,'confirmed','paid','2026-08-27 04:45:59','2026-08-27 01:15:59',NULL,NULL,NULL,'2026-08-27 01:15:59','2026-08-27 01:15:59');
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bus_seats`
--

DROP TABLE IF EXISTS `bus_seats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bus_seats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bus_id` bigint(20) unsigned NOT NULL,
  `seat_code` varchar(16) NOT NULL,
  `seat_row` smallint(5) unsigned NOT NULL,
  `column_code` varchar(8) NOT NULL,
  `seat_type` enum('regular','vip','female_only','disabled') NOT NULL DEFAULT 'regular',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bus_seat_code` (`bus_id`,`seat_code`),
  CONSTRAINT `fk_bus_seats_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bus_seats`
--

LOCK TABLES `bus_seats` WRITE;
/*!40000 ALTER TABLE `bus_seats` DISABLE KEYS */;
INSERT INTO `bus_seats` VALUES
(1,1,'A1',1,'A','regular',1,'2026-08-26 19:36:29'),
(2,1,'B1',1,'B','regular',1,'2026-08-26 19:36:29'),
(3,1,'C1',1,'C','regular',1,'2026-08-26 19:36:29'),
(4,1,'D1',1,'D','regular',1,'2026-08-26 19:36:29'),
(5,1,'A2',2,'A','regular',1,'2026-08-26 19:36:29'),
(6,1,'B2',2,'B','regular',1,'2026-08-26 19:36:29'),
(7,1,'C2',2,'C','regular',1,'2026-08-26 19:36:29'),
(8,1,'D2',2,'D','regular',1,'2026-08-26 19:36:29'),
(9,1,'A3',3,'A','regular',1,'2026-08-26 19:36:29'),
(10,1,'B3',3,'B','regular',1,'2026-08-26 19:36:29'),
(11,1,'C3',3,'C','regular',1,'2026-08-26 19:36:29'),
(12,1,'D3',3,'D','regular',1,'2026-08-26 19:36:29'),
(13,1,'A4',4,'A','regular',1,'2026-08-26 19:36:29'),
(14,1,'B4',4,'B','regular',1,'2026-08-26 19:36:29'),
(15,1,'C4',4,'C','regular',1,'2026-08-26 19:36:29'),
(16,1,'D4',4,'D','regular',1,'2026-08-26 19:36:29'),
(17,1,'A5',5,'A','regular',1,'2026-08-26 19:36:29'),
(18,1,'B5',5,'B','regular',1,'2026-08-26 19:36:29'),
(19,1,'C5',5,'C','regular',1,'2026-08-26 19:36:29'),
(20,1,'D5',5,'D','regular',1,'2026-08-26 19:36:29'),
(32,2,'V001',1,'A','regular',1,'2026-08-26 22:04:31'),
(33,2,'V002',1,'B','regular',1,'2026-08-26 22:04:31'),
(34,2,'V003',1,'C','regular',1,'2026-08-26 22:04:31'),
(35,2,'V004',1,'D','regular',1,'2026-08-26 22:04:31'),
(36,2,'V005',2,'A','regular',1,'2026-08-26 22:04:31'),
(37,2,'V006',2,'B','regular',1,'2026-08-26 22:04:31'),
(38,2,'V007',2,'C','regular',1,'2026-08-26 22:04:31'),
(39,2,'V008',2,'D','regular',1,'2026-08-26 22:04:31'),
(40,2,'V009',3,'A','regular',1,'2026-08-26 22:04:31'),
(41,2,'V010',3,'B','regular',1,'2026-08-26 22:04:31'),
(42,2,'V011',3,'C','regular',1,'2026-08-26 22:04:31'),
(43,2,'V012',3,'D','regular',1,'2026-08-26 22:04:31'),
(44,2,'V013',4,'A','regular',1,'2026-08-26 22:04:31'),
(45,2,'V014',4,'B','regular',1,'2026-08-26 22:04:31'),
(46,2,'V015',4,'C','regular',1,'2026-08-26 22:04:31'),
(47,2,'V016',4,'D','regular',1,'2026-08-26 22:04:31'),
(48,2,'V017',5,'A','regular',1,'2026-08-26 22:04:31'),
(49,2,'V018',5,'B','regular',1,'2026-08-26 22:04:31'),
(50,2,'V019',5,'C','regular',1,'2026-08-26 22:04:31'),
(51,2,'V020',5,'D','regular',1,'2026-08-26 22:04:31'),
(52,2,'V021',6,'A','regular',1,'2026-08-26 22:04:31'),
(53,2,'V022',6,'B','regular',1,'2026-08-26 22:04:31'),
(54,2,'V023',6,'C','regular',1,'2026-08-26 22:04:31'),
(55,2,'V024',6,'D','regular',1,'2026-08-26 22:04:31'),
(56,2,'V025',7,'A','regular',1,'2026-08-26 22:04:31'),
(57,2,'V026',7,'B','regular',1,'2026-08-26 22:04:31'),
(58,2,'V027',7,'C','regular',1,'2026-08-26 22:04:31'),
(59,2,'V028',7,'D','regular',1,'2026-08-26 22:04:31'),
(60,2,'V029',8,'A','regular',1,'2026-08-26 22:04:31'),
(61,2,'V030',8,'B','regular',1,'2026-08-26 22:04:31'),
(62,2,'V031',8,'C','regular',1,'2026-08-26 22:04:31'),
(63,2,'V032',8,'D','regular',1,'2026-08-26 22:04:31'),
(64,2,'V033',9,'A','regular',1,'2026-08-26 22:04:31'),
(65,2,'V034',9,'B','regular',1,'2026-08-26 22:04:31'),
(66,2,'V035',9,'C','regular',1,'2026-08-26 22:04:31'),
(67,2,'V036',9,'D','regular',1,'2026-08-26 22:04:31'),
(68,2,'V037',10,'A','regular',1,'2026-08-26 22:04:31'),
(69,2,'V038',10,'B','regular',1,'2026-08-26 22:04:31'),
(70,2,'V039',10,'C','regular',1,'2026-08-26 22:04:31'),
(71,2,'V040',10,'D','regular',1,'2026-08-26 22:04:31'),
(72,3,'V001',1,'A','regular',1,'2026-08-26 21:44:42'),
(73,3,'V002',1,'B','regular',1,'2026-08-26 21:44:42');
/*!40000 ALTER TABLE `bus_seats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `buses`
--

DROP TABLE IF EXISTS `buses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `buses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `name_ar` varchar(160) NOT NULL,
  `bus_number` varchar(64) NOT NULL,
  `plate_number` varchar(64) NOT NULL,
  `bus_type` varchar(100) DEFAULT NULL,
  `interior_image_path` varchar(500) DEFAULT NULL,
  `exterior_image_path` varchar(500) DEFAULT NULL,
  `model_year` smallint(5) unsigned DEFAULT NULL,
  `seat_count` smallint(5) unsigned NOT NULL,
  `status` enum('active','maintenance','inactive') NOT NULL DEFAULT 'active',
  `is_virtual` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_buses_company_number` (`company_id`,`bus_number`),
  UNIQUE KEY `uq_buses_company_plate` (`company_id`,`plate_number`),
  CONSTRAINT `fk_buses_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `buses`
--

LOCK TABLES `buses` WRITE;
/*!40000 ALTER TABLE `buses` DISABLE KEYS */;
INSERT INTO `buses` VALUES
(1,1,'باص النور السياحي','NOOR-20','يمن-20-001','VIP','uploads/companies/noor/bus-interior.png','uploads/companies/noor/bus-exterior.png',2024,20,'active',0,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(2,2,'مخزون مقاعد الوسيط','BROKER-2','BROKER-2','broker_seat_pool',NULL,NULL,NULL,40,'active',1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(3,1,'مخزون مقاعد الوسيط','BROKER-1','BROKER-1','broker_seat_pool',NULL,NULL,NULL,2,'active',1,'2026-08-26 21:44:42','2026-08-26 21:44:42');
/*!40000 ALTER TABLE `buses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cities`
--

DROP TABLE IF EXISTS `cities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country_id` bigint(20) unsigned NOT NULL,
  `name_ar` varchar(140) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cities_country_name` (`country_id`,`name_ar`),
  CONSTRAINT `fk_cities_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cities`
--

LOCK TABLES `cities` WRITE;
/*!40000 ALTER TABLE `cities` DISABLE KEYS */;
INSERT INTO `cities` VALUES
(1,1,'صنعاء',1,'2026-08-26 19:36:29'),
(2,1,'تعز',1,'2026-08-26 19:36:29'),
(3,2,'جده',1,'2026-08-26 21:05:57'),
(4,1,'معبر',1,'2026-08-26 21:23:12'),
(5,1,'ذمار',1,'2026-08-26 21:23:23'),
(6,1,'اب',1,'2026-08-26 21:23:34'),
(7,2,'الرياض',1,'2026-08-26 21:39:49'),
(8,4,'مدينة اختبار محدثة',1,'2026-08-26 21:40:09');
/*!40000 ALTER TABLE `cities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legal_name` varchar(180) NOT NULL,
  `trade_name` varchar(180) NOT NULL,
  `logo_path` varchar(500) DEFAULT NULL,
  `cover_image_path` varchar(500) DEFAULT NULL,
  `country_id` bigint(20) unsigned NOT NULL,
  `city_id` bigint(20) unsigned DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `base_currency_id` bigint(20) unsigned NOT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_companies_country_status` (`country_id`,`status`),
  KEY `fk_companies_currency` (`base_currency_id`),
  KEY `fk_companies_city` (`city_id`),
  CONSTRAINT `fk_companies_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_companies_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`),
  CONSTRAINT `fk_companies_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES
(1,'شركة النور اليمنية للنقل والسياحة','شركة النور للنقل','uploads/companies/noor/logo.png','uploads/companies/noor/cover.png',1,1,'صنعاء، الجمهورية اليمنية',NULL,NULL,'+967 1 234 567','support@noor-transport.local',1,'active','2026-08-26 19:36:29','2026-08-26 19:36:29'),
(2,'شركة المصتدر','شركة المتصدار','uploads/companies/2/logo.png','uploads/companies/2/cover.jpg',1,1,NULL,NULL,NULL,NULL,NULL,1,'active','2026-08-26 20:24:10','2026-08-26 20:36:50');
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_financial_settings`
--

DROP TABLE IF EXISTS `company_financial_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_financial_settings` (
  `company_id` bigint(20) unsigned NOT NULL,
  `settlement_cycle_days` smallint(5) unsigned NOT NULL DEFAULT 7,
  `payment_due_days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `credit_limit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `allow_agent_credit` tinyint(1) NOT NULL DEFAULT 0,
  `notes_ar` varchar(500) DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`),
  KEY `fk_company_fin_settings_user` (`updated_by_user_id`),
  CONSTRAINT `fk_company_fin_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_fin_settings_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_financial_settings`
--

LOCK TABLES `company_financial_settings` WRITE;
/*!40000 ALTER TABLE `company_financial_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_financial_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_images`
--

DROP TABLE IF EXISTS `company_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `image_path` varchar(500) NOT NULL,
  `image_order` tinyint(3) unsigned NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_image_order` (`company_id`,`image_order`),
  KEY `idx_company_images_status_order` (`company_id`,`status`,`image_order`),
  CONSTRAINT `fk_company_images_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_images`
--

LOCK TABLES `company_images` WRITE;
/*!40000 ALTER TABLE `company_images` DISABLE KEYS */;
INSERT INTO `company_images` VALUES
(1,1,'uploads/companies/noor/gallery-1.png',1,'active','2026-08-26 19:36:29','2026-08-26 19:36:29'),
(2,1,'uploads/companies/noor/gallery-2.png',2,'active','2026-08-26 19:36:29','2026-08-26 19:36:29'),
(3,2,'uploads/companies/2/gallery/image-1.jpg',1,'active','2026-08-26 20:36:50','2026-08-26 20:36:50'),
(4,2,'uploads/companies/2/gallery/image-2.jpg',2,'active','2026-08-26 20:36:51','2026-08-26 20:36:51'),
(5,2,'uploads/companies/2/gallery/image-3.jpg',3,'active','2026-08-26 20:36:52','2026-08-26 20:36:52'),
(6,2,'uploads/companies/2/gallery/image-4.jpg',4,'active','2026-08-26 20:36:53','2026-08-26 20:36:53'),
(7,2,'uploads/companies/2/gallery/image-5.jpg',5,'active','2026-08-26 20:36:53','2026-08-26 20:36:53'),
(8,2,'uploads/companies/2/gallery/image-6.jpg',6,'active','2026-08-26 20:36:54','2026-08-26 20:36:54');
/*!40000 ALTER TABLE `company_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_settlements`
--

DROP TABLE IF EXISTS `company_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `total_sales` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_commissions` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(14,2) NOT NULL DEFAULT 0.00,
  `outstanding_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `settled_at` datetime DEFAULT NULL,
  `status` enum('draft','settled','cancelled') NOT NULL DEFAULT 'draft',
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_settlements_company` (`company_id`),
  KEY `fk_settlements_agent` (`agent_id`),
  KEY `fk_settlements_currency` (`currency_id`),
  KEY `fk_settlements_user` (`created_by_user_id`),
  CONSTRAINT `fk_settlements_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_settlements_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_settlements_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_settlements_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_settlements`
--

LOCK TABLES `company_settlements` WRITE;
/*!40000 ALTER TABLE `company_settlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_settlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_users`
--

DROP TABLE IF EXISTS `company_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `employee_code` varchar(64) DEFAULT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_user` (`company_id`,`user_id`),
  KEY `fk_company_users_user` (`user_id`),
  CONSTRAINT `fk_company_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_users`
--

LOCK TABLES `company_users` WRITE;
/*!40000 ALTER TABLE `company_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_channels`
--

DROP TABLE IF EXISTS `contact_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_channels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(40) NOT NULL,
  `title_ar` varchar(160) NOT NULL,
  `value` varchar(500) NOT NULL,
  `description_ar` varchar(500) DEFAULT NULL,
  `icon` varchar(80) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contact_channels_status_order` (`status`,`sort_order`,`id`),
  KEY `fk_contact_channels_created_by` (`created_by`),
  KEY `fk_contact_channels_updated_by` (`updated_by`),
  CONSTRAINT `fk_contact_channels_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_contact_channels_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_channels`
--

LOCK TABLES `contact_channels` WRITE;
/*!40000 ALTER TABLE `contact_channels` DISABLE KEYS */;
INSERT INTO `contact_channels` VALUES
(1,'phone','الهاتف','+967 1 234 567','الدعم والحجوزات من 08:00 إلى 20:00','bi-telephone',1,'active',NULL,NULL,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(2,'whatsapp','واتساب','+967 777 123 456','رسائل سريعة لخدمة العملاء','bi-whatsapp',2,'active',NULL,NULL,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(3,'email','البريد الإلكتروني','support@rihla.local','نستقبل استفساراتكم وطلبات الدعم','bi-envelope',3,'active',NULL,NULL,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(4,'location','موقع المكتب','صنعاء، الجمهورية اليمنية','مكتب خدمة العملاء الرئيسي','bi-geo-alt',4,'inactive',NULL,NULL,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(5,'hours','ساعات العمل','يوميًا 08:00 — 20:00','عدا العطلات الرسمية','bi-clock',5,'active',NULL,NULL,'2026-08-26 19:36:29','2026-08-26 19:36:29');
/*!40000 ALTER TABLE `contact_channels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `phone` varchar(40) NOT NULL,
  `email` varchar(190) NOT NULL,
  `subject` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `status` enum('new','in_progress','resolved','failed','cancelled') NOT NULL DEFAULT 'new',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contact_messages_status_created` (`status`,`created_at`),
  KEY `idx_contact_messages_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_messages`
--

LOCK TABLES `contact_messages` WRITE;
/*!40000 ALTER TABLE `contact_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `countries`
--

DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` char(2) NOT NULL,
  `name_ar` varchar(120) NOT NULL,
  `phone_code` varchar(10) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `countries`
--

LOCK TABLES `countries` WRITE;
/*!40000 ALTER TABLE `countries` DISABLE KEYS */;
INSERT INTO `countries` VALUES
(1,'YE','اليمن','+967',1,'2026-08-26 19:36:29'),
(2,'SR','السعودي','+966',1,'2026-08-26 20:43:09'),
(3,'JJ','الامارت','+965',1,'2026-08-26 20:49:01'),
(4,'ZZ','دولة اختبار نهائية','+999',1,'2026-08-26 21:31:00');
/*!40000 ALTER TABLE `countries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` char(3) NOT NULL,
  `name_ar` varchar(120) NOT NULL,
  `symbol_ar` varchar(16) NOT NULL,
  `decimal_places` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
INSERT INTO `currencies` VALUES
(1,'YER','الريال اليمني','ر.ي',0,1,1,'2026-08-26 19:36:29'),
(2,'TST','عملة اختبار محدثة','ت',2,0,1,'2026-08-26 21:46:03');
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `country_id` bigint(20) unsigned NOT NULL,
  `city_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `fk_customers_country` (`country_id`),
  KEY `fk_customers_city` (`city_id`),
  CONSTRAINT `fk_customers_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customers_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`),
  CONSTRAINT `fk_customers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES
(1,2,1,2,'2026-08-26 19:59:26'),
(2,3,1,1,'2026-08-26 20:38:35'),
(3,4,1,1,'2026-08-26 21:24:23'),
(4,5,1,1,'2026-08-26 22:25:28'),
(5,6,1,1,'2026-08-26 22:30:00'),
(6,9,1,1,'2026-08-26 22:15:48'),
(8,11,1,1,'2026-08-26 23:10:33');
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exchange_rates`
--

DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exchange_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `base_currency_id` bigint(20) unsigned NOT NULL,
  `quote_currency_id` bigint(20) unsigned NOT NULL,
  `rate` decimal(18,8) NOT NULL,
  `effective_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exchange_pair_date` (`base_currency_id`,`quote_currency_id`,`effective_at`),
  KEY `fk_exchange_quote_currency` (`quote_currency_id`),
  KEY `fk_exchange_created_by` (`created_by`),
  CONSTRAINT `fk_exchange_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_exchange_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_exchange_quote_currency` FOREIGN KEY (`quote_currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exchange_rates`
--

LOCK TABLES `exchange_rates` WRITE;
/*!40000 ALTER TABLE `exchange_rates` DISABLE KEYS */;
INSERT INTO `exchange_rates` VALUES
(1,1,2,250.00000000,'2026-08-26 18:46:03',NULL,1,1,'2026-08-26 21:46:03');
/*!40000 ALTER TABLE `exchange_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `category_ar` varchar(160) NOT NULL,
  `description_ar` varchar(500) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_expenses_company` (`company_id`),
  KEY `fk_expenses_currency` (`currency_id`),
  KEY `fk_expenses_user` (`recorded_by_user_id`),
  CONSTRAINT `fk_expenses_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_expenses_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_expenses_user` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_email_ip_date` (`email`,`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
INSERT INTO `login_attempts` VALUES
(7,'محمد','127.0.0.1',1,'2026-08-26 19:54:41'),
(8,'محمد','::1',1,'2026-08-26 19:58:09'),
(9,'محمد','::1',1,'2026-08-26 19:58:23'),
(10,'محمد','192.168.0.48',1,'2026-08-26 20:33:50'),
(11,'محمد','::1',1,'2026-08-26 20:37:13'),
(12,'محمد','::1',1,'2026-08-26 20:41:44'),
(13,'محمد','::1',1,'2026-08-26 20:45:03'),
(14,'محمد','::1',1,'2026-08-26 20:49:40'),
(15,'محمد','::1',1,'2026-08-26 21:30:40'),
(16,'محمد','::1',1,'2026-08-26 21:38:04'),
(17,'محمد','192.168.0.48',1,'2026-08-26 22:24:40'),
(18,'e2e.customer.2018192@example.com','::1',0,'2026-08-26 22:26:21'),
(19,'e2e.customer.2018192@example.com','::1',1,'2026-08-26 22:26:22'),
(20,'محمد','::1',1,'2026-08-26 22:26:57'),
(21,'e2e.customer.2018192@example.com','::1',1,'2026-08-26 22:30:34'),
(22,'e2e.customer.2018192@example.com','::1',0,'2026-08-26 22:31:48'),
(23,'محمد','10.158.235.1',1,'2026-08-26 20:40:27'),
(24,'محمد','10.158.235.1',1,'2026-08-26 20:49:37'),
(25,'محمد','10.16.114.1',1,'2026-08-26 21:27:36'),
(26,'محمد','127.0.0.1',1,'2026-08-26 22:28:26'),
(27,'محمد','127.0.0.1',1,'2026-08-26 22:53:10'),
(28,'الغزالي','10.16.114.1',1,'2026-08-27 00:23:27');
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(64) NOT NULL,
  `title_ar` varchar(180) NOT NULL,
  `body_ar` varchar(500) NOT NULL,
  `reference_type` varchar(64) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read` (`user_id`,`read_at`,`created_at`),
  KEY `fk_notifications_company` (`company_id`),
  CONSTRAINT `fk_notifications_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES
(1,5,2,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-001171 وهو بانتظار تأكيد الإدارة.','booking',1,NULL,'2026-08-26 22:29:56'),
(2,1,2,'new_booking','حجز جديد يحتاج المراجعة','وصل طلب حجز جديد رقم BK-2026-001171 ويحتاج إلى المراجعة والتأكيد.','booking',1,'2026-08-26 22:38:46','2026-08-26 22:29:56'),
(7,9,2,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-860382 وهو بانتظار تأكيد الإدارة.','booking',5,'2026-08-26 22:54:53','2026-08-26 22:18:09'),
(8,1,2,'new_booking','حجز جديد يحتاج المراجعة','وصل طلب حجز جديد رقم BK-2026-860382 ويحتاج إلى المراجعة والتأكيد.','booking',5,'2026-08-26 22:33:39','2026-08-26 22:18:09'),
(9,9,2,'ticket_issued','تم إصدار التذاكر','تم إصدار تذاكر الحجز رقم BK-2026-860382 وهي جاهزة للعرض.','booking',5,'2026-08-26 22:54:53','2026-08-26 22:20:53'),
(10,9,2,'booking_confirmed','تم تأكيد الحجز','تم تأكيد حجزك رقم BK-2026-860382 وإصدار التذاكر.','booking',5,'2026-08-26 22:54:53','2026-08-26 22:20:53'),
(14,5,2,'booking_expired','انتهت مهلة الحجز','انتهت مهلة تأكيد الطلب وتم تحرير المقاعد المختارة.','booking',1,NULL,'2026-08-26 23:02:34'),
(15,11,2,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-761472 للعميل محمد محمد حسين الغزالي، والحالة: قيد الانتظار.','booking',31,'2026-08-26 23:12:02','2026-08-26 23:11:51'),
(16,1,2,'new_booking','حجز جديد — محمد محمد حسين الغزالي','العميل: محمد محمد حسين الغزالي · رقم الحجز: BK-2026-761472 · الحالة: قيد الانتظار. افتح التفاصيل للمراجعة والتأكيد.','booking',31,'2026-08-27 01:08:44','2026-08-26 23:11:51'),
(17,11,2,'ticket_issued','تم إصدار التذاكر','تم إصدار تذاكر الحجز رقم BK-2026-761472 وهي جاهزة للعرض.','booking',31,'2026-08-26 23:12:56','2026-08-26 23:12:33'),
(18,11,2,'booking_confirmed','تم تأكيد الحجز','تم تأكيد حجزك رقم BK-2026-761472 وإصدار التذاكر.','booking',31,'2026-08-26 23:12:56','2026-08-26 23:12:33'),
(19,12,1,'wallet_top_up','تمت إضافة رصيد إلى الحساب','تمت إضافة رصيد إلى محفظتك. السبب: تحويل','agent_wallet',4,NULL,'2026-08-27 00:40:40'),
(20,12,1,'wallet_top_up','تمت إضافة رصيد إلى الحساب','تمت إضافة رصيد إلى محفظتك. السبب: تحويل','agent_wallet',4,NULL,'2026-08-27 00:40:41'),
(21,1,1,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-754122 للعميل عميل غير مسمى، والحالة: قيد الانتظار.','booking',35,NULL,'2026-08-27 01:14:39'),
(22,1,1,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-257818 للعميل عميل غير مسمى، والحالة: قيد الانتظار.','booking',36,NULL,'2026-08-27 01:14:39'),
(23,1,1,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-112062 للعميل عميل غير مسمى، والحالة: قيد الانتظار.','booking',38,NULL,'2026-08-27 01:15:23'),
(24,1,1,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-091397 للعميل عميل غير مسمى، والحالة: قيد الانتظار.','booking',39,NULL,'2026-08-27 01:15:23'),
(25,12,1,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-677629 للعميل عميل غير مسمى، والحالة: قيد الانتظار.','booking',40,NULL,'2026-08-27 01:15:23'),
(26,1,1,'new_booking','حجز جديد — عميل غير مسمى','العميل: عميل غير مسمى · رقم الحجز: BK-2026-677629 · الحالة: قيد الانتظار. افتح التفاصيل للمراجعة والتأكيد.','booking',40,'2026-08-27 01:17:24','2026-08-27 01:15:23'),
(27,12,1,'wallet_updated','تم تحديث الحساب المالي','تم خصم قيمة الحجز رقم BK-2026-677629 من حسابك.','booking',40,NULL,'2026-08-27 01:15:23'),
(28,12,1,'payment_received','تم تأكيد استلام الدفع','تم تأكيد استلام دفعة الحجز رقم BK-2026-677629.','booking',40,NULL,'2026-08-27 01:15:23'),
(29,12,1,'ticket_issued','تم إصدار التذاكر','تم إصدار تذاكر الحجز رقم BK-2026-677629 وهي جاهزة للعرض.','booking',40,NULL,'2026-08-27 01:15:23'),
(30,12,1,'booking_confirmed','تم تأكيد الحجز','تم تأكيد حجزك رقم BK-2026-677629 وإصدار التذاكر.','booking',40,NULL,'2026-08-27 01:15:23'),
(61,1,2,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-348089 للعميل عميل غير مسمى، والحالة: قيد الانتظار.','booking',56,NULL,'2026-08-27 02:25:50'),
(62,1,2,'booking_created','تم إرسال طلب الحجز','تم إنشاء طلب الحجز رقم BK-2026-457848 للعميل عميل غير مسمى، والحالة: قيد الانتظار.','booking',57,NULL,'2026-08-27 02:28:39');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_challenges`
--

DROP TABLE IF EXISTS `otp_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_challenges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `challenge_id` char(64) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `purpose` enum('registration','login','phone_change','email_change','test') NOT NULL,
  `channel` enum('whatsapp','sms','email') NOT NULL,
  `destination` varchar(190) NOT NULL,
  `destination_hash` char(64) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `status` enum('sent','verified','expired','failed','locked') NOT NULL DEFAULT 'sent',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `send_count` smallint(5) unsigned NOT NULL DEFAULT 1,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `last_sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `failure_reason` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_otp_challenge_id` (`challenge_id`),
  KEY `idx_otp_destination_created` (`destination_hash`,`created_at`),
  KEY `idx_otp_ip_created` (`ip_address`,`created_at`),
  KEY `idx_otp_status_expires` (`status`,`expires_at`),
  KEY `idx_otp_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_otp_challenges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_challenges`
--

LOCK TABLES `otp_challenges` WRITE;
/*!40000 ALTER TABLE `otp_challenges` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_challenges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_provider_settings`
--

DROP TABLE IF EXISTS `otp_provider_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_provider_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `whatsapp_provider` varchar(80) DEFAULT NULL,
  `whatsapp_api_url` varchar(500) DEFAULT NULL,
  `whatsapp_api_token` text DEFAULT NULL,
  `whatsapp_phone_number_id` varchar(180) DEFAULT NULL,
  `whatsapp_template_name` varchar(180) DEFAULT NULL,
  `whatsapp_language` varchar(40) NOT NULL DEFAULT 'ar',
  `sms_provider` varchar(80) DEFAULT NULL,
  `sms_api_url` varchar(500) DEFAULT NULL,
  `sms_api_key` text DEFAULT NULL,
  `sms_sender_id` varchar(120) DEFAULT NULL,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` smallint(5) unsigned NOT NULL DEFAULT 587,
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` text DEFAULT NULL,
  `smtp_encryption` enum('none','tls','ssl') NOT NULL DEFAULT 'tls',
  `from_email` varchar(190) DEFAULT NULL,
  `from_name` varchar(180) DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_otp_provider_settings_user` (`updated_by_user_id`),
  CONSTRAINT `fk_otp_provider_settings_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_provider_settings`
--

LOCK TABLES `otp_provider_settings` WRITE;
/*!40000 ALTER TABLE `otp_provider_settings` DISABLE KEYS */;
INSERT INTO `otp_provider_settings` VALUES
(1,NULL,NULL,NULL,NULL,NULL,'ar',NULL,NULL,NULL,NULL,NULL,587,NULL,NULL,'tls',NULL,NULL,NULL,'2026-08-27 02:13:35');
/*!40000 ALTER TABLE `otp_provider_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_registration_payloads`
--

DROP TABLE IF EXISTS `otp_registration_payloads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_registration_payloads` (
  `challenge_id` char(64) NOT NULL,
  `full_name` varchar(180) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(32) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `country_id` bigint(20) unsigned NOT NULL,
  `city_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`challenge_id`),
  KEY `fk_otp_registration_country` (`country_id`),
  KEY `fk_otp_registration_city` (`city_id`),
  CONSTRAINT `fk_otp_registration_challenge` FOREIGN KEY (`challenge_id`) REFERENCES `otp_challenges` (`challenge_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_otp_registration_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_otp_registration_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_registration_payloads`
--

LOCK TABLES `otp_registration_payloads` WRITE;
/*!40000 ALTER TABLE `otp_registration_payloads` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_registration_payloads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_settings`
--

DROP TABLE IF EXISTS `otp_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `code_length` tinyint(3) unsigned NOT NULL DEFAULT 6,
  `ttl_minutes` smallint(5) unsigned NOT NULL DEFAULT 5,
  `resend_after_seconds` smallint(5) unsigned NOT NULL DEFAULT 60,
  `max_attempts` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `max_sends_per_hour` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `max_sends_per_day` smallint(5) unsigned NOT NULL DEFAULT 20,
  `whatsapp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `sms_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_otp_settings_user` (`updated_by_user_id`),
  CONSTRAINT `fk_otp_settings_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_settings`
--

LOCK TABLES `otp_settings` WRITE;
/*!40000 ALTER TABLE `otp_settings` DISABLE KEYS */;
INSERT INTO `otp_settings` VALUES
(1,0,6,5,60,5,5,20,0,0,1,NULL,'2026-08-27 02:18:51');
/*!40000 ALTER TABLE `otp_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passengers`
--

DROP TABLE IF EXISTS `passengers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `passengers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `full_name_ar` varchar(220) NOT NULL,
  `gender` enum('male','female') DEFAULT NULL,
  `phone_country_code` varchar(10) NOT NULL,
  `phone` varchar(32) NOT NULL,
  `passport_number` varchar(64) NOT NULL,
  `birth_date` date NOT NULL,
  `birth_place` varchar(180) NOT NULL,
  `passport_issue_date` date NOT NULL,
  `passport_issue_place` varchar(180) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_passengers_passport` (`passport_number`),
  KEY `fk_passengers_customer` (`customer_id`),
  CONSTRAINT `fk_passengers_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passengers`
--

LOCK TABLES `passengers` WRITE;
/*!40000 ALTER TABLE `passengers` DISABLE KEYS */;
INSERT INTO `passengers` VALUES
(1,4,'عميل اختبار دورة كاملة محدث',NULL,'+967','7782018192','E2E2018192','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-26 22:29:56','2026-08-26 22:29:56'),
(4,6,'اصيله محمد حسين الغزالي','female','+967','737987541','92567360','2026-08-27','معبر','2026-08-27','عدن','2026-08-26 22:18:09','2026-08-26 22:18:09'),
(5,8,'صلاح','female','+967','770105284','7382727','2026-08-27','ذمار','2026-08-27','عدن','2026-08-26 23:11:51','2026-08-26 23:11:51'),
(6,NULL,'مسافر اختبار الدفع 1','male','+967','700000001','TESTPAY2608270414391','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-27 01:14:39','2026-08-27 01:14:39'),
(7,NULL,'مسافر اختبار الدفع 3','male','+967','700000003','TESTPAY2608270414393','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-27 01:14:39','2026-08-27 01:14:39'),
(8,NULL,'مسافر اختبار الدفع 1','male','+967','700000001','TESTPAY2608270415231','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-27 01:15:23','2026-08-27 01:15:23'),
(9,NULL,'مسافر اختبار الدفع 3','male','+967','700000003','TESTPAY2608270415233','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-27 01:15:23','2026-08-27 01:15:23'),
(10,NULL,'مسافر اختبار الدفع 5','male','+967','700000005','TESTPAY2608270415235','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-27 01:15:23','2026-08-27 01:15:23'),
(11,NULL,'مسافر اختبار الدفع 1','male','+967','700000001','TESTPAY2608270415591','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-27 01:15:59','2026-08-27 01:15:59'),
(12,NULL,'مسافر اختبار الدفع 3','male','+967','700000003','TESTPAY2608270415593','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-27 01:15:59','2026-08-27 01:15:59'),
(13,NULL,'مسافر اختبار الدفع 5','male','+967','700000005','TESTPAY2608270415595','1990-01-01','صنعاء','2020-01-01','صنعاء','2026-08-27 01:15:59','2026-08-27 01:15:59');
/*!40000 ALTER TABLE `passengers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','wallet','card','other') NOT NULL,
  `payment_channel` varchar(32) DEFAULT NULL,
  `bank_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `reference_number` varchar(128) DEFAULT NULL,
  `receipt_image_path` varchar(500) DEFAULT NULL,
  `received_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_payments_booking` (`booking_id`),
  KEY `fk_payments_currency` (`currency_id`),
  KEY `fk_payments_receiver` (`received_by_user_id`),
  CONSTRAINT `fk_payments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_payments_receiver` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES
(1,35,1,20000.00,'cash','company',NULL,'completed','TEST-COMPANY',NULL,1,'2026-08-27 01:14:39'),
(2,36,1,20000.00,'bank_transfer','bank_transfer',2,'completed','TEST-BANK_TRANSFER',NULL,1,'2026-08-27 01:14:39'),
(3,38,1,20000.00,'cash','company',NULL,'completed','TEST-COMPANY',NULL,1,'2026-08-27 01:15:23'),
(4,39,1,20000.00,'bank_transfer','bank_transfer',3,'completed','TEST-BANK_TRANSFER',NULL,1,'2026-08-27 01:15:23'),
(5,40,1,20000.00,'cash','agent',NULL,'completed','TEST-AGENT',NULL,1,'2026-08-27 01:15:23'),
(6,41,1,20000.00,'cash','company',NULL,'completed','TEST-COMPANY',NULL,1,'2026-08-27 01:15:59'),
(7,42,1,20000.00,'bank_transfer','bank_transfer',4,'completed','TEST-BANK_TRANSFER',NULL,1,'2026-08-27 01:15:59'),
(8,43,1,20000.00,'cash','agent',NULL,'completed','TEST-AGENT',NULL,1,'2026-08-27 01:15:59');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `name_ar` varchar(160) NOT NULL,
  `module_code` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=22763 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES
(1,'create_booking','إنشاء حجز','bookings','2026-08-26 19:36:29'),
(2,'manage_roles_permissions','إدارة الأدوار والصلاحيات','users','2026-08-27 00:12:19'),
(3,'manage_users','إدارة المستخدمين','users','2026-08-27 00:12:19'),
(4,'view_all_bookings','عرض جميع الحجوزات','bookings','2026-08-27 00:12:19'),
(5,'view_company_bookings','عرض حجوزات الشركة','bookings','2026-08-27 00:12:19'),
(7,'confirm_booking','تأكيد الحجز وإصدار التذاكر','bookings','2026-08-27 00:12:19'),
(8,'reject_booking','رفض أو إلغاء الحجز بسبب','bookings','2026-08-27 00:12:19'),
(9,'manage_payments','إدارة الدفع والمبالغ الداخلية','finance','2026-08-27 00:12:19'),
(10,'view_financial_reports','عرض التقارير المالية','finance','2026-08-27 00:12:19'),
(11,'view_reports','عرض التقارير التشغيلية','reports','2026-08-27 00:12:19'),
(12,'manage_trips','إدارة الرحلات','trips','2026-08-27 00:12:19'),
(13,'manage_routes','إدارة المسارات','routes','2026-08-27 00:12:19'),
(14,'manage_companies','إدارة الشركات','companies','2026-08-27 00:12:19'),
(15,'manage_agents','إدارة الوكلاء','agents','2026-08-27 00:12:19'),
(16,'manage_buses','إدارة الباصات','buses','2026-08-27 00:12:19'),
(17,'manage_countries','إدارة الدول والمدن','references','2026-08-27 00:12:19'),
(18,'manage_settings','إدارة الإعدادات والبيانات المرجعية','settings','2026-08-27 00:12:19'),
(8119,'receive_payment','تأكيد استلام الدفع وتسجيل الحركة المالية','finance','2026-08-27 00:57:51');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refunds`
--

DROP TABLE IF EXISTS `refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `refunds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `reason_ar` varchar(500) NOT NULL,
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_refunds_payment` (`payment_id`),
  KEY `fk_refunds_currency` (`currency_id`),
  KEY `fk_refunds_approver` (`approved_by_user_id`),
  CONSTRAINT `fk_refunds_approver` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_refunds_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_refunds_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refunds`
--

LOCK TABLES `refunds` WRITE;
/*!40000 ALTER TABLE `refunds` DISABLE KEYS */;
/*!40000 ALTER TABLE `refunds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES
(1,1),
(2,1),
(4,1),
(4,5),
(4,7),
(4,8),
(4,11),
(4,12),
(4,13),
(4,15),
(5,1),
(5,5),
(5,7),
(5,8),
(6,5),
(6,9),
(6,10),
(6,8119),
(7,5),
(7,11);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name_ar` varchar(120) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5168 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'customer','عميل',0,'2026-08-26 19:36:29'),
(2,'agent','وكيل',1,'2026-08-26 19:36:29'),
(3,'super_admin','مدير النظام',1,'2026-08-26 19:52:00'),
(4,'company_admin','مدير شركة',1,'2026-08-27 00:12:19'),
(5,'booking_officer','موظف حجوزات',1,'2026-08-27 00:12:19'),
(6,'accountant','محاسب',1,'2026-08-27 00:12:19'),
(7,'support','موظف دعم',1,'2026-08-27 00:12:19');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `route_segments`
--

DROP TABLE IF EXISTS `route_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `route_segments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `route_id` bigint(20) unsigned NOT NULL,
  `origin_stop_id` bigint(20) unsigned NOT NULL,
  `destination_stop_id` bigint(20) unsigned NOT NULL,
  `origin_order` smallint(5) unsigned NOT NULL,
  `destination_order` smallint(5) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_route_segment` (`route_id`,`origin_stop_id`,`destination_stop_id`),
  KEY `idx_segment_search` (`route_id`,`origin_order`,`destination_order`),
  KEY `fk_segments_origin` (`origin_stop_id`),
  KEY `fk_segments_destination` (`destination_stop_id`),
  CONSTRAINT `fk_segments_destination` FOREIGN KEY (`destination_stop_id`) REFERENCES `route_stops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_segments_origin` FOREIGN KEY (`origin_stop_id`) REFERENCES `route_stops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_segments_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `route_segments`
--

LOCK TABLES `route_segments` WRITE;
/*!40000 ALTER TABLE `route_segments` DISABLE KEYS */;
INSERT INTO `route_segments` VALUES
(1,1,1,2,1,2,1,'2026-08-26 19:36:29'),
(2,7,3,5,1,3,1,'2026-08-26 22:02:29'),
(3,7,4,5,2,3,1,'2026-08-26 22:02:29');
/*!40000 ALTER TABLE `route_segments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `route_stops`
--

DROP TABLE IF EXISTS `route_stops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `route_stops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `route_id` bigint(20) unsigned NOT NULL,
  `station_id` bigint(20) unsigned NOT NULL,
  `stop_order` smallint(5) unsigned NOT NULL,
  `arrival_offset_minutes` int(11) DEFAULT NULL,
  `departure_offset_minutes` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_route_stop_order` (`route_id`,`stop_order`),
  UNIQUE KEY `uq_route_station` (`route_id`,`station_id`),
  KEY `fk_route_stops_station` (`station_id`),
  CONSTRAINT `fk_route_stops_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_route_stops_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `route_stops`
--

LOCK TABLES `route_stops` WRITE;
/*!40000 ALTER TABLE `route_stops` DISABLE KEYS */;
INSERT INTO `route_stops` VALUES
(1,1,1,1,0,0,'2026-08-26 19:36:29'),
(2,1,2,2,480,480,'2026-08-26 19:36:29'),
(3,7,1,1,0,0,'2026-08-26 22:02:29'),
(4,7,3,2,0,61,'2026-08-26 22:02:29'),
(5,7,4,3,31,0,'2026-08-26 22:02:29');
/*!40000 ALTER TABLE `route_stops` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `route_subroute_links`
--

DROP TABLE IF EXISTS `route_subroute_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `route_subroute_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `route_id` bigint(20) unsigned NOT NULL,
  `subroute_id` bigint(20) unsigned NOT NULL,
  `route_segment_id` bigint(20) unsigned NOT NULL,
  `stop_order` smallint(5) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_route_subroute` (`route_id`,`subroute_id`),
  UNIQUE KEY `uq_route_subroute_order` (`route_id`,`stop_order`),
  KEY `fk_route_subroute_links_subroute` (`subroute_id`),
  KEY `fk_route_subroute_links_segment` (`route_segment_id`),
  CONSTRAINT `fk_route_subroute_links_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_route_subroute_links_segment` FOREIGN KEY (`route_segment_id`) REFERENCES `route_segments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_route_subroute_links_subroute` FOREIGN KEY (`subroute_id`) REFERENCES `route_subroutes` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `route_subroute_links`
--

LOCK TABLES `route_subroute_links` WRITE;
/*!40000 ALTER TABLE `route_subroute_links` DISABLE KEYS */;
INSERT INTO `route_subroute_links` VALUES
(1,1,1,1,1,'2026-08-26 19:36:29'),
(2,7,2,2,1,'2026-08-26 22:02:29'),
(3,7,3,3,2,'2026-08-26 22:02:29');
/*!40000 ALTER TABLE `route_subroute_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `route_subroutes`
--

DROP TABLE IF EXISTS `route_subroutes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `route_subroutes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `origin_city_id` bigint(20) unsigned NOT NULL,
  `destination_city_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `company_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(14,2) NOT NULL,
  `origin_arrival_time` time DEFAULT NULL,
  `origin_departure_time` time DEFAULT NULL,
  `destination_arrival_time` time DEFAULT NULL,
  `destination_departure_time` time DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_subroutes_company_status` (`company_id`,`status`),
  KEY `idx_subroutes_cities` (`origin_city_id`,`destination_city_id`),
  KEY `fk_subroutes_destination_city` (`destination_city_id`),
  KEY `fk_subroutes_currency` (`currency_id`),
  KEY `fk_subroutes_created_by` (`created_by`),
  CONSTRAINT `fk_subroutes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_subroutes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_subroutes_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_subroutes_destination_city` FOREIGN KEY (`destination_city_id`) REFERENCES `cities` (`id`),
  CONSTRAINT `fk_subroutes_origin_city` FOREIGN KEY (`origin_city_id`) REFERENCES `cities` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `route_subroutes`
--

LOCK TABLES `route_subroutes` WRITE;
/*!40000 ALTER TABLE `route_subroutes` DISABLE KEYS */;
INSERT INTO `route_subroutes` VALUES
(1,1,1,2,1,15000.00,20000.00,'07:30:00','08:00:00','16:00:00','16:15:00','active',NULL,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(2,NULL,1,3,1,40000.00,50000.00,NULL,'22:14:00','21:44:00',NULL,'active',1,'2026-08-26 21:44:23','2026-08-26 21:44:23'),
(3,NULL,4,3,1,40000.00,45000.00,NULL,'23:15:00','22:45:00',NULL,'active',1,'2026-08-26 21:45:13','2026-08-26 21:45:13');
/*!40000 ALTER TABLE `route_subroutes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `routes`
--

DROP TABLE IF EXISTS `routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `routes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(64) NOT NULL,
  `name_ar` varchar(220) NOT NULL,
  `route_type` enum('normal','tourist') NOT NULL DEFAULT 'normal',
  `journey_type` enum('direct','indirect') NOT NULL DEFAULT 'direct',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_routes_company_code` (`company_id`,`code`),
  KEY `fk_routes_created_by` (`created_by`),
  CONSTRAINT `fk_routes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_routes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `routes`
--

LOCK TABLES `routes` WRITE;
/*!40000 ALTER TABLE `routes` DISABLE KEYS */;
INSERT INTO `routes` VALUES
(1,1,'Sanaa-Taiz','صنعاء ← تعز','normal','direct','active',NULL,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(7,2,'RT-000007','صنعاء - جده','normal','indirect','active',1,'2026-08-26 22:02:29','2026-08-26 22:03:43');
/*!40000 ALTER TABLE `routes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `segment_prices`
--

DROP TABLE IF EXISTS `segment_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `segment_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `route_segment_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_segment_prices_lookup` (`company_id`,`route_segment_id`,`currency_id`,`starts_at`,`ends_at`),
  KEY `fk_segment_prices_segment` (`route_segment_id`),
  KEY `fk_segment_prices_currency` (`currency_id`),
  KEY `fk_segment_prices_creator` (`created_by`),
  CONSTRAINT `fk_segment_prices_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_segment_prices_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_segment_prices_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_segment_prices_segment` FOREIGN KEY (`route_segment_id`) REFERENCES `route_segments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `segment_prices`
--

LOCK TABLES `segment_prices` WRITE;
/*!40000 ALTER TABLE `segment_prices` DISABLE KEYS */;
INSERT INTO `segment_prices` VALUES
(1,1,1,1,20000.00,'2026-01-01 00:00:00',NULL,'active',NULL,'2026-08-26 19:36:29'),
(2,2,2,1,50000.00,'2026-08-26 22:02:29',NULL,'active',1,'2026-08-26 22:02:29'),
(3,2,3,1,45000.00,'2026-08-26 22:02:29',NULL,'active',1,'2026-08-26 22:02:29');
/*!40000 ALTER TABLE `segment_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `site_settings`
--

DROP TABLE IF EXISTS `site_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `site_name_ar` varchar(180) NOT NULL,
  `tagline_ar` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `icon_path` varchar(255) DEFAULT NULL,
  `footer_text_ar` varchar(255) NOT NULL,
  `password_policy` enum('normal','medium','complex') NOT NULL DEFAULT 'medium',
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_settings`
--

LOCK TABLES `site_settings` WRITE;
/*!40000 ALTER TABLE `site_settings` DISABLE KEYS */;
INSERT INTO `site_settings` VALUES
(1,'منصة رحلتي','احجز رحلتك بسهولة وأمان','uploads/site/logo.png','uploads/site/icon.png','© 2026 منصة رحلة','medium',1,'2026-08-27 00:29:39');
/*!40000 ALTER TABLE `site_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stations`
--

DROP TABLE IF EXISTS `stations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `city_id` bigint(20) unsigned NOT NULL,
  `name_ar` varchar(180) NOT NULL,
  `address` varchar(400) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `station_type` enum('company','agent') NOT NULL DEFAULT 'company',
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stations_city_name` (`city_id`,`name_ar`),
  CONSTRAINT `fk_stations_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stations`
--

LOCK TABLES `stations` WRITE;
/*!40000 ALTER TABLE `stations` DISABLE KEYS */;
INSERT INTO `stations` VALUES
(1,1,'محطة صنعاء المركزية','شارع الستين، صنعاء',NULL,NULL,'company',NULL,NULL,1,'2026-08-26 19:36:29'),
(2,2,'محطة تعز الرئيسية','شارع جمال، تعز',NULL,NULL,'company',NULL,NULL,1,'2026-08-26 19:36:29'),
(3,4,'محطة معبر',NULL,NULL,NULL,'company',NULL,NULL,1,'2026-08-26 22:02:29'),
(4,3,'محطة جده',NULL,NULL,NULL,'company',NULL,NULL,1,'2026-08-26 22:02:29'),
(5,4,'الغزالي معبر','الغزالي معبر',NULL,NULL,'company',2,NULL,1,'2026-08-27 00:14:51');
/*!40000 ALTER TABLE `stations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_number` varchar(32) NOT NULL,
  `booking_id` bigint(20) unsigned NOT NULL,
  `booking_passenger_id` bigint(20) unsigned NOT NULL,
  `booking_seat_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `status` enum('active','used','cancelled','void') NOT NULL DEFAULT 'active',
  `qr_token` char(64) NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_number` (`ticket_number`),
  UNIQUE KEY `qr_token` (`qr_token`),
  KEY `fk_tickets_booking` (`booking_id`),
  KEY `fk_tickets_passenger` (`booking_passenger_id`),
  KEY `fk_tickets_seat` (`booking_seat_id`),
  KEY `fk_tickets_currency` (`currency_id`),
  CONSTRAINT `fk_tickets_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tickets_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_tickets_passenger` FOREIGN KEY (`booking_passenger_id`) REFERENCES `booking_passengers` (`id`),
  CONSTRAINT `fk_tickets_seat` FOREIGN KEY (`booking_seat_id`) REFERENCES `booking_seats` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
INSERT INTO `tickets` VALUES
(1,'TK-2026-773363',5,4,4,1,50000.00,'active','aaf98fe51d110c88a45d1e2944e5c3f0b44a6f730481f709a2650dddfc0b25c0','2026-08-26 22:20:53'),
(2,'TK-2026-749570',31,5,5,1,60000.00,'active','102a2d3b6004573569ddd365974b7241b0fad36fd9bee560e17e14993ed70ee3','2026-08-26 23:12:33'),
(3,'TK-2026-721814',35,6,6,1,20000.00,'active','471c761ee6b5c125c28c12be32bdcaa55afd73866592844e2b27699e624e66ea','2026-08-27 01:14:39'),
(4,'TK-2026-799030',36,7,7,1,20000.00,'active','9a05dcf53d9cb9aaa11fc94fa3b96b1a677c0fed13aad7575279e4b6c12b250a','2026-08-27 01:14:39'),
(5,'TK-2026-196981',38,8,8,1,20000.00,'active','5a7b0b8b52bc68039b4a2cbf33fc92e4d9e109da47d1ca52f8449794c8eb9ffd','2026-08-27 01:15:23'),
(6,'TK-2026-874397',39,9,9,1,20000.00,'active','0c3456040230a4499c26f1c58c9d5317443abf8809e429939ad70103b820ba82','2026-08-27 01:15:23'),
(7,'TK-2026-382486',40,10,10,1,20000.00,'active','52232969dbf27c475b9e25cf44bebbede96e535d17552dcfe75d268e9b866be5','2026-08-27 01:15:23'),
(8,'TK-2026-218310',41,11,11,1,20000.00,'active','dfa63288cb367d9a771025e0b49c1623d4c98f81e099a91b2d19df38b310ed16','2026-08-27 01:15:59'),
(9,'TK-2026-481111',42,12,12,1,20000.00,'active','764bcf529053e7218bf1f50792741afd4b960ffe7c8ca5b076be515ed9e635f5','2026-08-27 01:15:59'),
(10,'TK-2026-150197',43,13,13,1,20000.00,'active','e58d42559900f58a92ec277e5f38ac81351e4f5f5158a0177a6d130f28b28b67','2026-08-27 01:15:59');
/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trip_display_settings`
--

DROP TABLE IF EXISTS `trip_display_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trip_display_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `show_company_cost` tinyint(1) NOT NULL DEFAULT 0,
  `show_available_seats` tinyint(1) NOT NULL DEFAULT 1,
  `show_bookings_button` tinyint(1) NOT NULL DEFAULT 1,
  `show_agent_commission` tinyint(1) NOT NULL DEFAULT 1,
  `show_price_change_badge` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `allow_agent_payment` tinyint(1) NOT NULL DEFAULT 1,
  `allow_company_payment` tinyint(1) NOT NULL DEFAULT 1,
  `allow_bank_transfer` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trip_display_settings`
--

LOCK TABLES `trip_display_settings` WRITE;
/*!40000 ALTER TABLE `trip_display_settings` DISABLE KEYS */;
INSERT INTO `trip_display_settings` VALUES
(1,0,1,1,1,0,1,'2026-08-26 21:56:15',1,1,1);
/*!40000 ALTER TABLE `trip_display_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trip_price_change_logs`
--

DROP TABLE IF EXISTS `trip_price_change_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trip_price_change_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint(20) unsigned NOT NULL,
  `route_segment_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `previous_amount` decimal(14,2) NOT NULL,
  `current_amount` decimal(14,2) NOT NULL,
  `change_type` enum('increase','decrease') NOT NULL,
  `changed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_trip_price_change_trip_segment` (`trip_id`,`route_segment_id`,`created_at`),
  KEY `fk_trip_price_change_segment` (`route_segment_id`),
  KEY `fk_trip_price_change_currency` (`currency_id`),
  KEY `fk_trip_price_change_user` (`changed_by`),
  CONSTRAINT `fk_trip_price_change_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_trip_price_change_segment` FOREIGN KEY (`route_segment_id`) REFERENCES `route_segments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trip_price_change_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trip_price_change_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trip_price_change_logs`
--

LOCK TABLES `trip_price_change_logs` WRITE;
/*!40000 ALTER TABLE `trip_price_change_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `trip_price_change_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trip_reviews`
--

DROP TABLE IF EXISTS `trip_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trip_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint(20) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `company_rating` tinyint(3) unsigned DEFAULT NULL,
  `agent_rating` tinyint(3) unsigned DEFAULT NULL,
  `recommendation` tinyint(1) NOT NULL DEFAULT 1,
  `comment` varchar(1000) DEFAULT NULL,
  `status` enum('published','hidden') NOT NULL DEFAULT 'published',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_reviews_booking` (`booking_id`),
  KEY `idx_trip_reviews_trip_status` (`trip_id`,`status`),
  KEY `fk_trip_reviews_customer` (`customer_id`),
  CONSTRAINT `fk_trip_reviews_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trip_reviews_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trip_reviews_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trip_reviews`
--

LOCK TABLES `trip_reviews` WRITE;
/*!40000 ALTER TABLE `trip_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `trip_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trip_seat_inventory`
--

DROP TABLE IF EXISTS `trip_seat_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trip_seat_inventory` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint(20) unsigned NOT NULL,
  `bus_seat_id` bigint(20) unsigned NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_inventory_seat` (`trip_id`,`bus_seat_id`),
  KEY `fk_trip_inventory_seat` (`bus_seat_id`),
  CONSTRAINT `fk_trip_inventory_seat` FOREIGN KEY (`bus_seat_id`) REFERENCES `bus_seats` (`id`),
  CONSTRAINT `fk_trip_inventory_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=167 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trip_seat_inventory`
--

LOCK TABLES `trip_seat_inventory` WRITE;
/*!40000 ALTER TABLE `trip_seat_inventory` DISABLE KEYS */;
INSERT INTO `trip_seat_inventory` VALUES
(1,1,1,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(2,1,2,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(3,1,3,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(4,1,4,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(5,1,5,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(6,1,6,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(7,1,7,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(8,1,8,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(9,1,9,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(10,1,10,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(11,1,11,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(12,1,12,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(13,1,13,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(14,1,14,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(15,1,15,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(16,1,16,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(17,1,17,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(18,1,18,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(19,1,19,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(20,1,20,1,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(32,2,32,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(33,2,33,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(34,2,34,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(35,2,35,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(36,2,36,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(37,2,37,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(38,2,38,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(39,2,39,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(40,2,40,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(41,2,41,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(42,2,42,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(43,2,43,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(44,2,44,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(45,2,45,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(46,2,46,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(47,2,47,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(48,2,48,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(49,2,49,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(50,2,50,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(51,2,51,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(52,2,52,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(53,2,53,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(54,2,54,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(55,2,55,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(56,2,56,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(57,2,57,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(58,2,58,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(59,2,59,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(60,2,60,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(61,2,61,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(62,2,62,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(63,2,63,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(64,2,64,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(65,2,65,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(66,2,66,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(67,2,67,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(68,2,68,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(69,2,69,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(70,2,70,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(71,2,71,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(95,3,32,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(96,3,33,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(97,3,34,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(98,3,35,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(99,3,36,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(100,3,37,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(101,3,38,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(102,3,39,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(103,3,40,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(104,3,41,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(105,3,42,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(106,3,43,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(107,3,44,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(108,3,45,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(109,3,46,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(110,3,47,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(111,3,48,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(112,3,49,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(113,3,50,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(114,3,51,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(115,3,52,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(116,3,53,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(117,3,54,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(118,3,55,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(119,3,56,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(120,3,57,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(121,3,58,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(122,3,59,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(123,3,60,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(124,3,61,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(125,3,62,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(126,3,63,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(127,3,64,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(128,3,65,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(129,3,66,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(130,3,67,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(131,3,68,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(132,3,69,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(133,3,70,1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(134,3,71,1,'2026-08-26 22:04:31','2026-08-26 22:04:31');
/*!40000 ALTER TABLE `trip_seat_inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trip_segment_prices`
--

DROP TABLE IF EXISTS `trip_segment_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trip_segment_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint(20) unsigned NOT NULL,
  `route_segment_id` bigint(20) unsigned NOT NULL,
  `currency_id` bigint(20) unsigned NOT NULL,
  `company_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(14,2) NOT NULL,
  `source_price_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_segment_price` (`trip_id`,`route_segment_id`,`currency_id`),
  KEY `fk_trip_segment_prices_segment` (`route_segment_id`),
  KEY `fk_trip_segment_prices_currency` (`currency_id`),
  KEY `fk_trip_segment_prices_source` (`source_price_id`),
  CONSTRAINT `fk_trip_segment_prices_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_trip_segment_prices_segment` FOREIGN KEY (`route_segment_id`) REFERENCES `route_segments` (`id`),
  CONSTRAINT `fk_trip_segment_prices_source` FOREIGN KEY (`source_price_id`) REFERENCES `segment_prices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trip_segment_prices_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trip_segment_prices`
--

LOCK TABLES `trip_segment_prices` WRITE;
/*!40000 ALTER TABLE `trip_segment_prices` DISABLE KEYS */;
INSERT INTO `trip_segment_prices` VALUES
(1,1,1,1,15000.00,20000.00,1,'2026-08-26 19:36:29'),
(2,2,2,1,40000.00,50000.00,2,'2026-08-26 22:04:31'),
(3,2,3,1,40000.00,45000.00,3,'2026-08-26 22:04:31'),
(5,3,2,1,40000.00,60000.00,2,'2026-08-26 22:04:31'),
(6,3,3,1,40000.00,50000.00,3,'2026-08-26 22:04:31');
/*!40000 ALTER TABLE `trip_segment_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trips`
--

DROP TABLE IF EXISTS `trips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trips` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `route_id` bigint(20) unsigned NOT NULL,
  `route_subroute_id` bigint(20) unsigned DEFAULT NULL,
  `bus_id` bigint(20) unsigned NOT NULL,
  `trip_number` varchar(64) NOT NULL,
  `trip_type` varchar(30) NOT NULL DEFAULT 'local',
  `bus_type` varchar(100) DEFAULT NULL,
  `agent_commission_type` enum('percentage','fixed') DEFAULT NULL,
  `agent_commission_value` decimal(12,4) DEFAULT NULL,
  `seat_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `recurrence_group` varchar(64) DEFAULT NULL,
  `recurrence_index` smallint(5) unsigned DEFAULT NULL,
  `departure_at` datetime NOT NULL,
  `arrival_at` datetime NOT NULL,
  `booking_open_at` datetime DEFAULT NULL,
  `booking_close_at` datetime DEFAULT NULL,
  `status` enum('scheduled','open','boarding','completed','cancelled','expired') NOT NULL DEFAULT 'scheduled',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trips_company_number_date` (`company_id`,`trip_number`,`departure_at`),
  KEY `idx_trips_search` (`company_id`,`route_id`,`status`,`departure_at`),
  KEY `idx_trips_route_subroute` (`route_subroute_id`),
  KEY `idx_trips_recurrence_group` (`recurrence_group`,`departure_at`),
  KEY `fk_trips_route` (`route_id`),
  KEY `fk_trips_bus` (`bus_id`),
  KEY `fk_trips_creator` (`created_by`),
  CONSTRAINT `fk_trips_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`),
  CONSTRAINT `fk_trips_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trips_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trips_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`),
  CONSTRAINT `fk_trips_subroute` FOREIGN KEY (`route_subroute_id`) REFERENCES `route_subroutes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trips`
--

LOCK TABLES `trips` WRITE;
/*!40000 ALTER TABLE `trips` DISABLE KEYS */;
INSERT INTO `trips` VALUES
(1,1,1,1,1,'NO-1001','local',NULL,NULL,NULL,0,NULL,NULL,'2026-08-27 08:00:00','2026-08-27 16:00:00','2026-08-26 19:36:29','2026-08-27 07:30:00','open',NULL,'2026-08-26 19:36:29','2026-08-26 19:36:29'),
(2,2,7,2,2,'TR-000001','international','VIP',NULL,NULL,40,'RG-5A24659939F9',1,'2026-08-26 22:14:00','2026-08-27 21:44:00','2026-08-26 22:04:31','2026-08-26 21:44:00','open',1,'2026-08-26 22:04:31','2026-08-26 22:04:31'),
(3,2,7,2,2,'TR-000002','international','VIP',NULL,NULL,40,'RG-5A24659939F9',2,'2026-08-27 22:14:00','2026-08-28 21:44:00','2026-08-26 22:04:31','2026-08-27 21:44:00','open',1,'2026-08-26 22:04:31','2026-08-26 22:04:31');
/*!40000 ALTER TABLE `trips` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_devices`
--

DROP TABLE IF EXISTS `user_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_identifier` char(64) NOT NULL,
  `device_type` varchar(50) DEFAULT NULL,
  `operating_system` varchar(100) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `last_ip_address` varchar(45) DEFAULT NULL,
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_device` (`user_id`,`device_identifier`),
  CONSTRAINT `fk_user_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_devices`
--

LOCK TABLES `user_devices` WRITE;
/*!40000 ALTER TABLE `user_devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_roles_scope` (`user_id`,`role_id`,`company_id`),
  KEY `fk_user_roles_role` (`role_id`),
  KEY `fk_user_roles_company` (`company_id`),
  CONSTRAINT `fk_user_roles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_roles`
--

LOCK TABLES `user_roles` WRITE;
/*!40000 ALTER TABLE `user_roles` DISABLE KEYS */;
INSERT INTO `user_roles` VALUES
(1,1,3,NULL,'2026-08-26 19:52:00'),
(2,2,1,NULL,'2026-08-26 19:59:26'),
(3,3,1,NULL,'2026-08-26 20:38:35'),
(4,4,1,NULL,'2026-08-26 21:24:23'),
(5,5,1,NULL,'2026-08-26 22:25:28'),
(6,6,1,NULL,'2026-08-26 22:30:00'),
(7,9,1,NULL,'2026-08-26 22:15:48'),
(9,11,1,NULL,'2026-08-26 23:10:33'),
(10,12,2,1,'2026-08-27 00:13:35');
/*!40000 ALTER TABLE `user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(180) NOT NULL,
  `username` varchar(80) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `profile_image_path` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('active','suspended','pending') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'محمد','محمد','mohammad@rihla.local',NULL,NULL,'$2y$10$8cG5DpwtX7jfX1IJphp9AOvELBcOvsL82HiXSUstLJnYgqEYKz6J.','active','2026-08-26 22:53:10','2026-08-26 19:52:00','2026-08-26 22:53:10'),
(2,'الغزالي محمدمحمدحسين',NULL,'mahumad7733@gmail.com','0770105284',NULL,'$2y$10$Bz/0WfFBiwrC.sCo.FCy1OBlYO3ZXGWU8F/YEvJRk0qt76EUlbnt2','active',NULL,'2026-08-26 19:59:26','2026-08-26 22:33:50'),
(3,'مستخدم اختبار',NULL,'test-20260826@example.com','777000001',NULL,'$2y$10$Ptd4924rv5u/2JQDDtJCQu0uc2pB3hqoM9haIG64p4PYBt48jloLS','active',NULL,'2026-08-26 20:38:35','2026-08-26 20:38:35'),
(4,'عميل صورة تجريبي',NULL,'test-profile-20260826@example.com','777000027',NULL,'$2y$10$EfDeE/yfWwv9.M6nOS27ruJScfywPnBePjxJTYFPQudJD9lOV3JDi','active',NULL,'2026-08-26 21:24:23','2026-08-26 21:24:23'),
(5,'عميل اختبار دورة كاملة محدث',NULL,'e2e.customer.2018192@example.com','7782018192',NULL,'$2y$10$Hop48F3Q9N8Qw5q.Rxb5guXdN3VB5Y.RiT4HaYSXiO.x1Tyfn6aGy','active','2026-08-26 22:30:34','2026-08-26 22:25:28','2026-08-26 22:31:49'),
(6,'محمد علي',NULL,'ss738155ku@gmail.com','770105284','uploads/profiles/user_6_62b69ea3d2.jpg','$2y$10$TJVP3ED5S7R2b3uuEKJ26eIC5w1eYS55pUqzrFWJa/fPxqHkqHVrO','active',NULL,'2026-08-26 22:30:00','2026-08-26 22:30:01'),
(9,'اصيله',NULL,'huh02326@gmail.com','737987541',NULL,'$2y$10$4VRi4FYBRbEmWmJhzwySBu4TzEoQ55X2X1y6QZDipy7wRdvD7hGQS','active',NULL,'2026-08-26 22:15:48','2026-08-26 22:15:48'),
(11,'محمد محمد حسين الغزالي',NULL,'mahumad776633@gmail.com','770105286457','uploads/profiles/user_11_cb0974350f.jpg','$2y$10$uk0/lOkqKn3HvNeeA1b4P./pVeQSqDPCUUgPkJ3SK9y2ltv2oIqBG','active',NULL,'2026-08-26 23:10:33','2026-08-26 23:25:38'),
(12,'الغزالي','الغزالي','mahumad77333@gmail.com','07701057284',NULL,'$2y$10$j9wsrs111NLr5rKmEmFVb.978RfMvgWk4Ju/6mV4xBAXBSKVrnbW.','active','2026-08-27 00:23:27','2026-08-27 00:13:35','2026-08-27 00:23:27');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'rihla_latest_local'
--

--
-- Dumping routines for database 'rihla_latest_local'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-27  3:37:05

-- Exported locally by Rihla packaging workflow.
