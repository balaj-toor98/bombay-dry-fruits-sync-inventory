-- Run once on existing Hostinger DB (phpMyAdmin → your database → SQL tab)
ALTER TABLE `products`
  ADD COLUMN `compare_at_price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00
    COMMENT 'CRM ProductRetailPrice → Shopify compare_at_price'
    AFTER `price`;
