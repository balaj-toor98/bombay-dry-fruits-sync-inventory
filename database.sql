-- Bombay Dry Fruits - Inventory Sync Middleware
--
-- HOSTINGER: Do NOT run CREATE DATABASE here.
-- 1. Create DB in hPanel (e.g. u681832676_inventory_sync)
-- 2. phpMyAdmin → click YOUR database in the left sidebar
-- 3. Import this file (only tables will be created)
--
-- Local/dev: create empty DB first, select it, then import.

-- Products (local cache from CRM, synced to Shopify + Foodpanda)
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` VARCHAR(64) NOT NULL COMMENT 'CRM ProductId',
  `sku` VARCHAR(128) NOT NULL COMMENT 'ProductBarcode / SKU',
  `name` VARCHAR(512) NOT NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `last_update` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_id` (`product_id`),
  UNIQUE KEY `uk_sku` (`sku`),
  KEY `idx_stock` (`stock`),
  KEY `idx_last_update` (`last_update`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Application logs
CREATE TABLE IF NOT EXISTS `logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(32) NOT NULL COMMENT 'info, warning, error, sync, webhook, cron',
  `message` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track last successful sync timestamps
CREATE TABLE IF NOT EXISTS `sync_meta` (
  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `last_crm_fetch` DATETIME NULL,
  `last_shopify_sync` DATETIME NULL,
  `last_foodpanda_sync` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sync_meta` (`id`) VALUES (1)
  ON DUPLICATE KEY UPDATE `id` = `id`;

-- Foodpanda async catalog job tracking (PUT /catalog, export)
CREATE TABLE IF NOT EXISTS `foodpanda_jobs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id` VARCHAR(64) NOT NULL,
  `job_status` VARCHAR(32) NOT NULL DEFAULT 'QUEUED',
  `sku_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_job_id` (`job_id`),
  KEY `idx_job_status` (`job_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
