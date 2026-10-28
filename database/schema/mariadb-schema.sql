/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `adjustment_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `adjustment_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `number` varchar(255) DEFAULT NULL,
  `amount` decimal(17,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `is_cancellation` tinyint(1) NOT NULL DEFAULT 0,
  `is_refund` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `adjustment_notes_number_unique` (`number`),
  KEY `adjustment_notes_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `adjustment_notes_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `token` varchar(255) NOT NULL,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `ip_addresses` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ip_addresses`)),
  `last_used_at` datetime DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_token_unique` (`token`),
  KEY `api_keys_staff_id_foreign` (`staff_id`),
  CONSTRAINT `api_keys_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changes`)),
  `ip_address` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_model_type_model_id_index` (`model_type`,`model_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_type` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(255) NOT NULL,
  `auditable_type` varchar(255) NOT NULL,
  `auditable_id` bigint(20) unsigned NOT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `url` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(1023) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audits_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audits_user_id_user_type_index` (`user_id`,`user_type`),
  KEY `audits_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bank` varchar(20) NOT NULL,
  `direction` varchar(10) NOT NULL,
  `amount` decimal(17,2) NOT NULL,
  `balance` decimal(17,2) DEFAULT NULL,
  `reason` varchar(500) NOT NULL DEFAULT '',
  `executed_at` timestamp NULL DEFAULT NULL,
  `message_key` varchar(64) NOT NULL,
  `message_id` varchar(255) DEFAULT NULL,
  `folder` varchar(255) DEFAULT NULL,
  `account` varchar(100) DEFAULT NULL,
  `authentic` tinyint(1) NOT NULL DEFAULT 0,
  `authenticity` varchar(64) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `matched_at` timestamp NULL DEFAULT NULL,
  `match_note` varchar(150) DEFAULT NULL,
  `acknowledged_by` bigint(20) unsigned DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bank_movements_message_key_unique` (`message_key`),
  KEY `bank_movements_acknowledged_by_foreign` (`acknowledged_by`),
  KEY `bank_movements_direction_status_index` (`direction`,`status`),
  KEY `bank_movements_bank_executed_at_index` (`bank`,`executed_at`),
  KEY `bank_movements_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `bank_movements_acknowledged_by_foreign` FOREIGN KEY (`acknowledged_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_movements_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_agreements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_agreements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ulid` char(26) NOT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `gateway_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(255) DEFAULT NULL,
  `expiry` date DEFAULT NULL,
  `expiry_notified_at` timestamp NULL DEFAULT NULL,
  `failed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_failed_at` timestamp NULL DEFAULT NULL,
  `external_reference` varchar(255) NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_agreements_ulid_unique` (`ulid`),
  UNIQUE KEY `billing_agreements_external_reference_unique` (`external_reference`),
  KEY `billing_agreements_gateway_id_foreign` (`gateway_id`),
  KEY `billing_agreements_client_id_foreign` (`client_id`),
  CONSTRAINT `billing_agreements_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_agreements_gateway_id_foreign` FOREIGN KEY (`gateway_id`) REFERENCES `extensions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bulk_mail_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bulk_mail_recipients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bulk_mail_id` bigint(20) unsigned NOT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `error` varchar(255) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bulk_mail_recipients_bulk_mail_id_client_id_unique` (`bulk_mail_id`,`client_id`),
  KEY `bulk_mail_recipients_client_id_foreign` (`client_id`),
  KEY `bulk_mail_recipients_bulk_mail_id_status_index` (`bulk_mail_id`,`status`),
  KEY `bulk_mail_recipients_user_id_index` (`user_id`),
  CONSTRAINT `bulk_mail_recipients_bulk_mail_id_foreign` FOREIGN KEY (`bulk_mail_id`) REFERENCES `bulk_mails` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bulk_mail_recipients_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bulk_mails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bulk_mails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `subject_el` varchar(255) DEFAULT NULL,
  `body_el` text DEFAULT NULL,
  `filters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`filters`)),
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `recipients_count` int(10) unsigned NOT NULL DEFAULT 0,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `batch_at` timestamp NULL DEFAULT NULL,
  `batch_seq` int(10) unsigned NOT NULL DEFAULT 0,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bulk_mails_staff_id_foreign` (`staff_id`),
  CONSTRAINT `bulk_mails_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `canned_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canned_replies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `canned_reply_category_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `whmcs_hash` char(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `canned_replies_whmcs_id_unique` (`whmcs_id`),
  KEY `canned_replies_canned_reply_category_id_foreign` (`canned_reply_category_id`),
  CONSTRAINT `canned_replies_canned_reply_category_id_foreign` FOREIGN KEY (`canned_reply_category_id`) REFERENCES `canned_reply_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `canned_reply_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canned_reply_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `whmcs_hash` char(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `canned_reply_categories_whmcs_id_unique` (`whmcs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cardlink_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cardlink_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` varchar(50) NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` bigint(20) unsigned DEFAULT NULL,
  `payment_group` varchar(26) DEFAULT NULL,
  `amount` decimal(17,2) NOT NULL,
  `currency_code` varchar(3) NOT NULL,
  `locale` varchar(10) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `tx_id` varchar(64) DEFAULT NULL,
  `pay_method` varchar(30) DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `recorded_at` timestamp NULL DEFAULT NULL,
  `invoice_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cardlink_orders_order_id_unique` (`order_id`),
  KEY `cardlink_orders_invoice_id_foreign` (`invoice_id`),
  KEY `cardlink_orders_client_id_foreign` (`client_id`),
  CONSTRAINT `cardlink_orders_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cardlink_orders_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `config_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_options`)),
  `checkout_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checkout_config`)),
  `domain` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`domain`)),
  `epp_code` text DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cart_items_cart_id_foreign` (`cart_id`),
  KEY `cart_items_product_id_foreign` (`product_id`),
  KEY `cart_items_plan_id_foreign` (`plan_id`),
  CONSTRAINT `cart_items_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_items_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `carts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ulid` char(26) NOT NULL,
  `client_id` bigint(20) unsigned DEFAULT NULL,
  `coupon_id` bigint(20) unsigned DEFAULT NULL,
  `currency_code` varchar(3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `carts_ulid_unique` (`ulid`),
  KEY `carts_coupon_id_foreign` (`coupon_id`),
  KEY `carts_client_id_foreign` (`client_id`),
  CONSTRAINT `carts_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `carts_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `full_slug` text DEFAULT NULL,
  `sort` tinyint(3) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `show_in_menu` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_slug_unique` (`slug`),
  UNIQUE KEY `categories_whmcs_id_unique` (`whmcs_id`),
  KEY `categories_parent_id_foreign` (`parent_id`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `charge_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` uuid NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `billing_agreement_id` bigint(20) unsigned DEFAULT NULL,
  `payment_group_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(17,2) NOT NULL,
  `currency_code` varchar(3) NOT NULL,
  `source` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL,
  `error` varchar(255) DEFAULT NULL,
  `started_at` timestamp NOT NULL,
  `sent_at` timestamp NOT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charge_attempts_key_unique` (`key`),
  KEY `charge_attempts_client_id_foreign` (`client_id`),
  KEY `charge_attempts_billing_agreement_id_foreign` (`billing_agreement_id`),
  KEY `charge_attempts_payment_group_id_foreign` (`payment_group_id`),
  KEY `charge_attempts_invoice_id_sent_at_index` (`invoice_id`,`sent_at`),
  CONSTRAINT `charge_attempts_billing_agreement_id_foreign` FOREIGN KEY (`billing_agreement_id`) REFERENCES `billing_agreements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `charge_attempts_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `charge_attempts_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `charge_attempts_payment_group_id_foreign` FOREIGN KEY (`payment_group_id`) REFERENCES `payment_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_notification_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_notification_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `webhook_url` text NOT NULL,
  `events` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`events`)),
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `colour` varchar(7) DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `separate_invoices` tinyint(1) NOT NULL DEFAULT 0,
  `dunning_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `no_overdue_reminders` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `invited_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `invited_by_staff_id` bigint(20) unsigned DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `accepted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_invites_token_hash_unique` (`token_hash`),
  KEY `client_invites_invited_by_user_id_foreign` (`invited_by_user_id`),
  KEY `client_invites_invited_by_staff_id_foreign` (`invited_by_staff_id`),
  KEY `client_invites_accepted_by_user_id_foreign` (`accepted_by_user_id`),
  KEY `client_invites_client_id_email_index` (`client_id`,`email`),
  CONSTRAINT `client_invites_accepted_by_user_id_foreign` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_invites_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_invites_invited_by_staff_id_foreign` FOREIGN KEY (`invited_by_staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_invites_invited_by_user_id_foreign` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `sticky` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_notes_staff_id_foreign` (`staff_id`),
  KEY `client_notes_client_id_sticky_index` (`client_id`,`sticky`),
  CONSTRAINT `client_notes_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_notes_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `owner` tinyint(1) NOT NULL DEFAULT 0,
  `owner_of_client_id` bigint(20) unsigned DEFAULT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `granted_by` bigint(20) unsigned DEFAULT NULL,
  `granted_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_user_client_id_user_id_unique` (`client_id`,`user_id`),
  UNIQUE KEY `client_user_owner_of_client_id_unique` (`owner_of_client_id`),
  KEY `client_user_user_id_foreign` (`user_id`),
  KEY `client_user_granted_by_foreign` (`granted_by`),
  CONSTRAINT `client_user_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_user_granted_by_foreign` FOREIGN KEY (`granted_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_group_id` bigint(20) unsigned DEFAULT NULL,
  `credit_auto_apply` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tax_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `separate_invoices` tinyint(1) NOT NULL DEFAULT 0,
  `dunning_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `no_overdue_reminders` tinyint(1) NOT NULL DEFAULT 0,
  `default_billing_agreement_id` bigint(20) unsigned DEFAULT NULL,
  `auto_charge` tinyint(1) NOT NULL DEFAULT 1,
  `language` varchar(10) DEFAULT NULL,
  `ignore_invoicing` tinyint(1) NOT NULL DEFAULT 0,
  `immediate_invoicing` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `clients_default_billing_agreement_id_foreign` (`default_billing_agreement_id`),
  KEY `clients_client_group_id_index` (`client_group_id`),
  CONSTRAINT `clients_default_billing_agreement_id_foreign` FOREIGN KEY (`default_billing_agreement_id`) REFERENCES `billing_agreements` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `config_option_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `config_option_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `config_option_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `config_option_products_config_option_id_foreign` (`config_option_id`),
  KEY `config_option_products_product_id_foreign` (`product_id`),
  CONSTRAINT `config_option_products_config_option_id_foreign` FOREIGN KEY (`config_option_id`) REFERENCES `config_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `config_option_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `config_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `config_options` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `env_variable` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `sort` tinyint(3) unsigned DEFAULT NULL,
  `hidden` tinyint(1) NOT NULL DEFAULT 0,
  `staff_task` tinyint(1) NOT NULL DEFAULT 0,
  `upgradable` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_ref` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_options_whmcs_ref_unique` (`whmcs_ref`),
  KEY `config_options_parent_id_foreign` (`parent_id`),
  CONSTRAINT `config_options_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `config_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `primary_of_client_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'individual',
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `vat_number` varchar(50) DEFAULT NULL,
  `vat_status` varchar(16) DEFAULT NULL,
  `vat_checked_number` varchar(50) DEFAULT NULL,
  `vat_checked_at` timestamp NULL DEFAULT NULL,
  `tax_office` varchar(255) DEFAULT NULL,
  `activity` varchar(255) DEFAULT NULL,
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `postcode` varchar(255) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `document_type` varchar(20) DEFAULT NULL,
  `email_preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`email_preferences`)),
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contacts_primary_of_client_id_unique` (`primary_of_client_id`),
  KEY `contacts_client_id_archived_at_index` (`client_id`,`archived_at`),
  CONSTRAINT `contacts_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `coupon_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `coupon_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `coupon_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `coupon_products_coupon_id_foreign` (`coupon_id`),
  KEY `coupon_products_product_id_foreign` (`product_id`),
  CONSTRAINT `coupon_products_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `coupon_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `coupon_required_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `coupon_required_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `coupon_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coupon_required_products_coupon_id_product_id_unique` (`coupon_id`,`product_id`),
  KEY `coupon_required_products_product_id_foreign` (`product_id`),
  CONSTRAINT `coupon_required_products_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `coupon_required_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `coupons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `coupons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL,
  `applies_to` varchar(255) NOT NULL DEFAULT 'all',
  `recurring` int(11) DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `value` decimal(17,2) DEFAULT NULL,
  `max_uses` int(11) DEFAULT NULL,
  `max_uses_per_user` int(11) DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `customers` varchar(16) NOT NULL DEFAULT 'all',
  `once_per_client` tinyint(1) NOT NULL DEFAULT 0,
  `requires_existing` tinyint(1) NOT NULL DEFAULT 0,
  `billing_cycles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`billing_cycles`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `coupons_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cpanel_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cpanel_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint(20) unsigned NOT NULL,
  `user` varchar(64) NOT NULL,
  `domain` varchar(253) DEFAULT NULL,
  `owner` varchar(64) DEFAULT NULL,
  `plan` varchar(191) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `reseller` tinyint(1) DEFAULT NULL,
  `disk_used_mb` bigint(20) unsigned DEFAULT NULL,
  `disk_limit_mb` bigint(20) unsigned DEFAULT NULL,
  `inodes_used` bigint(20) unsigned DEFAULT NULL,
  `inodes_limit` bigint(20) unsigned DEFAULT NULL,
  `bandwidth_used_mb` bigint(20) unsigned DEFAULT NULL,
  `bandwidth_limit_mb` bigint(20) unsigned DEFAULT NULL,
  `suspended` tinyint(1) NOT NULL DEFAULT 0,
  `suspend_reason` varchar(255) DEFAULT NULL,
  `suspended_at` timestamp NULL DEFAULT NULL,
  `mail_held` tinyint(1) NOT NULL DEFAULT 0,
  `mail_suspended` tinyint(1) NOT NULL DEFAULT 0,
  `max_email_per_hour` int(11) DEFAULT NULL,
  `backup` tinyint(1) NOT NULL DEFAULT 0,
  `created_on_server_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `gone_at` timestamp NULL DEFAULT NULL,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `internal` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lve_memory_limit_mb` bigint(20) unsigned DEFAULT NULL,
  `lve_entry_processes_limit` int(10) unsigned DEFAULT NULL,
  `lve_processes_limit` int(10) unsigned DEFAULT NULL,
  `lve_io_limit_kbs` bigint(20) unsigned DEFAULT NULL,
  `lve_iops_limit` int(10) unsigned DEFAULT NULL,
  `lve_read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cpanel_accounts_server_id_user_unique` (`server_id`,`user`),
  KEY `cpanel_accounts_service_id_foreign` (`service_id`),
  KEY `cpanel_accounts_owner_index` (`owner`),
  CONSTRAINT `cpanel_accounts_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `extensions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cpanel_accounts_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cpanel_lve_samples`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cpanel_lve_samples` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cpanel_account_id` bigint(20) unsigned NOT NULL,
  `sampled_at` timestamp NOT NULL,
  `cpu` smallint(5) unsigned DEFAULT NULL,
  `memory_mb` bigint(20) unsigned DEFAULT NULL,
  `entry_processes` int(10) unsigned DEFAULT NULL,
  `processes` int(10) unsigned DEFAULT NULL,
  `io_kbs` bigint(20) unsigned DEFAULT NULL,
  `iops` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cpanel_lve_samples_cpanel_account_id_sampled_at_index` (`cpanel_account_id`,`sampled_at`),
  KEY `cpanel_lve_samples_sampled_at_index` (`sampled_at`),
  CONSTRAINT `cpanel_lve_samples_cpanel_account_id_foreign` FOREIGN KEY (`cpanel_account_id`) REFERENCES `cpanel_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `currency_code` varchar(3) NOT NULL,
  `amount` decimal(17,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credits_user_id_currency_code_unique` (`client_id`,`currency_code`),
  CONSTRAINT `credits_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cron_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cron_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` int(11) NOT NULL DEFAULT 0,
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cron_stats_date_index` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `code` varchar(3) NOT NULL,
  `name` varchar(255) NOT NULL,
  `prefix` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `format` enum('1.000,00','1,000.00','1 000,00','1 000.00') NOT NULL DEFAULT '1.000,00',
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `custom_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_properties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `key` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `model` varchar(255) NOT NULL,
  `validation` varchar(255) DEFAULT NULL,
  `allowed_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_values`)),
  `non_editable` tinyint(1) NOT NULL,
  `required` tinyint(1) NOT NULL,
  `show_on_invoice` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_properties_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `debug_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `debug_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`context`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_nameservers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_nameservers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` bigint(20) unsigned NOT NULL,
  `host` varchar(255) NOT NULL,
  `sort` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_nameservers_domain_id_foreign` (`domain_id`),
  CONSTRAINT `domain_nameservers_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_owner_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_owner_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `move_to_client_id` bigint(20) unsigned DEFAULT NULL,
  `declaration_path` varchar(255) DEFAULT NULL,
  `declaration_name` varchar(255) DEFAULT NULL,
  `status` varchar(24) NOT NULL,
  `free` tinyint(1) NOT NULL DEFAULT 0,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `result` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `done_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_owner_changes_domain_id_foreign` (`domain_id`),
  KEY `domain_owner_changes_contact_id_foreign` (`contact_id`),
  KEY `domain_owner_changes_move_to_client_id_foreign` (`move_to_client_id`),
  KEY `domain_owner_changes_staff_id_foreign` (`staff_id`),
  KEY `domain_owner_changes_status_index` (`status`),
  CONSTRAINT `domain_owner_changes_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `domain_owner_changes_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE,
  CONSTRAINT `domain_owner_changes_move_to_client_id_foreign` FOREIGN KEY (`move_to_client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `domain_owner_changes_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_registrar_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_registrar_connections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `registrar` varchar(40) NOT NULL,
  `label` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `mode` varchar(20) NOT NULL DEFAULT 'off',
  `debug_log` tinyint(1) NOT NULL DEFAULT 0,
  `balance` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`balance`)),
  `low_balance` decimal(12,2) DEFAULT NULL,
  `renew_transfer_to_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_registrar_connections_registrar_is_active_index` (`registrar`,`is_active`),
  KEY `domain_registrar_connections_renew_transfer_to_id_foreign` (`renew_transfer_to_id`),
  CONSTRAINT `domain_registrar_connections_renew_transfer_to_id_foreign` FOREIGN KEY (`renew_transfer_to_id`) REFERENCES `domain_registrar_connections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_registrar_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_registrar_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` bigint(20) unsigned DEFAULT NULL,
  `registrar_connection_id` bigint(20) unsigned DEFAULT NULL,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `status` varchar(20) NOT NULL,
  `request` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request`)),
  `response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response`)),
  `transactions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`transactions`)),
  `error` text DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_registrar_logs_registrar_connection_id_foreign` (`registrar_connection_id`),
  KEY `domain_registrar_logs_invoice_id_foreign` (`invoice_id`),
  KEY `domain_registrar_logs_domain_id_action_status_index` (`domain_id`,`action`,`status`),
  KEY `domain_registrar_logs_staff_id_foreign` (`staff_id`),
  KEY `domain_registrar_logs_user_id_foreign` (`user_id`),
  CONSTRAINT `domain_registrar_logs_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`),
  CONSTRAINT `domain_registrar_logs_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `domain_registrar_logs_registrar_connection_id_foreign` FOREIGN KEY (`registrar_connection_id`) REFERENCES `domain_registrar_connections` (`id`),
  CONSTRAINT `domain_registrar_logs_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `domain_registrar_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_reminders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` bigint(20) unsigned NOT NULL,
  `days` smallint(6) NOT NULL,
  `expires_on` date NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_reminders_domain_id_days_expires_on_unique` (`domain_id`,`days`,`expires_on`),
  CONSTRAINT `domain_reminders_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_tld_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_tld_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `domain_tld_id` bigint(20) unsigned NOT NULL,
  `operation` varchar(20) NOT NULL,
  `years` tinyint(3) unsigned NOT NULL,
  `currency_code` varchar(3) NOT NULL,
  `price` decimal(17,2) DEFAULT NULL,
  `cost` decimal(17,2) DEFAULT NULL,
  `cost_currency` varchar(3) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_tld_prices_unique` (`domain_tld_id`,`operation`,`years`,`currency_code`),
  KEY `domain_tld_prices_currency_code_foreign` (`currency_code`),
  CONSTRAINT `domain_tld_prices_currency_code_foreign` FOREIGN KEY (`currency_code`) REFERENCES `currencies` (`code`) ON DELETE CASCADE,
  CONSTRAINT `domain_tld_prices_domain_tld_id_foreign` FOREIGN KEY (`domain_tld_id`) REFERENCES `domain_tlds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_tlds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_tlds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tld` varchar(63) NOT NULL,
  `registrar_connection_id` bigint(20) unsigned DEFAULT NULL,
  `epp_code_required` tinyint(1) NOT NULL DEFAULT 1,
  `dns_management` tinyint(1) NOT NULL DEFAULT 0,
  `email_forwarding` tinyint(1) NOT NULL DEFAULT 0,
  `id_protection` tinyint(1) NOT NULL DEFAULT 0,
  `allow_idn` tinyint(1) NOT NULL DEFAULT 0,
  `grace_days` smallint(5) unsigned DEFAULT NULL,
  `redemption_days` smallint(5) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_tlds_tld_unique` (`tld`),
  UNIQUE KEY `domain_tlds_whmcs_id_unique` (`whmcs_id`),
  KEY `domain_tlds_registrar_connection_id_foreign` (`registrar_connection_id`),
  CONSTRAINT `domain_tlds_registrar_connection_id_foreign` FOREIGN KEY (`registrar_connection_id`) REFERENCES `domain_registrar_connections` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `domain_tld_id` bigint(20) unsigned DEFAULT NULL,
  `registrar_connection_id` bigint(20) unsigned DEFAULT NULL,
  `sld` varchar(190) NOT NULL,
  `tld` varchar(63) NOT NULL,
  `fqdn` varchar(255) NOT NULL,
  `status` varchar(30) NOT NULL,
  `live_fqdn` varchar(255) GENERATED ALWAYS AS (case when `status` in ('transferred_away','cancelled','deleted') then NULL else `fqdn` end) STORED,
  `registered_at` date DEFAULT NULL,
  `transferred_at` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `grace_ends_on` date DEFAULT NULL,
  `redemption_ends_on` date DEFAULT NULL,
  `years` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `currency_code` varchar(3) NOT NULL,
  `recurring_amount` decimal(17,2) DEFAULT NULL,
  `auto_renew` tinyint(1) NOT NULL DEFAULT 1,
  `transfer_lock` tinyint(1) NOT NULL DEFAULT 0,
  `id_protection` tinyint(1) NOT NULL DEFAULT 0,
  `dns_management` tinyint(1) NOT NULL DEFAULT 0,
  `email_forwarding` tinyint(1) NOT NULL DEFAULT 0,
  `dnssec_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `registrant_contact_id` bigint(20) unsigned DEFAULT NULL,
  `registrant_handle` varchar(60) DEFAULT NULL,
  `admin_contact_id` bigint(20) unsigned DEFAULT NULL,
  `admin_handle` varchar(60) DEFAULT NULL,
  `tech_contact_id` bigint(20) unsigned DEFAULT NULL,
  `tech_handle` varchar(60) DEFAULT NULL,
  `billing_contact_id` bigint(20) unsigned DEFAULT NULL,
  `billing_handle` varchar(60) DEFAULT NULL,
  `registrar_domain_id` varchar(60) DEFAULT NULL,
  `epp_code` text DEFAULT NULL,
  `idn_script` varchar(30) DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `sync_error` text DEFAULT NULL,
  `grace_days_override` smallint(5) unsigned DEFAULT NULL,
  `grace_fee_override` decimal(17,2) DEFAULT NULL,
  `redemption_days_override` smallint(5) unsigned DEFAULT NULL,
  `redemption_fee_override` decimal(17,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domains_live_fqdn_unique` (`live_fqdn`),
  KEY `domains_client_id_foreign` (`client_id`),
  KEY `domains_domain_tld_id_foreign` (`domain_tld_id`),
  KEY `domains_registrar_connection_id_foreign` (`registrar_connection_id`),
  KEY `domains_currency_code_foreign` (`currency_code`),
  KEY `domains_registrant_contact_id_foreign` (`registrant_contact_id`),
  KEY `domains_admin_contact_id_foreign` (`admin_contact_id`),
  KEY `domains_tech_contact_id_foreign` (`tech_contact_id`),
  KEY `domains_billing_contact_id_foreign` (`billing_contact_id`),
  KEY `domains_status_expires_at_index` (`status`,`expires_at`),
  KEY `domains_expires_at_index` (`expires_at`),
  KEY `domains_fqdn_index` (`fqdn`),
  KEY `domains_order_id_foreign` (`order_id`),
  KEY `domains_contact_id_foreign` (`contact_id`),
  CONSTRAINT `domains_admin_contact_id_foreign` FOREIGN KEY (`admin_contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `domains_billing_contact_id_foreign` FOREIGN KEY (`billing_contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `domains_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  CONSTRAINT `domains_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `domains_currency_code_foreign` FOREIGN KEY (`currency_code`) REFERENCES `currencies` (`code`),
  CONSTRAINT `domains_domain_tld_id_foreign` FOREIGN KEY (`domain_tld_id`) REFERENCES `domain_tlds` (`id`),
  CONSTRAINT `domains_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `domains_registrant_contact_id_foreign` FOREIGN KEY (`registrant_contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `domains_registrar_connection_id_foreign` FOREIGN KEY (`registrar_connection_id`) REFERENCES `domain_registrar_connections` (`id`),
  CONSTRAINT `domains_tech_contact_id_foreign` FOREIGN KEY (`tech_contact_id`) REFERENCES `contacts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ekdosi_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ekdosi_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `mark` varchar(32) NOT NULL,
  `code` varchar(40) DEFAULT NULL,
  `state` varchar(12) NOT NULL DEFAULT 'active',
  `pdf_url` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ekdosi_documents_mark_unique` (`mark`),
  KEY `ekdosi_documents_invoice_id_index` (`invoice_id`),
  CONSTRAINT `ekdosi_documents_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `to` varchar(255) NOT NULL,
  `body` longtext NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `error` text DEFAULT NULL,
  `job_uuid` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `from_address` varchar(255) DEFAULT NULL,
  `cc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cc`)),
  `bcc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bcc`)),
  `template_key` varchar(100) DEFAULT NULL,
  `related_type` varchar(255) DEFAULT NULL,
  `related_id` bigint(20) unsigned DEFAULT NULL,
  `trigger` varchar(16) NOT NULL DEFAULT 'auto',
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `resent_from_id` bigint(20) unsigned DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sending_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `message_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_logs_client_id_foreign` (`client_id`),
  KEY `email_logs_staff_id_foreign` (`staff_id`),
  KEY `email_logs_related_type_related_id_index` (`related_type`,`related_id`),
  KEY `email_logs_status_created_at_index` (`status`,`created_at`),
  KEY `email_logs_template_key_index` (`template_key`),
  KEY `email_logs_resent_from_id_index` (`resent_from_id`),
  KEY `email_logs_created_at_index` (`created_at`),
  CONSTRAINT `email_logs_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_logs_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `extensions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `extensions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `extension` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `max_accounts` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `extensions_whmcs_id_unique` (`whmcs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gateway_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gateway_refunds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `gateway` varchar(50) NOT NULL,
  `reference` varchar(191) NOT NULL,
  `payment_reference` varchar(191) DEFAULT NULL,
  `amount` decimal(17,2) NOT NULL,
  `currency_code` varchar(3) NOT NULL,
  `recorded_amount` decimal(17,2) NOT NULL,
  `origin` varchar(20) NOT NULL,
  `money_event_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gateway_refunds_gateway_reference_unique` (`gateway`,`reference`),
  KEY `gateway_refunds_invoice_transaction_id_foreign` (`invoice_transaction_id`),
  KEY `gateway_refunds_gateway_payment_reference_index` (`gateway`,`payment_reference`),
  KEY `gateway_refunds_money_event_id_foreign` (`money_event_id`),
  CONSTRAINT `gateway_refunds_invoice_transaction_id_foreign` FOREIGN KEY (`invoice_transaction_id`) REFERENCES `invoice_transactions` (`id`),
  CONSTRAINT `gateway_refunds_money_event_id_foreign` FOREIGN KEY (`money_event_id`) REFERENCES `money_events` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `price` decimal(17,2) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `forgone_at` timestamp NULL DEFAULT NULL,
  `reference_type` varchar(255) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `kind` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxed` tinyint(1) NOT NULL DEFAULT 1,
  `tax_rate` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `tax_reason` varchar(20) DEFAULT NULL,
  `tax_amount` decimal(17,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(17,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `invoice_items_invoice_id_foreign` (`invoice_id`),
  KEY `invoice_items_reference_period_index` (`reference_type`,`reference_id`,`period_start`),
  CONSTRAINT `invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_reminders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `due_on` date NOT NULL,
  `sent_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_reminders_invoice_id_kind_days_due_on_unique` (`invoice_id`,`kind`,`days`,`due_on`),
  CONSTRAINT `invoice_reminders_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `tax_name` varchar(255) DEFAULT NULL,
  `tax_rate` decimal(5,2) DEFAULT NULL,
  `tax_country` varchar(255) DEFAULT NULL,
  `bill_to` text DEFAULT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_snapshots_invoice_id_unique` (`invoice_id`),
  CONSTRAINT `invoice_snapshots_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `gateway_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(17,2) NOT NULL,
  `refunded_amount` decimal(17,2) NOT NULL DEFAULT 0.00,
  `credited_amount` decimal(17,2) NOT NULL DEFAULT 0.00,
  `source_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `fee` decimal(17,2) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'succeeded',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_credit_transaction` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `invoice_transactions_invoice_id_foreign` (`invoice_id`),
  KEY `invoice_transactions_gateway_id_foreign` (`gateway_id`),
  KEY `invoice_transactions_transaction_gateway_index` (`transaction_id`,`gateway_id`),
  KEY `invoice_transactions_source_transaction_id_foreign` (`source_transaction_id`),
  KEY `inv_tx_status_is_credit_transaction_created_at_index` (`status`,`is_credit_transaction`,`created_at`),
  KEY `invoice_transactions_created_at_index` (`created_at`),
  CONSTRAINT `invoice_transactions_gateway_id_foreign` FOREIGN KEY (`gateway_id`) REFERENCES `extensions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoice_transactions_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_transactions_source_transaction_id_foreign` FOREIGN KEY (`source_transaction_id`) REFERENCES `invoice_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `cancellation_reason` varchar(255) DEFAULT NULL,
  `merged_into_id` bigint(20) unsigned DEFAULT NULL,
  `split_from_id` bigint(20) unsigned DEFAULT NULL,
  `kept_as_debt_at` timestamp NULL DEFAULT NULL,
  `finish_pending_at` timestamp NULL DEFAULT NULL,
  `due_at` date DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `currency_code` varchar(3) NOT NULL,
  `client_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `apply_pending_since` timestamp NULL DEFAULT NULL,
  `no_auto_charge` tinyint(1) NOT NULL DEFAULT 0,
  `ekdosi_requested_at` timestamp NULL DEFAULT NULL,
  `ekdosi_requested_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_number_unique` (`number`),
  KEY `invoices_apply_pending_since_index` (`apply_pending_since`),
  KEY `invoices_client_id_foreign` (`client_id`),
  KEY `invoices_merged_into_id_foreign` (`merged_into_id`),
  KEY `invoices_split_from_id_foreign` (`split_from_id`),
  KEY `invoices_paid_at_index` (`paid_at`),
  KEY `invoices_status_due_at_index` (`status`,`due_at`),
  KEY `invoices_ekdosi_requested_by_foreign` (`ekdosi_requested_by`),
  KEY `invoices_ekdosi_requested_at_index` (`ekdosi_requested_at`),
  CONSTRAINT `invoices_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_ekdosi_requested_by_foreign` FOREIGN KEY (`ekdosi_requested_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_merged_into_id_foreign` FOREIGN KEY (`merged_into_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_split_from_id_foreign` FOREIGN KEY (`split_from_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ip_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_pool_id` bigint(20) unsigned NOT NULL,
  `address` varchar(49) NOT NULL,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `reserved` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(191) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_addresses_address_unique` (`address`),
  KEY `ip_addresses_service_id_foreign` (`service_id`),
  KEY `ip_addresses_ip_pool_id_service_id_index` (`ip_pool_id`,`service_id`),
  CONSTRAINT `ip_addresses_ip_pool_id_foreign` FOREIGN KEY (`ip_pool_id`) REFERENCES `ip_pools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ip_addresses_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ip_pools`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_pools` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `family` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `network` varchar(49) DEFAULT NULL,
  `prefix` tinyint(3) unsigned NOT NULL,
  `gateway` varchar(45) DEFAULT NULL,
  `nameservers` varchar(191) DEFAULT NULL,
  `server_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_pools_whmcs_id_unique` (`whmcs_id`),
  KEY `ip_pools_server_id_foreign` (`server_id`),
  CONSTRAINT `ip_pools_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `extensions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kb_article_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_article_category` (
  `article_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`article_id`,`category_id`),
  KEY `kb_article_category_category_id_foreign` (`category_id`),
  CONSTRAINT `kb_article_category_article_id_foreign` FOREIGN KEY (`article_id`) REFERENCES `kb_articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `kb_article_category_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `kb_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kb_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_articles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `body` longtext NOT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  `translations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`translations`)),
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kb_articles_whmcs_id_unique` (`whmcs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kb_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `translations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`translations`)),
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kb_categories_whmcs_id_unique` (`whmcs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kb_whmcs_tombstones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_whmcs_tombstones` (
  `ref` varchar(40) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `license_access_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `license_access_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `license_id` bigint(20) unsigned DEFAULT NULL,
  `domain` varchar(255) NOT NULL DEFAULT '',
  `ip` varchar(64) NOT NULL DEFAULT '',
  `directory` varchar(255) NOT NULL DEFAULT '',
  `message` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  `whmcs_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `license_access_logs_whmcs_id_unique` (`whmcs_id`),
  KEY `license_access_logs_license_id_foreign` (`license_id`),
  KEY `license_access_logs_created_at_index` (`created_at`),
  CONSTRAINT `license_access_logs_license_id_foreign` FOREIGN KEY (`license_id`) REFERENCES `licenses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `license_check_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `license_check_days` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `license_id` bigint(20) unsigned NOT NULL,
  `day` date NOT NULL,
  `ok` int(10) unsigned NOT NULL DEFAULT 0,
  `failed` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `license_check_days_license_id_day_unique` (`license_id`,`day`),
  CONSTRAINT `license_check_days_license_id_foreign` FOREIGN KEY (`license_id`) REFERENCES `licenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `licenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `license_key` varchar(191) NOT NULL,
  `status` varchar(16) NOT NULL,
  `valid_domain` text DEFAULT NULL,
  `valid_ip` text DEFAULT NULL,
  `valid_directory` text DEFAULT NULL,
  `reissues` int(10) unsigned NOT NULL DEFAULT 0,
  `last_access` datetime DEFAULT NULL,
  `whmcs_id` bigint(20) unsigned DEFAULT NULL,
  `last_ok_at` datetime DEFAULT NULL,
  `last_seen_ip` varchar(64) DEFAULT NULL,
  `last_seen_client` varchar(255) DEFAULT NULL,
  `last_failure_at` datetime DEFAULT NULL,
  `last_failure` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `licenses_service_id_unique` (`service_id`),
  UNIQUE KEY `licenses_license_key_unique` (`license_key`),
  UNIQUE KEY `licenses_whmcs_id_unique` (`whmcs_id`),
  KEY `licenses_last_ok_at_index` (`last_ok_at`),
  CONSTRAINT `licenses_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `live_chat_department_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `live_chat_department_staff` (
  `ticket_department_id` bigint(20) unsigned NOT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`ticket_department_id`,`staff_id`),
  KEY `live_chat_department_staff_staff_id_foreign` (`staff_id`),
  CONSTRAINT `live_chat_department_staff_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `live_chat_department_staff_ticket_department_id_foreign` FOREIGN KEY (`ticket_department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `live_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `live_chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) unsigned NOT NULL,
  `author_type` varchar(16) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `live_chat_messages_user_id_foreign` (`user_id`),
  KEY `live_chat_messages_staff_id_foreign` (`staff_id`),
  KEY `live_chat_messages_chat_id_id_index` (`chat_id`,`id`),
  CONSTRAINT `live_chat_messages_chat_id_foreign` FOREIGN KEY (`chat_id`) REFERENCES `live_chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `live_chat_messages_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`),
  CONSTRAINT `live_chat_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `live_chats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `live_chats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'waiting',
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `last_participant_message_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `live_chats_user_id_foreign` (`user_id`),
  KEY `live_chats_department_id_foreign` (`department_id`),
  KEY `live_chats_assigned_to_foreign` (`assigned_to`),
  KEY `live_chats_status_department_id_index` (`status`,`department_id`),
  KEY `live_chats_client_id_status_index` (`client_id`,`status`),
  CONSTRAINT `live_chats_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `live_chats_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `live_chats_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `live_chats_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `area` varchar(8) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `result` varchar(16) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `login_attempts_user_id_foreign` (`user_id`),
  KEY `login_attempts_staff_id_foreign` (`staff_id`),
  KEY `login_attempts_result_created_at_index` (`result`,`created_at`),
  KEY `login_attempts_created_at_index` (`created_at`),
  CONSTRAINT `login_attempts_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `login_attempts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mailcow_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mailcow_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint(20) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `mailboxes` int(10) unsigned NOT NULL DEFAULT 0,
  `mailbox_limit` int(10) unsigned NOT NULL DEFAULT 0,
  `aliases` int(10) unsigned NOT NULL DEFAULT 0,
  `alias_limit` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `used_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `messages` bigint(20) unsigned NOT NULL DEFAULT 0,
  `admins` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`admins`)),
  `dkim_selector` varchar(63) DEFAULT NULL,
  `dkim_txt` text DEFAULT NULL,
  `dns_status` varchar(8) DEFAULT NULL,
  `dns_checked_at` timestamp NULL DEFAULT NULL,
  `dns_issues` varchar(255) DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `gone_at` timestamp NULL DEFAULT NULL,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `internal` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mailcow_domains_server_id_domain_unique` (`server_id`,`domain`),
  KEY `mailcow_domains_service_id_foreign` (`service_id`),
  CONSTRAINT `mailcow_domains_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `extensions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mailcow_domains_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mailcow_mailboxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mailcow_mailboxes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mailcow_domain_id` bigint(20) unsigned NOT NULL,
  `address` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `quota_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `used_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `messages` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mailcow_mailboxes_mailcow_domain_id_address_unique` (`mailcow_domain_id`,`address`),
  CONSTRAINT `mailcow_mailboxes_mailcow_domain_id_foreign` FOREIGN KEY (`mailcow_domain_id`) REFERENCES `mailcow_domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_calls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `api_key_id` bigint(20) unsigned DEFAULT NULL,
  `tool` varchar(64) NOT NULL,
  `arguments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`arguments`)),
  `outcome` varchar(16) NOT NULL,
  `error` varchar(255) DEFAULT NULL,
  `duration_ms` int(10) unsigned NOT NULL DEFAULT 0,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `mcp_calls_staff_id_foreign` (`staff_id`),
  KEY `mcp_calls_api_key_id_foreign` (`api_key_id`),
  KEY `mcp_calls_created_at_index` (`created_at`),
  CONSTRAINT `mcp_calls_api_key_id_foreign` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mcp_calls_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_oauth_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_oauth_clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `redirect_uris` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`redirect_uris`)),
  `registered_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcp_oauth_clients_client_id_unique` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_oauth_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_oauth_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_hash` varchar(64) NOT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  `api_key_id` bigint(20) unsigned DEFAULT NULL,
  `redirect_uri` text NOT NULL,
  `code_challenge` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `grant_id` bigint(20) unsigned DEFAULT NULL,
  `staff_session` varchar(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcp_oauth_codes_code_hash_unique` (`code_hash`),
  KEY `mcp_oauth_codes_client_id_foreign` (`client_id`),
  KEY `mcp_oauth_codes_staff_id_foreign` (`staff_id`),
  KEY `mcp_oauth_codes_api_key_id_foreign` (`api_key_id`),
  KEY `mcp_oauth_codes_grant_id_foreign` (`grant_id`),
  KEY `mcp_oauth_codes_staff_session_index` (`staff_session`),
  CONSTRAINT `mcp_oauth_codes_api_key_id_foreign` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mcp_oauth_codes_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `mcp_oauth_clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mcp_oauth_codes_grant_id_foreign` FOREIGN KEY (`grant_id`) REFERENCES `mcp_oauth_grants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mcp_oauth_codes_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_oauth_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_oauth_grants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  `api_key_id` bigint(20) unsigned DEFAULT NULL,
  `approved_by` varchar(16) NOT NULL,
  `access_hash` varchar(64) DEFAULT NULL,
  `access_expires_at` datetime DEFAULT NULL,
  `refresh_hash` varchar(64) DEFAULT NULL,
  `previous_refresh_hash` varchar(64) DEFAULT NULL,
  `refresh_expires_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_reason` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `staff_session` varchar(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcp_oauth_grants_access_hash_unique` (`access_hash`),
  UNIQUE KEY `mcp_oauth_grants_refresh_hash_unique` (`refresh_hash`),
  KEY `mcp_oauth_grants_client_id_foreign` (`client_id`),
  KEY `mcp_oauth_grants_staff_id_foreign` (`staff_id`),
  KEY `mcp_oauth_grants_api_key_id_foreign` (`api_key_id`),
  KEY `mcp_oauth_grants_previous_refresh_hash_index` (`previous_refresh_hash`),
  KEY `mcp_oauth_grants_staff_session_index` (`staff_session`),
  CONSTRAINT `mcp_oauth_grants_api_key_id_foreign` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mcp_oauth_grants_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `mcp_oauth_clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mcp_oauth_grants_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_oauth_used_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_oauth_used_refresh_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grant_id` bigint(20) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcp_oauth_used_refresh_tokens_token_hash_unique` (`token_hash`),
  KEY `mcp_oauth_used_refresh_tokens_grant_id_foreign` (`grant_id`),
  CONSTRAINT `mcp_oauth_used_refresh_tokens_grant_id_foreign` FOREIGN KEY (`grant_id`) REFERENCES `mcp_oauth_grants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_proposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(48) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `summary` varchar(500) NOT NULL,
  `proposed_by` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `decided_by` bigint(20) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `result` text DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mcp_proposals_proposed_by_foreign` (`proposed_by`),
  KEY `mcp_proposals_decided_by_foreign` (`decided_by`),
  KEY `mcp_proposals_status_index` (`status`),
  CONSTRAINT `mcp_proposals_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mcp_proposals_proposed_by_foreign` FOREIGN KEY (`proposed_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `module_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `module_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `domain_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_item_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(16) NOT NULL,
  `subject` varchar(191) DEFAULT NULL,
  `module` varchar(64) DEFAULT NULL,
  `status` varchar(16) NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `given_up_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution` varchar(255) DEFAULT NULL,
  `resolved_by_staff_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `module_actions_resolved_by_staff_id_foreign` (`resolved_by_staff_id`),
  KEY `module_actions_status_action_index` (`status`,`action`),
  KEY `module_actions_service_id_action_status_index` (`service_id`,`action`,`status`),
  KEY `module_actions_invoice_item_id_foreign` (`invoice_item_id`),
  KEY `module_actions_domain_id_action_status_index` (`domain_id`,`action`,`status`),
  CONSTRAINT `module_actions_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`),
  CONSTRAINT `module_actions_invoice_item_id_foreign` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `module_actions_resolved_by_staff_id_foreign` FOREIGN KEY (`resolved_by_staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `module_actions_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `money_event_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `money_event_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `money_event_id` bigint(20) unsigned NOT NULL,
  `note` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `money_event_notes_money_event_id_unique` (`money_event_id`),
  CONSTRAINT `money_event_notes_money_event_id_foreign` FOREIGN KEY (`money_event_id`) REFERENCES `money_events` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `money_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `money_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(20) NOT NULL,
  `amount` decimal(17,2) NOT NULL,
  `currency_code` varchar(3) NOT NULL,
  `client_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `credit_balance_after` decimal(17,2) DEFAULT NULL,
  `source` varchar(50) NOT NULL,
  `cause` varchar(50) DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `group_reference` varchar(255) DEFAULT NULL,
  `actor_type` varchar(255) DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `reverses_id` bigint(20) unsigned DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `money_events_reverses_id_unique` (`reverses_id`),
  KEY `money_events_actor_type_actor_id_index` (`actor_type`,`actor_id`),
  KEY `money_events_user_id_currency_code_id_index` (`client_id`,`currency_code`,`id`),
  KEY `money_events_invoice_id_index` (`invoice_id`),
  KEY `money_events_invoice_transaction_id_index` (`invoice_transaction_id`),
  KEY `money_events_group_reference_index` (`group_reference`),
  CONSTRAINT `money_events_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `money_events_reverses_id_foreign` FOREIGN KEY (`reverses_id`) REFERENCES `money_events` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `notification_template_id` bigint(20) unsigned NOT NULL,
  `mail_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `in_app_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notification_preferences_user_id_foreign` (`user_id`),
  KEY `notification_preferences_notification_template_id_foreign` (`notification_template_id`),
  CONSTRAINT `notification_preferences_notification_template_id_foreign` FOREIGN KEY (`notification_template_id`) REFERENCES `notification_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `endpoint` varchar(255) NOT NULL,
  `p256dh_key` text NOT NULL,
  `auth_key` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_subscriptions_endpoint_unique` (`endpoint`),
  KEY `notification_subscriptions_user_id_foreign` (`user_id`),
  CONSTRAINT `notification_subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `in_app_title` varchar(255) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `mail_enabled` enum('force','choice_on','choice_off','never') NOT NULL DEFAULT 'choice_on',
  `in_app_enabled` enum('force','choice_on','choice_off','never') NOT NULL DEFAULT 'choice_on',
  `body` text NOT NULL,
  `in_app_body` text DEFAULT NULL,
  `edit_preference_message` varchar(255) DEFAULT NULL,
  `in_app_url` varchar(255) DEFAULT NULL,
  `translations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`translations`)),
  `cc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cc`)),
  `bcc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bcc`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_templates_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `show_in_app` tinyint(1) NOT NULL DEFAULT 1,
  `show_as_push` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_id_foreign` (`user_id`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offer_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `offer_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `offer_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `sort` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `offer_items_offer_id_foreign` (`offer_id`),
  KEY `offer_items_product_id_foreign` (`product_id`),
  KEY `offer_items_plan_id_foreign` (`plan_id`),
  CONSTRAINT `offer_items_offer_id_foreign` FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `offer_items_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `offer_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `offers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `coupon_id` bigint(20) unsigned DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offers_slug_unique` (`slug`),
  KEY `offers_coupon_id_foreign` (`coupon_id`),
  CONSTRAINT `offers_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `currency_code` varchar(3) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `accepted_at` timestamp NULL DEFAULT NULL,
  `accepted_by_staff_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `notes` mediumtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `orders_client_id_foreign` (`client_id`),
  KEY `orders_accepted_by_staff_id_foreign` (`accepted_by_staff_id`),
  KEY `orders_status_index` (`status`),
  KEY `orders_invoice_id_index` (`invoice_id`),
  KEY `orders_created_at_index` (`created_at`),
  CONSTRAINT `orders_accepted_by_staff_id_foreign` FOREIGN KEY (`accepted_by_staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_gateway_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_gateway_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gateway` varchar(50) NOT NULL,
  `endpoint` varchar(100) NOT NULL,
  `method` varchar(10) NOT NULL,
  `event_type` varchar(100) DEFAULT NULL,
  `fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fields`)),
  `signature_verified` tinyint(1) DEFAULT NULL,
  `outcome` varchar(20) NOT NULL DEFAULT 'received',
  `reason` varchar(50) DEFAULT NULL,
  `amount` decimal(17,2) DEFAULT NULL,
  `currency_code` varchar(3) DEFAULT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `http_status` smallint(5) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_gateway_events_gateway_index` (`gateway`),
  KEY `payment_gateway_events_outcome_index` (`outcome`),
  KEY `payment_gateway_events_external_id_index` (`external_id`),
  KEY `payment_gateway_events_invoice_id_index` (`invoice_id`),
  KEY `payment_gateway_events_invoice_transaction_id_index` (`invoice_transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_group_invoice`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_group_invoice` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_group_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL,
  `amount` decimal(17,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_group_invoice_payment_group_id_invoice_id_unique` (`payment_group_id`,`invoice_id`),
  KEY `payment_group_invoice_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `payment_group_invoice_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_group_invoice_payment_group_id_foreign` FOREIGN KEY (`payment_group_id`) REFERENCES `payment_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ulid` char(26) NOT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `currency_code` varchar(3) NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `total` decimal(17,2) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'open',
  `invoice_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_groups_ulid_unique` (`ulid`),
  UNIQUE KEY `payment_groups_whmcs_invoice_id_unique` (`whmcs_invoice_id`),
  KEY `payment_groups_user_id_foreign` (`user_id`),
  KEY `payment_groups_invoice_id_foreign` (`invoice_id`),
  KEY `payment_groups_invoice_transaction_id_foreign` (`invoice_transaction_id`),
  KEY `payment_groups_client_id_status_index` (`client_id`,`status`),
  KEY `payment_groups_status_index` (`status`),
  CONSTRAINT `payment_groups_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_groups_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_groups_invoice_transaction_id_foreign` FOREIGN KEY (`invoice_transaction_id`) REFERENCES `invoice_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_groups_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paypal_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `paypal_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` varchar(64) NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `payee_merchant_id` varchar(64) DEFAULT NULL,
  `payee_email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paypal_orders_order_id_unique` (`order_id`),
  KEY `paypal_orders_invoice_id_index` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `priceable_type` varchar(255) NOT NULL,
  `priceable_id` bigint(20) unsigned NOT NULL,
  `type` enum('free','one-time','recurring') NOT NULL,
  `billing_period` int(11) DEFAULT NULL,
  `billing_unit` enum('hour','day','week','month','year') DEFAULT NULL,
  `sort` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plans_priceable_type_priceable_id_index` (`priceable_type`,`priceable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `price` decimal(17,2) DEFAULT NULL,
  `setup_fee` decimal(17,2) DEFAULT NULL,
  `currency_code` varchar(3) NOT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_upgrades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_upgrades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `upgrade_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_upgrades_product_id_foreign` (`product_id`),
  KEY `product_upgrades_upgrade_id_foreign` (`upgrade_id`),
  CONSTRAINT `product_upgrades_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_upgrades_upgrade_id_foreign` FOREIGN KEY (`upgrade_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `stock` int(11) DEFAULT NULL,
  `per_user_limit` int(11) DEFAULT NULL,
  `sort` tinyint(3) unsigned DEFAULT NULL,
  `allow_quantity` enum('disabled','separated','combined') NOT NULL DEFAULT 'disabled',
  `server_id` bigint(20) unsigned DEFAULT NULL,
  `server_group_id` bigint(20) unsigned DEFAULT NULL,
  `autosetup` varchar(8) NOT NULL DEFAULT 'payment',
  `email_template` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `hidden` tinyint(1) NOT NULL DEFAULT 0,
  `taxable` tinyint(1) NOT NULL DEFAULT 1,
  `prorata_day` tinyint(3) unsigned DEFAULT NULL,
  `prorata_next_after_day` tinyint(3) unsigned DEFAULT NULL,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `retired` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_whmcs_id_unique` (`whmcs_id`),
  KEY `products_category_id_foreign` (`category_id`),
  KEY `products_server_group_id_foreign` (`server_group_id`),
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `products_server_group_id_foreign` FOREIGN KEY (`server_group_id`) REFERENCES `server_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `properties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `custom_property_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `key` varchar(255) NOT NULL,
  `value` text NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `properties_key_model_id_model_type_unique` (`key`,`model_id`,`model_type`),
  KEY `properties_custom_property_id_foreign` (`custom_property_id`),
  KEY `properties_model_type_model_id_index` (`model_type`,`model_id`),
  CONSTRAINT `properties_custom_property_id_foreign` FOREIGN KEY (`custom_property_id`) REFERENCES `custom_properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `proxmox_guests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proxmox_guests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint(20) unsigned NOT NULL,
  `vmid` int(10) unsigned NOT NULL,
  `type` varchar(8) NOT NULL DEFAULT 'qemu',
  `node` varchar(255) DEFAULT NULL,
  `pool` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `status` varchar(32) DEFAULT NULL,
  `template` tinyint(1) NOT NULL DEFAULT 0,
  `cpus` int(10) unsigned NOT NULL DEFAULT 0,
  `max_memory` bigint(20) unsigned NOT NULL DEFAULT 0,
  `max_disk` bigint(20) unsigned NOT NULL DEFAULT 0,
  `uptime` bigint(20) unsigned NOT NULL DEFAULT 0,
  `macs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`macs`)),
  `ips` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ips`)),
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `gone_at` timestamp NULL DEFAULT NULL,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `internal` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(255) DEFAULT NULL,
  `onboot_before_suspend` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proxmox_guests_server_id_vmid_unique` (`server_id`,`vmid`),
  KEY `proxmox_guests_service_id_foreign` (`service_id`),
  CONSTRAINT `proxmox_guests_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `extensions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `proxmox_guests_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `server_group_server`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_group_server` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `server_group_id` bigint(20) unsigned NOT NULL,
  `server_id` bigint(20) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_group_server_server_group_id_server_id_unique` (`server_group_id`,`server_id`),
  KEY `server_group_server_server_id_foreign` (`server_id`),
  CONSTRAINT `server_group_server_server_group_id_foreign` FOREIGN KEY (`server_group_id`) REFERENCES `server_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `server_group_server_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `extensions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `server_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `fill_type` varchar(16) NOT NULL DEFAULT 'least_full',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_groups_whmcs_id_unique` (`whmcs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_cancellations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_cancellations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `type` enum('immediate','end_of_period') NOT NULL DEFAULT 'immediate',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_cancellations_service_id_unique` (`service_id`),
  CONSTRAINT `service_cancellations_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `configurable_type` varchar(255) NOT NULL,
  `configurable_id` bigint(20) unsigned NOT NULL,
  `config_option_id` bigint(20) unsigned NOT NULL,
  `config_value_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_configs_configurable_type_configurable_id_index` (`configurable_type`,`configurable_id`),
  KEY `service_configs_config_option_id_foreign` (`config_option_id`),
  KEY `service_configs_config_value_id_foreign` (`config_value_id`),
  CONSTRAINT `service_configs_config_option_id_foreign` FOREIGN KEY (`config_option_id`) REFERENCES `config_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_configs_config_value_id_foreign` FOREIGN KEY (`config_value_id`) REFERENCES `config_options` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_hosted_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_hosted_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `domain` varchar(253) NOT NULL,
  `type` varchar(16) NOT NULL,
  `account` varchar(64) DEFAULT NULL,
  `read_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_hosted_domains_service_id_domain_unique` (`service_id`,`domain`),
  KEY `service_hosted_domains_domain_index` (`domain`),
  CONSTRAINT `service_hosted_domains_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_upgrades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_upgrades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `type` varchar(255) NOT NULL DEFAULT 'product',
  `credit_cents` bigint(20) unsigned DEFAULT NULL,
  `credit_until` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_upgrades_service_id_foreign` (`service_id`),
  KEY `service_upgrades_invoice_id_foreign` (`invoice_id`),
  KEY `service_upgrades_plan_id_foreign` (`plan_id`),
  KEY `service_upgrades_product_id_foreign` (`product_id`),
  CONSTRAINT `service_upgrades_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_upgrades_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `service_upgrades_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `service_upgrades_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_usages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_usages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `disk_used_mb` bigint(20) unsigned DEFAULT NULL,
  `disk_limit_mb` bigint(20) unsigned DEFAULT NULL,
  `bandwidth_used_mb` bigint(20) unsigned DEFAULT NULL,
  `bandwidth_limit_mb` bigint(20) unsigned DEFAULT NULL,
  `accounts` int(10) unsigned DEFAULT NULL,
  `accounts_limit` int(10) unsigned DEFAULT NULL,
  `accounts_kind` varchar(16) NOT NULL DEFAULT 'accounts',
  `server_ip` varchar(45) DEFAULT NULL,
  `read_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_usages_service_id_unique` (`service_id`),
  CONSTRAINT `service_usages_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `server_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `currency_code` varchar(3) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(17,2) NOT NULL,
  `group_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `price_locked` tinyint(1) NOT NULL DEFAULT 0,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `next_plan_id` bigint(20) unsigned DEFAULT NULL,
  `coupon_id` bigint(20) unsigned DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `billing_day` tinyint(3) unsigned DEFAULT NULL,
  `suspend_hold_until` date DEFAULT NULL,
  `subscription_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `password` text DEFAULT NULL,
  `dedicated_ip` varchar(45) DEFAULT NULL,
  `assigned_ips` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `suspend_reason` varchar(255) DEFAULT NULL,
  `coupon_started_at` date DEFAULT NULL,
  `coupon_expired_at` timestamp NULL DEFAULT NULL,
  `dunning_suspended_at` timestamp NULL DEFAULT NULL,
  `started_unpaid_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `services_coupon_id_foreign` (`coupon_id`),
  KEY `services_plan_id_foreign` (`plan_id`),
  KEY `services_product_id_foreign` (`product_id`),
  KEY `services_order_id_foreign` (`order_id`),
  KEY `services_client_id_foreign` (`client_id`),
  KEY `services_domain_index` (`domain`),
  KEY `services_dedicated_ip_index` (`dedicated_ip`),
  KEY `services_server_id_foreign` (`server_id`),
  KEY `services_next_plan_id_foreign` (`next_plan_id`),
  KEY `services_contact_id_foreign` (`contact_id`),
  KEY `services_status_expires_at_index` (`status`,`expires_at`),
  CONSTRAINT `services_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `services_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `services_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `services_next_plan_id_foreign` FOREIGN KEY (`next_plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `services_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `services_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `services_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `services_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `extensions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'string',
  `encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `settingable_type` varchar(255) DEFAULT NULL,
  `settingable_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `scope` varchar(191) GENERATED ALWAYS AS (concat(coalesce(`settingable_type`,''),'#',coalesce(`settingable_id`,0))) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_settingable_id_settingable_type_unique` (`key`,`settingable_id`,`settingable_type`),
  UNIQUE KEY `settings_key_scope_unique` (`key`,`scope`),
  KEY `settings_settingable_type_settingable_id_index` (`settingable_type`,`settingable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ssl_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ssl_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `connection_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `period` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `common_name` varchar(255) DEFAULT NULL,
  `host_names` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`host_names`)),
  `csr` text DEFAULT NULL,
  `validation_method` varchar(16) DEFAULT NULL,
  `approver_email` varchar(255) DEFAULT NULL,
  `organization_handle` varchar(64) DEFAULT NULL,
  `status` varchar(16) DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `reissue_at` timestamp NULL DEFAULT NULL,
  `order_ends_at` timestamp NULL DEFAULT NULL,
  `certificate` text DEFAULT NULL,
  `intermediate` text DEFAULT NULL,
  `validation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation`)),
  `synced_at` timestamp NULL DEFAULT NULL,
  `sync_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `renew_requested_at` timestamp NULL DEFAULT NULL,
  `renew_for` timestamp NULL DEFAULT NULL,
  `renew_answered_at` timestamp NULL DEFAULT NULL,
  `previous_order_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`previous_order_ids`)),
  `notified_hash` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ssl_orders_service_id_unique` (`service_id`),
  UNIQUE KEY `ssl_orders_connection_id_order_id_unique` (`connection_id`,`order_id`),
  CONSTRAINT `ssl_orders_connection_id_foreign` FOREIGN KEY (`connection_id`) REFERENCES `domain_registrar_connections` (`id`),
  CONSTRAINT `ssl_orders_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ssl_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ssl_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ssl_order_id` bigint(20) unsigned NOT NULL,
  `number` smallint(5) unsigned NOT NULL,
  `source` varchar(16) NOT NULL,
  `key_type` varchar(16) DEFAULT NULL,
  `key_description` varchar(32) DEFAULT NULL,
  `private_key` text DEFAULT NULL,
  `spki_sha256` char(64) DEFAULT NULL,
  `csr` text DEFAULT NULL,
  `common_name` varchar(255) DEFAULT NULL,
  `host_names` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`host_names`)),
  `certificate` text DEFAULT NULL,
  `chain` text DEFAULT NULL,
  `serial` varchar(64) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `ordered_at` timestamp NULL DEFAULT NULL,
  `creator_type` varchar(255) DEFAULT NULL,
  `creator_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ssl_versions_ssl_order_id_number_unique` (`ssl_order_id`,`number`),
  KEY `ssl_versions_creator_type_creator_id_index` (`creator_type`,`creator_id`),
  KEY `ssl_versions_spki_sha256_index` (`spki_sha256`),
  CONSTRAINT `ssl_versions_ssl_order_id_foreign` FOREIGN KEY (`ssl_order_id`) REFERENCES `ssl_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role_id` bigint(20) unsigned DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `app_authentication_secret` text DEFAULT NULL,
  `app_authentication_recovery_codes` text DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `presence_status` varchar(16) NOT NULL DEFAULT 'available',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_email_unique` (`email`),
  KEY `staff_role_id_foreign` (`role_id`),
  CONSTRAINT `staff_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` bigint(20) unsigned NOT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `staff_messages_staff_id_foreign` (`staff_id`),
  KEY `staff_messages_thread_id_id_index` (`thread_id`,`id`),
  CONSTRAINT `staff_messages_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`),
  CONSTRAINT `staff_messages_thread_id_foreign` FOREIGN KEY (`thread_id`) REFERENCES `staff_threads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ulid` char(26) NOT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `remembered` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `terminated` tinyint(1) NOT NULL DEFAULT 0,
  `ended_by_staff_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_sessions_ulid_unique` (`ulid`),
  KEY `staff_sessions_ended_by_staff_id_foreign` (`ended_by_staff_id`),
  KEY `staff_sessions_staff_id_ended_at_index` (`staff_id`,`ended_at`),
  KEY `staff_sessions_session_id_index` (`session_id`),
  CONSTRAINT `staff_sessions_ended_by_staff_id_foreign` FOREIGN KEY (`ended_by_staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_sessions_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_thread_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_thread_members` (
  `thread_id` bigint(20) unsigned NOT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  `last_read_message_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`thread_id`,`staff_id`),
  KEY `staff_thread_members_staff_id_foreign` (`staff_id`),
  CONSTRAINT `staff_thread_members_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_thread_members_thread_id_foreign` FOREIGN KEY (`thread_id`) REFERENCES `staff_threads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_threads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_threads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(16) NOT NULL,
  `direct_key` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_threads_direct_key_unique` (`direct_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tax_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `rate` decimal(5,2) NOT NULL,
  `country` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tax_rates_country_unique` (`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_blocked_senders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_blocked_senders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `value` varchar(255) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_blocked_senders_value_unique` (`value`),
  KEY `ticket_blocked_senders_created_by_foreign` (`created_by`),
  CONSTRAINT `ticket_blocked_senders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_ccs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_ccs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `added_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_ccs_ticket_id_email_unique` (`ticket_id`,`email`),
  KEY `ticket_ccs_added_by_foreign` (`added_by`),
  CONSTRAINT `ticket_ccs_added_by_foreign` FOREIGN KEY (`added_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ticket_ccs_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_department_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_department_staff` (
  `ticket_department_id` bigint(20) unsigned NOT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`ticket_department_id`,`staff_id`),
  KEY `ticket_department_staff_staff_id_foreign` (`staff_id`),
  CONSTRAINT `ticket_department_staff_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_department_staff_ticket_department_id_foreign` FOREIGN KEY (`ticket_department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `hidden` tinyint(1) NOT NULL DEFAULT 0,
  `clients_only` tinyint(1) NOT NULL DEFAULT 0,
  `email` varchar(255) DEFAULT NULL,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `imap_host` varchar(255) DEFAULT NULL,
  `imap_port` smallint(5) unsigned DEFAULT NULL,
  `imap_encryption` varchar(10) NOT NULL DEFAULT 'ssl',
  `imap_username` varchar(255) DEFAULT NULL,
  `imap_password` text DEFAULT NULL,
  `imap_folder` varchar(255) NOT NULL DEFAULT 'INBOX',
  `authserv_id` varchar(255) DEFAULT NULL,
  `require_dmarc` tinyint(1) NOT NULL DEFAULT 1,
  `polling` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_departments_whmcs_id_unique` (`whmcs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_guest_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_guest_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_guest_links_token_hash_unique` (`token_hash`),
  KEY `ticket_guest_links_ticket_id_foreign` (`ticket_id`),
  CONSTRAINT `ticket_guest_links_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_mail_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_mail_ids` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `ticket_message_id` bigint(20) unsigned DEFAULT NULL,
  `message_id` varchar(255) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_mail_ids_message_id_unique` (`message_id`),
  KEY `ticket_mail_ids_ticket_id_foreign` (`ticket_id`),
  KEY `ticket_mail_ids_ticket_message_id_foreign` (`ticket_message_id`),
  CONSTRAINT `ticket_mail_ids_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_mail_ids_ticket_message_id_foreign` FOREIGN KEY (`ticket_message_id`) REFERENCES `ticket_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_mail_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_mail_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `from_email` varchar(250) DEFAULT NULL,
  `from_name` varchar(250) DEFAULT NULL,
  `subject` varchar(250) DEFAULT NULL,
  `outcome` varchar(16) NOT NULL,
  `reason` varchar(32) DEFAULT NULL,
  `ticket_id` bigint(20) unsigned DEFAULT NULL,
  `quarantine_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_mail_imports_department_id_foreign` (`department_id`),
  KEY `ticket_mail_imports_ticket_id_foreign` (`ticket_id`),
  KEY `ticket_mail_imports_quarantine_id_foreign` (`quarantine_id`),
  KEY `ticket_mail_imports_created_at_index` (`created_at`),
  CONSTRAINT `ticket_mail_imports_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ticket_mail_imports_quarantine_id_foreign` FOREIGN KEY (`quarantine_id`) REFERENCES `ticket_quarantine` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ticket_mail_imports_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_message_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_message_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` uuid NOT NULL,
  `filename` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL,
  `filesize` bigint(20) unsigned NOT NULL,
  `mime_type` varchar(255) NOT NULL,
  `ticket_message_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_message_attachments_ticket_message_id_foreign` (`ticket_message_id`),
  CONSTRAINT `ticket_message_attachments_ticket_message_id_foreign` FOREIGN KEY (`ticket_message_id`) REFERENCES `ticket_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `sender_email` varchar(255) DEFAULT NULL,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `email_message_id` varchar(255) DEFAULT NULL,
  `via` varchar(16) DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `body_original` longtext DEFAULT NULL,
  `whmcs_ref` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_messages_email_message_id_unique` (`email_message_id`),
  UNIQUE KEY `ticket_messages_whmcs_ref_unique` (`whmcs_ref`),
  KEY `ticket_messages_staff_id_foreign` (`staff_id`),
  KEY `ticket_messages_user_id_foreign` (`user_id`),
  KEY `ticket_messages_ticket_id_created_at_index` (`ticket_id`,`created_at`),
  CONSTRAINT `ticket_messages_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `ticket_messages_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_poll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_poll_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned NOT NULL,
  `started_at` timestamp NOT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `connected` tinyint(1) NOT NULL DEFAULT 0,
  `fetched` int(10) unsigned NOT NULL DEFAULT 0,
  `threaded` int(10) unsigned NOT NULL DEFAULT 0,
  `opened` int(10) unsigned NOT NULL DEFAULT 0,
  `quarantined` int(10) unsigned NOT NULL DEFAULT 0,
  `skipped` int(10) unsigned NOT NULL DEFAULT 0,
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`errors`)),
  PRIMARY KEY (`id`),
  KEY `ticket_poll_runs_department_id_started_at_index` (`department_id`,`started_at`),
  CONSTRAINT `ticket_poll_runs_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_quarantine`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_quarantine` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(40) NOT NULL,
  `message_id` varchar(255) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `authenticated` tinyint(1) NOT NULL DEFAULT 0,
  `auth_detail` varchar(40) DEFAULT NULL,
  `preview` text DEFAULT NULL,
  `refused_files` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`refused_files`)),
  `raw_path` varchar(255) DEFAULT NULL,
  `raw_size` int(10) unsigned NOT NULL DEFAULT 0,
  `suggested_ticket_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `ticket_id` bigint(20) unsigned DEFAULT NULL,
  `handled_by` bigint(20) unsigned DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_quarantine_message_id_unique` (`message_id`),
  KEY `ticket_quarantine_department_id_foreign` (`department_id`),
  KEY `ticket_quarantine_suggested_ticket_id_foreign` (`suggested_ticket_id`),
  KEY `ticket_quarantine_ticket_id_foreign` (`ticket_id`),
  KEY `ticket_quarantine_handled_by_foreign` (`handled_by`),
  KEY `ticket_quarantine_from_email_index` (`from_email`),
  KEY `ticket_quarantine_status_index` (`status`),
  CONSTRAINT `ticket_quarantine_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ticket_quarantine_handled_by_foreign` FOREIGN KEY (`handled_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ticket_quarantine_suggested_ticket_id_foreign` FOREIGN KEY (`suggested_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ticket_quarantine_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_whmcs_tombstones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_whmcs_tombstones` (
  `ref` varchar(32) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(64) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'open',
  `pinned_at` timestamp NULL DEFAULT NULL,
  `priority` varchar(255) NOT NULL DEFAULT 'normal',
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` bigint(20) unsigned DEFAULT NULL,
  `guest_name` varchar(255) DEFAULT NULL,
  `guest_email` varchar(255) DEFAULT NULL,
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `merged_into_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `opened_via` varchar(16) DEFAULT NULL,
  `sender_vouched` tinyint(1) DEFAULT NULL,
  `whmcs_id` int(10) unsigned DEFAULT NULL,
  `whmcs_hash` char(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tickets_reference_unique` (`reference`),
  UNIQUE KEY `tickets_whmcs_id_unique` (`whmcs_id`),
  KEY `tickets_service_id_foreign` (`service_id`),
  KEY `tickets_assigned_to_foreign` (`assigned_to`),
  KEY `tickets_client_id_foreign` (`client_id`),
  KEY `tickets_department_id_foreign` (`department_id`),
  KEY `tickets_merged_into_id_foreign` (`merged_into_id`),
  KEY `tickets_status_id_index` (`status`,`id`),
  KEY `tickets_created_at_index` (`created_at`),
  CONSTRAINT `tickets_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_merged_into_id_foreign` FOREIGN KEY (`merged_into_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_authentication_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_authentication_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `last_used_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_authentication_logs_user_id_ip_address_index` (`user_id`,`ip_address`),
  CONSTRAINT `user_authentication_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ulid` char(26) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `last_activity` timestamp NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_sessions_ulid_unique` (`ulid`),
  KEY `user_sessions_user_id_foreign` (`user_id`),
  CONSTRAINT `user_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `tfa_secret` text DEFAULT NULL,
  `tfa_recovery_codes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `language` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vat_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vat_checks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `vat_number` varchar(50) NOT NULL,
  `result` varchar(16) NOT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'vies',
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `request_identifier` varchar(255) DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `checked_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vat_checks_staff_id_foreign` (`staff_id`),
  KEY `vat_checks_contact_id_checked_at_index` (`contact_id`,`checked_at`),
  CONSTRAINT `vat_checks_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vat_checks_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whmcs_ip_pool_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whmcs_ip_pool_imports` (
  `whmcs_id` int(10) unsigned NOT NULL,
  `imported_at` timestamp NOT NULL,
  PRIMARY KEY (`whmcs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `zlic_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `zlic_limits` (
  `key` varchar(120) NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `expires_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`key`),
  KEY `zlic_limits_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

/*M!999999\- enable the sandbox mode */ 
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2024_02_15_122223_create_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2024_02_15_122224_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2024_02_15_122225_create_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2024_02_15_122227_create_extensions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2024_02_15_122228_create_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2024_02_15_122231_create_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2024_02_15_122232_create_currencies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2024_02_15_122233_create_plans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2024_02_15_122235_create_prices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2024_04_20_111206_create_audit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2024_06_19_143154_create_config_options_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2024_06_27_122914_create_config_option_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2024_07_02_190049_create_tax_rates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2024_07_05_142906_create_coupons_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2024_07_05_143848_create_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2024_07_05_143851_create_services_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2024_07_05_143855_create_service_configs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2024_07_05_144020_create_invoices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2024_07_05_144024_create_invoice_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2024_07_10_203119_create_invoice_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2024_07_13_121420_create_coupon_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2024_07_15_134223_create_custom_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2024_07_15_134515_create_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2024_08_09_120929_create_tickets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2024_08_09_120938_create_ticket_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2024_08_13_185122_create_email_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2024_08_22_094747_create_email_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2024_09_13_193832_create_oauth_auth_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2024_09_13_193833_create_oauth_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2024_09_13_193834_create_oauth_refresh_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2024_09_13_193835_create_oauth_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2024_09_13_193836_create_oauth_personal_access_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2024_09_16_171549_create_service_cancellations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2024_09_16_171555_create_service_upgrades_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2024_09_17_111932_create_product_upgrades_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2024_12_26_185218_create_credits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2025_02_24_052318_make_invoice_nullable_in_service_upgrades',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2025_03_07_190949_create_debug_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2025_03_11_205629_add_hidden_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2025_04_25_064729_add_number_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2025_05_22_073500_add_max_uses_per_user_to_coupons_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2025_06_04_132119_create_api_keys_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2025_06_20_101701_add_upgradable_to_config_options',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2025_06_30_092448_cascade_config_value_id_on_service_configs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2025_07_31_165713_create_ticket_message_attachments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2025_08_02_150516_create_ticket_mail_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2025_08_04_181811_add_ticket_mail_log_id_to_ticket_messages',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2025_08_15_100111_change_gateway_id_on_invoice_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2025_08_15_132711_create_audits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2025_09_03_190940_add_name_to_currencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2025_09_12_163530_make_number_nullable_on_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2025_09_16_130604_create_invoice_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2025_09_19_140421_create_cron_stats_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2025_09_20_185200_add_applies_to_to_coupons',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2025_09_26_102234_create_carts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2025_09_26_102237_create_cart_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2025_09_27_182553_create_billing_agreements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2025_09_29_100814_add_deleted_at_to_extensions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2025_09_29_113738_add_billing_agreement_id_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2025_09_30_100549_add_status_to_invoice_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2025_10_04_183152_rename_email_templates_to_notification_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2025_10_06_161337_create_notification_preferences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2025_10_07_195108_create_notification_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2025_10_08_193743_create_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2025_10_14_140749_add_is_credit_transaction_to_invoice_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2025_10_27_210931_create_user_authentication_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2025_11_02_103925_add_description_to_config_options_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2025_12_13_125749_create_user_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2025_12_15_205656_drop_remember_token_from_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2025_12_17_125349_add_label_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_05_15_140242_change_default_invoice_status_to_draft',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_05_20_144000_create_adjustment_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_05_26_112630_add_refunded_amount_to_invoice_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_08_27_000000_add_suspend_hold_until_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_10_11_000000_templates_without_blade',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_10_11_100000_encrypt_extension_credentials',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_10_12_000000_add_billing_period_to_invoice_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_10_12_000100_add_billing_day_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_10_12_000200_add_applied_at_to_invoice_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_10_12_000300_add_coupon_state_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_10_12_000400_add_dunning_suspension_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_10_13_000000_add_credited_amount_to_invoice_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_10_13_000100_add_cancellation_marker_and_payment_source',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_10_14_000000_create_money_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_10_15_000000_credit_ledger',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_10_16_000000_forget_plaintext_settings_cache',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_10_17_000000_add_refund_marker_to_adjustment_notes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_10_18_000000_credit_auto_apply',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_10_19_000000_add_apply_pending_since_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_10_19_000000_create_payment_gateway_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_10_20_000000_restrict_deleting_plans_and_products_in_use',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_10_21_000000_rename_invoice_status_pending_to_unpaid',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_10_21_000000_unique_keys_and_long_line_descriptions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_10_22_000000_restrict_deleting_config_values_in_use',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_10_23_000000_create_gateway_refunds_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_10_23_000000_restrict_deleting_orders_with_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_10_24_000000_gateway_refunds_chargebacks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_10_24_000000_rename_dead_permission_names_in_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_10_25_000000_theme_settings_without_theme_name',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_10_26_000000_add_translations_to_notification_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_10_26_000000_split_staff_from_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_10_26_000001_new_notification_template_texts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_10_27_000000_create_invoice_reminders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_10_28_000000_split_clients_from_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_10_29_000000_create_client_invites_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_10_30_000000_create_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_10_31_000000_extensions_namespace_mycs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_11_01_000000_create_licenses_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_11_01_000000_net_amounts_and_tax_per_line',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_11_02_000000_add_notes_to_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_11_02_000000_add_whmcs_fields_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_11_02_000001_welcome_mail_login_details',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_11_03_000000_service_servers_and_server_groups',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_11_04_000000_create_module_actions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_11_04_000100_add_next_plan_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_11_05_000000_add_autosetup_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_11_05_000000_add_contact_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_11_05_000001_add_status_to_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_11_05_000002_add_activated_at_to_services',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_11_06_000000_add_forgone_at_to_invoice_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_11_06_000000_move_directadmin_passwords_out_of_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_11_07_000000_add_separate_invoices_to_clients',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_11_07_000000_license_keys_as_service_domains',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_11_07_000001_add_finish_pending_at_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_11_08_000000_move_saved_payment_method_to_clients',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_11_09_000000_add_prorata_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_11_09_000000_add_whmcs_id_to_license_access_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_11_09_000001_service_cancelled_notification',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_11_10_000000_add_merge_split_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_11_10_000000_mail_audit_on_email_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_11_11_000000_create_mcp_calls_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_11_11_000000_create_payment_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_11_12_000000_add_whmcs_invoice_id_to_payment_groups',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_11_12_000000_create_mcp_oauth_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_11_12_000001_mcp_oauth_review',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_11_13_000000_create_mcp_proposals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_11_13_000000_eu_vat',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_11_14_000000_create_charge_attempts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_11_15_000000_create_cardlink_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_11_16_000000_h2b_autocharge_flags',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_11_16_000000_suspension_mail_says_why',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_11_17_000000_create_domains_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_11_18_000000_whmcs_ids_on_the_catalog',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_11_19_000000_staff_on_domain_registrar_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_11_20_000000_quarantine_dates_on_domains',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_11_21_000000_kind_on_invoice_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_11_22_000000_domains_on_module_actions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_11_23_000000_forgone_domain_periods',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_11_24_000000_domain_notice_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_11_25_000000_domain_lines_without_period_are_charges',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_11_26_000000_user_on_domain_registrar_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_11_27_000000_domain_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_11_28_000000_domain_order_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_11_29_000000_domain_nameserver_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_11_30_000000_registrar_debug_log',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_12_01_000000_domain_transferred_template',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_12_02_000000_registrar_balances',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_12_03_000000_categories_show_in_menu',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_12_03_000001_products_retired',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_12_04_000000_services_started_unpaid_at',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_12_05_000000_orders_ip_address',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_12_05_000001_tickets_departments_guests_statuses',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_12_06_000000_create_client_notes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_12_06_000001_tickets_mailboxes_quarantine',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_12_07_000000_module_actions_subject',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_12_07_000001_tickets_outbound_mail',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_12_08_000000_zlic_limits',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_12_08_000001_tickets_guest_links',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_12_09_000001_tickets_whmcs_ids',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_12_10_000001_canned_replies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_12_10_000002_tickets_merge',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_12_10_000003_ticket_ccs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_12_11_000000_client_groups',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_12_11_000001_coupon_rules',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_12_12_000000_invoices_paid_at',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_12_13_000000_offers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_12_14_000000_bank_transfer_in_invoice_mail',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_12_15_000000_domain_renewed_and_transfer_failed_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_12_16_000000_bulk_mails',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_12_17_000000_config_options_staff_task',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_12_18_000000_registrar_renew_as_transfer',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_12_19_000000_drop_renew_transfer_except',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_12_20_000000_create_service_usages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_12_21_000000_create_service_hosted_domains_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_12_22_000000_add_credit_to_service_upgrades',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_12_22_000000_service_usages_accounts_kind',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_12_23_000000_create_paypal_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_12_24_000000_create_ssl_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_12_25_000000_ssl_orders_renewal_and_notice',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_12_26_000000_create_knowledgebase_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_12_26_000000_drop_passport_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_12_27_000000_add_tfa_recovery_codes_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_12_27_000000_staff_presence',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_12_28_000000_add_language_to_clients_and_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_12_28_000000_create_staff_chat',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_12_29_000000_create_staff_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_12_29_000000_invoice_mail_credit_line',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_12_29_000001_mcp_grants_staff_session',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_12_30_000000_add_server_ip_to_service_usages',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_12_31_000000_create_domain_owner_changes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2027_01_01_000000_create_ticket_mail_imports',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2027_01_02_000000_add_admin_list_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2027_01_02_000000_create_login_attempts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2027_01_03_000000_drop_unused_languages',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2027_01_05_000000_forget_cronjob_invoice_reminder',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2027_01_06_000000_create_ssl_versions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2027_01_07_000000_ekdosi_flags_on_clients',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2027_01_08_000000_create_proxmox_guests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2027_01_09_000000_proxmox_guest_pool',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2027_01_10_000000_create_bank_movements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2027_01_11_000000_dunning_flags_on_clients_and_groups',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2027_01_12_000000_create_chat_notification_rules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2027_01_13_000000_matching_on_bank_movements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2027_01_14_000000_create_ekdosi_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2027_01_14_000000_pinned_tickets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (217,'2027_01_15_000000_create_live_chats',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (218,'2027_01_15_000000_credit_note_counter_from_zero',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (219,'2027_01_16_000000_ekdosi_requested_on_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (220,'2027_01_17_000000_create_mailcow_inventory',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (221,'2027_01_18_000000_mailcow_domain_dkim',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (222,'2027_01_19_000000_create_cpanel_accounts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (223,'2027_01_20_000000_create_cpanel_lve_samples',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (224,'2027_01_21_000000_create_ip_pools',1);
