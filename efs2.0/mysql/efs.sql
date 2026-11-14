-- Ezy Filling System (EFS) 1.4.0 - MySQL / MariaDB schema
-- Select or create an EMPTY database in phpMyAdmin, then Import this file.
-- XAMPP setup.php can install this automatically. No DROP DATABASE / DROP TABLE.
-- PHP PDO MySQL; MySQL 5.7+ / MariaDB 10.3+. InnoDB; utf8mb4.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` BIGINT NOT NULL,
  `applied_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(100) NOT NULL,
  `value` LONGTEXT NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(32) COLLATE utf8mb4_general_ci NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_category_code` (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `users` (
  `id` VARCHAR(64) NOT NULL,
  `username` VARCHAR(64) COLLATE utf8mb4_general_ci NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(24) NOT NULL,
  `active` BIGINT NOT NULL DEFAULT 1,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  CHECK (role IN ('admin','manager','cashier','viewer')),
  CHECK (active IN(0,1))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `bucket` VARCHAR(64) NOT NULL,
  `attempts` BIGINT NOT NULL,
  `locked_until` BIGINT NOT NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fuel_types` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `color` VARCHAR(16) NOT NULL DEFAULT '#27cdb4',
  `active` BIGINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fuel_types_code` (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fuel_prices` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `fuel_type_id` VARCHAR(64) NOT NULL,
  `price_cents` BIGINT NOT NULL,
  `effective_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prices_effective` (`fuel_type_id`,`effective_at`),
  CHECK (price_cents>0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `tanks` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `fuel_type_id` VARCHAR(64) NOT NULL,
  `capacity_ml` BIGINT NOT NULL,
  `reorder_ml` BIGINT NOT NULL DEFAULT 0,
  `cost_cents_per_l` BIGINT NOT NULL DEFAULT 0,
  `active` BIGINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tanks_code` (`code`),
  CHECK (capacity_ml>0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `pumps` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `active` BIGINT NOT NULL DEFAULT 1,
  `description` VARCHAR(400) NOT NULL DEFAULT '',
  `start_meter_ml` BIGINT NOT NULL DEFAULT 0,
  `end_meter_ml` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pumps_code` (`code`),
  CHECK (start_meter_ml>=0 AND end_meter_ml>=start_meter_ml)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `nozzles` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `pump_id` VARCHAR(64) NOT NULL,
  `tank_id` VARCHAR(64) NOT NULL,
  `last_meter_ml` BIGINT NOT NULL DEFAULT 0,
  `active` BIGINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nozzles_code` (`code`),
  CHECK (last_meter_ml>=0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` VARCHAR(64) NOT NULL,
  `tank_id` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `quantity_ml` BIGINT NOT NULL,
  `reference_id` VARCHAR(64) NOT NULL,
  `reason` LONGTEXT NOT NULL,
  `created_by` VARCHAR(64) NULL,
  `occurred_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_tank` (`tank_id`,`occurred_at`),
  CHECK (kind IN('opening','sale','delivery','test_out','test_return','adjustment'))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `calibration_charts` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `tank_id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `valid_from` VARCHAR(32) NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `calibration_points` (
  `chart_id` VARCHAR(64) NOT NULL,
  `dip_mm` BIGINT NOT NULL,
  `volume_ml` BIGINT NOT NULL,
  PRIMARY KEY (`chart_id`,`dip_mm`),
  CHECK (dip_mm>=0),
  CHECK (volume_ml>=0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `dip_records` (
  `id` VARCHAR(64) NOT NULL,
  `tank_id` VARCHAR(64) NOT NULL,
  `chart_id` VARCHAR(64) NOT NULL,
  `dip_mm` BIGINT NOT NULL,
  `water_mm` BIGINT NOT NULL DEFAULT 0,
  `gross_ml` BIGINT NOT NULL,
  `water_ml` BIGINT NOT NULL,
  `physical_ml` BIGINT NOT NULL,
  `book_ml` BIGINT NOT NULL,
  `variance_ml` BIGINT NOT NULL,
  `temperature_milli_c` BIGINT NULL,
  `density_milli_kg_m3` BIGINT NULL,
  `density_15_milli_kg_m3` BIGINT NULL,
  `density_method` VARCHAR(400) NULL,
  `notes` VARCHAR(4000) NOT NULL DEFAULT '',
  `measured_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `customers` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `phone` VARCHAR(80) NOT NULL DEFAULT '',
  `credit_limit_cents` BIGINT NOT NULL DEFAULT 0,
  `credit_days` BIGINT NOT NULL DEFAULT 30,
  `max_unpaid` BIGINT NOT NULL DEFAULT 50,
  `active` BIGINT NOT NULL DEFAULT 1,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_code` (`code`),
  CHECK (credit_limit_cents>=0),
  CHECK (credit_days>=0),
  CHECK (max_unpaid>0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` VARCHAR(64) NOT NULL,
  `customer_id` VARCHAR(64) NOT NULL,
  `registration` VARCHAR(40) COLLATE utf8mb4_general_ci NOT NULL,
  `driver` VARCHAR(160) NOT NULL DEFAULT '',
  `fuel_type_id` VARCHAR(64) NULL,
  `max_fill_ml` BIGINT NOT NULL DEFAULT 0,
  `last_odometer` BIGINT NULL,
  `active` BIGINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicles_registration` (`registration`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `phone` VARCHAR(80) NOT NULL DEFAULT '',
  `created_at` VARCHAR(32) NOT NULL,
  `code` VARCHAR(64) COLLATE utf8mb4_general_ci NOT NULL,
  `address` VARCHAR(1000) NOT NULL DEFAULT '',
  `city` VARCHAR(160) NOT NULL DEFAULT '',
  `opening_balance_cents` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_code` (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `shifts` (
  `id` VARCHAR(64) NOT NULL,
  `label` VARCHAR(60) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `opened_at` VARCHAR(32) NOT NULL,
  `closed_at` VARCHAR(32) NULL,
  `opening_cash_cents` BIGINT NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `closed_by` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  `open_user` VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN `status`='open' THEN `user_id` ELSE NULL END) STORED,
  UNIQUE KEY `idx_one_open_shift` (`open_user`),
  CHECK (opening_cash_cents>=0),
  CHECK (status IN('open','closed'))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `shift_nozzles` (
  `id` VARCHAR(64) NOT NULL,
  `shift_id` VARCHAR(64) NOT NULL,
  `nozzle_id` VARCHAR(64) NOT NULL,
  `opening_ml` BIGINT NOT NULL,
  `closing_ml` BIGINT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shift_nozzles_shift_id_nozzle_id` (`shift_id`,`nozzle_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `meter_periods` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `shift_nozzle_id` VARCHAR(64) NOT NULL,
  `opening_ml` BIGINT NOT NULL,
  `closing_ml` BIGINT NULL,
  `rate_cents` BIGINT NOT NULL,
  `started_at` VARCHAR(32) NOT NULL,
  `ended_at` VARCHAR(32) NULL,
  PRIMARY KEY (`id`),
  `open_period` VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN `ended_at` IS NULL THEN `shift_nozzle_id` ELSE NULL END) STORED,
  UNIQUE KEY `idx_period_open` (`open_period`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fuel_tests` (
  `id` VARCHAR(64) NOT NULL,
  `meter_period_id` VARCHAR(64) NOT NULL,
  `quantity_ml` BIGINT NOT NULL,
  `returned_ml` BIGINT NOT NULL,
  `reason` LONGTEXT NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  CHECK (quantity_ml>0),
  CHECK (returned_ml>=0 AND returned_ml<=quantity_ml)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `products` (
  `id` VARCHAR(64) NOT NULL,
  `sku` VARCHAR(40) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `unit` VARCHAR(32) NOT NULL DEFAULT 'each',
  `price_cents` BIGINT NOT NULL,
  `cost_cents` BIGINT NOT NULL,
  `stock_milli` BIGINT NOT NULL DEFAULT 0,
  `active` BIGINT NOT NULL DEFAULT 1,
  `category_id` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  CHECK (price_cents>=0),
  CHECK (cost_cents>=0),
  CHECK (stock_milli>=0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `sales` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `receipt_no` VARCHAR(64) NOT NULL,
  `shift_id` VARCHAR(64) NOT NULL,
  `customer_id` VARCHAR(64) NULL,
  `vehicle_id` VARCHAR(64) NULL,
  `driver_name` VARCHAR(160) NOT NULL DEFAULT '',
  `indent_no` VARCHAR(160) NOT NULL DEFAULT '',
  `odometer` BIGINT NULL,
  `full_tank` BIGINT NOT NULL DEFAULT 0,
  `source` VARCHAR(32) NOT NULL DEFAULT 'pos',
  `total_cents` BIGINT NOT NULL,
  `cost_cents` BIGINT NOT NULL DEFAULT 0,
  `created_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sales_customer` (`customer_id`,`created_at`),
  KEY `idx_sales_shift` (`shift_id`,`created_at`),
  UNIQUE KEY `uq_sales_receipt_no` (`receipt_no`),
  UNIQUE KEY `uq_sales_idempotency_key` (`idempotency_key`),
  `credit_indent` VARCHAR(160) GENERATED ALWAYS AS (NULLIF(`indent_no`,'')) STORED,
  UNIQUE KEY `idx_indent_unique` (`customer_id`,`credit_indent`),
  CHECK (source IN('pos','meter_settlement')),
  CHECK (total_cents>0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `sale_requests` (
  `idempotency_key` VARCHAR(100) NOT NULL,
  `request_hash` VARCHAR(64) NOT NULL,
  `sale_id` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`idempotency_key`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` VARCHAR(64) NOT NULL,
  `sale_id` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `nozzle_id` VARCHAR(64) NULL,
  `product_id` VARCHAR(64) NULL,
  `meter_period_id` VARCHAR(64) NULL,
  `description` LONGTEXT NOT NULL,
  `quantity_milli` BIGINT NOT NULL,
  `unit_price_cents` BIGINT NOT NULL,
  `line_cents` BIGINT NOT NULL,
  `cost_cents` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_item_period` (`meter_period_id`),
  CHECK (kind IN('fuel','product')),
  CHECK (quantity_milli>0),
  CHECK (unit_price_cents>=0),
  CHECK (line_cents>=0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `sale_payments` (
  `id` VARCHAR(64) NOT NULL,
  `sale_id` VARCHAR(64) NOT NULL,
  `method` VARCHAR(24) NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  `reference` VARCHAR(400) NOT NULL DEFAULT '',
  `confirmed` BIGINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  CHECK (method IN('cash','card','qr','credit')),
  CHECK (amount_cents>0),
  CHECK (confirmed IN(0,1))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `customer_ledger` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `customer_id` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `debit_cents` BIGINT NOT NULL DEFAULT 0,
  `credit_cents` BIGINT NOT NULL DEFAULT 0,
  `sale_id` VARCHAR(64) NULL,
  `reference` VARCHAR(400) NOT NULL,
  `occurred_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  `payment_method` VARCHAR(24) NULL,
  `bank_account_id` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  CHECK (kind IN('invoice','receipt','opening')),
  CHECK (debit_cents>=0),
  CHECK (credit_cents>=0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `customer_allocations` (
  `id` VARCHAR(64) NOT NULL,
  `receipt_entry_id` VARCHAR(64) NOT NULL,
  `invoice_entry_id` VARCHAR(64) NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  CHECK (amount_cents>0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `cash_movements` (
  `id` VARCHAR(64) NOT NULL,
  `shift_id` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  `reason` LONGTEXT NOT NULL,
  `reference_id` VARCHAR(64) NULL,
  `created_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  CHECK (kind IN('expense','drop','paid_in','credit_receipt'))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` VARCHAR(64) NOT NULL,
  `shift_id` VARCHAR(64) NULL,
  `category` LONGTEXT NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  `payment_method` VARCHAR(24) NOT NULL,
  `note` LONGTEXT NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  `bank_account_id` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  CHECK (amount_cents>0),
  CHECK (payment_method IN('cash','bank'))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `deliveries` (
  `id` VARCHAR(64) NOT NULL,
  `tank_id` VARCHAR(64) NOT NULL,
  `supplier_id` VARCHAR(64) NOT NULL,
  `invoice_no` VARCHAR(80) NOT NULL,
  `quantity_ml` BIGINT NOT NULL,
  `cost_cents_per_l` BIGINT NOT NULL,
  `total_cents` BIGINT NOT NULL,
  `paid_cents` BIGINT NOT NULL DEFAULT 0,
  `notes` VARCHAR(4000) NOT NULL DEFAULT '',
  `received_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  `bank_account_id` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deliveries_supplier_id_invoice_no` (`supplier_id`,`invoice_no`),
  CHECK (quantity_ml>0),
  CHECK (cost_cents_per_l>=0),
  CHECK (paid_cents>=0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `supplier_payments` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `supplier_id` VARCHAR(64) NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  `reference` VARCHAR(400) NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  `bank_account_id` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  CHECK (amount_cents>0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `settlements` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `shift_id` VARCHAR(64) NOT NULL,
  `expected_sales_cents` BIGINT NOT NULL,
  `expected_cash_cents` BIGINT NOT NULL,
  `counted_cash_cents` BIGINT NOT NULL,
  `card_expected_cents` BIGINT NOT NULL,
  `card_counted_cents` BIGINT NOT NULL,
  `qr_expected_cents` BIGINT NOT NULL,
  `qr_counted_cents` BIGINT NOT NULL,
  `credit_cents` BIGINT NOT NULL,
  `variance_cents` BIGINT NOT NULL,
  `note` LONGTEXT NOT NULL,
  `approved_by` VARCHAR(64) NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settlements_shift_id` (`shift_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `staff_variances` (
  `id` VARCHAR(64) NOT NULL,
  `settlement_id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'review',
  `note` LONGTEXT NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  CHECK (status IN('review','acknowledged'))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(64) NOT NULL,
  `entity_id` VARCHAR(64) NOT NULL,
  `details_json` LONGTEXT NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `sync_outbox` (
  `seq` BIGINT NOT NULL AUTO_INCREMENT,
  `event_id` VARCHAR(64) NOT NULL,
  `aggregate_type` VARCHAR(64) NOT NULL,
  `aggregate_id` VARCHAR(64) NOT NULL,
  `payload_json` LONGTEXT NOT NULL,
  `payload_sha256` VARCHAR(64) NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  `sent_at` VARCHAR(32) NULL,
  `attempts` BIGINT NOT NULL DEFAULT 0,
  `last_error` LONGTEXT NULL,
  PRIMARY KEY (`seq`),
  UNIQUE KEY `uq_sync_outbox_event_id` (`event_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `license_cache` (
  `id` BIGINT NOT NULL,
  `license_key` VARCHAR(100) NOT NULL,
  `signed_payload` LONGTEXT NOT NULL,
  `signature` VARCHAR(255) NOT NULL,
  `received_at` BIGINT NOT NULL,
  `last_seen_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  CHECK (id=1)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `backup_history` (
  `id` VARCHAR(64) NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `size_bytes` BIGINT NOT NULL,
  `sha256` VARCHAR(64) NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `employees` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(32) COLLATE utf8mb4_general_ci NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `phone` VARCHAR(80) NOT NULL DEFAULT '',
  `designation` VARCHAR(160) NOT NULL DEFAULT 'Attendant',
  `user_id` VARCHAR(64) NULL,
  `active` BIGINT NOT NULL DEFAULT 1,
  `created_at` VARCHAR(32) NOT NULL,
  `address` VARCHAR(1000) NOT NULL DEFAULT '',
  `shift_type` VARCHAR(8) NOT NULL DEFAULT 'day',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employees_user_id` (`user_id`),
  UNIQUE KEY `uq_employees_code` (`code`),
  CHECK (active IN(0,1)),
  CHECK (shift_type IN('day','night'))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(32) COLLATE utf8mb4_general_ci NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `bank_name` VARCHAR(160) NOT NULL,
  `account_ref` VARCHAR(160) NOT NULL DEFAULT '',
  `allow_overdraft` BIGINT NOT NULL DEFAULT 0,
  `active` BIGINT NOT NULL DEFAULT 1,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_accounts_code` (`code`),
  CHECK (allow_overdraft IN(0,1)),
  CHECK (active IN(0,1))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `bank_transactions` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `bank_account_id` VARCHAR(64) NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `source_type` VARCHAR(64) NOT NULL,
  `source_id` VARCHAR(64) NOT NULL,
  `reference` VARCHAR(400) NOT NULL,
  `note` LONGTEXT NOT NULL DEFAULT '',
  `occurred_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NOT NULL,
  `matched_at` VARCHAR(32) NULL,
  `matched_by` VARCHAR(64) NULL,
  `statement_ref` VARCHAR(400) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_bank_date` (`bank_account_id`,`occurred_at`),
  UNIQUE KEY `uq_bank_transactions_bank_account_id_source_type_source_id` (`bank_account_id`,`source_type`,`source_id`),
  CHECK (amount_cents<>0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `product_purchases` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `supplier_id` VARCHAR(64) NOT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `invoice_no` VARCHAR(80) NOT NULL,
  `quantity_milli` BIGINT NOT NULL,
  `cost_cents` BIGINT NOT NULL,
  `total_cents` BIGINT NOT NULL,
  `paid_cents` BIGINT NOT NULL DEFAULT 0,
  `bank_account_id` VARCHAR(64) NULL,
  `notes` VARCHAR(4000) NOT NULL DEFAULT '',
  `received_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_purchases_supplier_id_invoice_no` (`supplier_id`,`invoice_no`),
  CHECK (quantity_milli>0),
  CHECK (cost_cents>=0),
  CHECK (total_cents>0),
  CHECK (paid_cents>=0 AND paid_cents<=total_cents)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `purchase_invoices` (
  `supplier_id` VARCHAR(64) NOT NULL,
  `invoice_no` VARCHAR(80) NOT NULL,
  `purchase_id` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `con_bill_no` VARCHAR(80) NULL,
  `purchase_date` CHAR(10) NULL,
  PRIMARY KEY (`supplier_id`,`invoice_no`),
  UNIQUE KEY `uq_purchase_invoices_purchase_id` (`purchase_id`),
  CHECK (kind IN('fuel','product')),
  UNIQUE KEY `uq_purchase_con_bill` (`con_bill_no`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `pump_receipts` (
  `_seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  `id` VARCHAR(64) NOT NULL,
  `settlement_id` VARCHAR(64) NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  `method` VARCHAR(24) NOT NULL,
  `shift_id` VARCHAR(64) NULL,
  `bank_account_id` VARCHAR(64) NULL,
  `reference` VARCHAR(400) NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  `created_by` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pump_receipts_settlement` (`settlement_id`),
  CHECK (amount_cents>0),
  CHECK (method IN('cash','bank'))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `workspace_requests` (
  `request_key` VARCHAR(100) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `request_hash` VARCHAR(64) NOT NULL,
  `result_json` LONGTEXT NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`request_key`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `efs_mutex` (`id` BIGINT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT IGNORE INTO `efs_mutex` (`id`) VALUES(1);


-- Foreign keys: run only once against an empty database.
ALTER TABLE `products` ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);
ALTER TABLE `fuel_prices` ADD CONSTRAINT `fk_fuel_prices_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `fuel_prices` ADD CONSTRAINT `fk_fuel_prices_1` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`);
ALTER TABLE `tanks` ADD CONSTRAINT `fk_tanks_0` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`);
ALTER TABLE `nozzles` ADD CONSTRAINT `fk_nozzles_0` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);
ALTER TABLE `nozzles` ADD CONSTRAINT `fk_nozzles_1` FOREIGN KEY (`pump_id`) REFERENCES `pumps` (`id`);
ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_1` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);
ALTER TABLE `calibration_charts` ADD CONSTRAINT `fk_calibration_charts_0` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);
ALTER TABLE `calibration_points` ADD CONSTRAINT `fk_calibration_points_0` FOREIGN KEY (`chart_id`) REFERENCES `calibration_charts` (`id`);
ALTER TABLE `dip_records` ADD CONSTRAINT `fk_dip_records_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `dip_records` ADD CONSTRAINT `fk_dip_records_1` FOREIGN KEY (`chart_id`) REFERENCES `calibration_charts` (`id`);
ALTER TABLE `dip_records` ADD CONSTRAINT `fk_dip_records_2` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);
ALTER TABLE `vehicles` ADD CONSTRAINT `fk_vehicles_0` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`);
ALTER TABLE `vehicles` ADD CONSTRAINT `fk_vehicles_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);
ALTER TABLE `shifts` ADD CONSTRAINT `fk_shifts_0` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`);
ALTER TABLE `shifts` ADD CONSTRAINT `fk_shifts_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
ALTER TABLE `shift_nozzles` ADD CONSTRAINT `fk_shift_nozzles_0` FOREIGN KEY (`nozzle_id`) REFERENCES `nozzles` (`id`);
ALTER TABLE `shift_nozzles` ADD CONSTRAINT `fk_shift_nozzles_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`);
ALTER TABLE `meter_periods` ADD CONSTRAINT `fk_meter_periods_0` FOREIGN KEY (`shift_nozzle_id`) REFERENCES `shift_nozzles` (`id`);
ALTER TABLE `fuel_tests` ADD CONSTRAINT `fk_fuel_tests_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `fuel_tests` ADD CONSTRAINT `fk_fuel_tests_1` FOREIGN KEY (`meter_period_id`) REFERENCES `meter_periods` (`id`);
ALTER TABLE `sales` ADD CONSTRAINT `fk_sales_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `sales` ADD CONSTRAINT `fk_sales_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`);
ALTER TABLE `sales` ADD CONSTRAINT `fk_sales_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);
ALTER TABLE `sales` ADD CONSTRAINT `fk_sales_3` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`);
ALTER TABLE `sale_requests` ADD CONSTRAINT `fk_sale_requests_0` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`);
ALTER TABLE `sale_items` ADD CONSTRAINT `fk_sale_items_0` FOREIGN KEY (`meter_period_id`) REFERENCES `meter_periods` (`id`);
ALTER TABLE `sale_items` ADD CONSTRAINT `fk_sale_items_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);
ALTER TABLE `sale_items` ADD CONSTRAINT `fk_sale_items_2` FOREIGN KEY (`nozzle_id`) REFERENCES `nozzles` (`id`);
ALTER TABLE `sale_items` ADD CONSTRAINT `fk_sale_items_3` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`);
ALTER TABLE `sale_payments` ADD CONSTRAINT `fk_sale_payments_0` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`);
ALTER TABLE `customer_ledger` ADD CONSTRAINT `fk_customer_ledger_0` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`);
ALTER TABLE `customer_ledger` ADD CONSTRAINT `fk_customer_ledger_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `customer_ledger` ADD CONSTRAINT `fk_customer_ledger_2` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`);
ALTER TABLE `customer_ledger` ADD CONSTRAINT `fk_customer_ledger_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);
ALTER TABLE `customer_allocations` ADD CONSTRAINT `fk_customer_allocations_0` FOREIGN KEY (`invoice_entry_id`) REFERENCES `customer_ledger` (`id`);
ALTER TABLE `customer_allocations` ADD CONSTRAINT `fk_customer_allocations_1` FOREIGN KEY (`receipt_entry_id`) REFERENCES `customer_ledger` (`id`);
ALTER TABLE `cash_movements` ADD CONSTRAINT `fk_cash_movements_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `cash_movements` ADD CONSTRAINT `fk_cash_movements_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`);
ALTER TABLE `expenses` ADD CONSTRAINT `fk_expenses_0` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`);
ALTER TABLE `expenses` ADD CONSTRAINT `fk_expenses_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `expenses` ADD CONSTRAINT `fk_expenses_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`);
ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_0` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`);
ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);
ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_3` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);
ALTER TABLE `supplier_payments` ADD CONSTRAINT `fk_supplier_payments_0` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`);
ALTER TABLE `supplier_payments` ADD CONSTRAINT `fk_supplier_payments_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `supplier_payments` ADD CONSTRAINT `fk_supplier_payments_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);
ALTER TABLE `settlements` ADD CONSTRAINT `fk_settlements_0` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);
ALTER TABLE `settlements` ADD CONSTRAINT `fk_settlements_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`);
ALTER TABLE `staff_variances` ADD CONSTRAINT `fk_staff_variances_0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
ALTER TABLE `staff_variances` ADD CONSTRAINT `fk_staff_variances_1` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`);
ALTER TABLE `audit_logs` ADD CONSTRAINT `fk_audit_logs_0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
ALTER TABLE `backup_history` ADD CONSTRAINT `fk_backup_history_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `employees` ADD CONSTRAINT `fk_employees_0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
ALTER TABLE `bank_transactions` ADD CONSTRAINT `fk_bank_transactions_0` FOREIGN KEY (`matched_by`) REFERENCES `users` (`id`);
ALTER TABLE `bank_transactions` ADD CONSTRAINT `fk_bank_transactions_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `bank_transactions` ADD CONSTRAINT `fk_bank_transactions_2` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`);
ALTER TABLE `product_purchases` ADD CONSTRAINT `fk_product_purchases_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `product_purchases` ADD CONSTRAINT `fk_product_purchases_1` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`);
ALTER TABLE `product_purchases` ADD CONSTRAINT `fk_product_purchases_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);
ALTER TABLE `product_purchases` ADD CONSTRAINT `fk_product_purchases_3` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);
ALTER TABLE `purchase_invoices` ADD CONSTRAINT `fk_purchase_invoices_0` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);
ALTER TABLE `pump_receipts` ADD CONSTRAINT `fk_pump_receipts_0` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
ALTER TABLE `pump_receipts` ADD CONSTRAINT `fk_pump_receipts_1` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`);
ALTER TABLE `pump_receipts` ADD CONSTRAINT `fk_pump_receipts_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`);
ALTER TABLE `pump_receipts` ADD CONSTRAINT `fk_pump_receipts_3` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`);
ALTER TABLE `workspace_requests` ADD CONSTRAINT `fk_workspace_requests_0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW tank_balances AS SELECT t.*,COALESCE((SELECT SUM(s.quantity_ml) FROM stock_movements s WHERE s.tank_id=t.id),0) AS balance_ml FROM tanks t;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW customer_balances AS SELECT c.*,COALESCE((SELECT SUM(l.debit_cents-l.credit_cents) FROM customer_ledger l WHERE l.customer_id=c.id),0) AS balance_cents FROM customers c;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW bank_balances AS SELECT b.*,COALESCE((SELECT SUM(t.amount_cents) FROM bank_transactions t WHERE t.bank_account_id=b.id),0) balance_cents FROM bank_accounts b;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW supplier_balances AS SELECT s.*,
 s.opening_balance_cents+
 COALESCE((SELECT SUM(d.total_cents-d.paid_cents) FROM deliveries d WHERE d.supplier_id=s.id),0)
 +COALESCE((SELECT SUM(p.total_cents-p.paid_cents) FROM product_purchases p WHERE p.supplier_id=s.id),0)
 -COALESCE((SELECT SUM(p.amount_cents) FROM supplier_payments p WHERE p.supplier_id=s.id),0) balance_cents FROM suppliers s;

INSERT IGNORE INTO `schema_migrations` (`version`,`applied_at`) VALUES (4,DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'));
