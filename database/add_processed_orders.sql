-- Run once on existing databases (phpMyAdmin → your DB → Import)
-- Prevents duplicate stock deduction when Shopify/Foodpanda webhooks retry

CREATE TABLE IF NOT EXISTS `processed_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform` VARCHAR(16) NOT NULL COMMENT 'shopify or foodpanda',
  `external_order_id` VARCHAR(64) NOT NULL,
  `processed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_order` (`platform`, `external_order_id`),
  KEY `idx_processed_at` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
