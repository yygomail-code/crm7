ALTER TABLE `email_queue`
  MODIFY COLUMN `status` enum('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `claimed_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `claim_token` varchar(32) DEFAULT NULL,
  ADD KEY `idx_status_claimed` (`status`, `claimed_at`);

CREATE TABLE IF NOT EXISTS `stock_levels_backup` (
  `ID` int NOT NULL,
  `ACTIVE` enum('Y','N') DEFAULT 'Y',
  `TIME_ADD` timestamp NULL DEFAULT NULL,
  `SID` char(50) DEFAULT NULL,
  `LAST_STOCK_UPDATE_SID` char(50) DEFAULT NULL,
  `ACTUAL_DATE` date DEFAULT NULL,
  `STOCK` char(255) DEFAULT NULL,
  `STOCK_SID` char(50) DEFAULT NULL,
  `NAME` char(255) DEFAULT NULL,
  `NAME_SID` char(50) DEFAULT NULL,
  `UNIT` char(255) DEFAULT NULL,
  `QUANTITY` decimal(20,5) DEFAULT 0.00000,
  `backup_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_stock` (`STOCK`),
  KEY `idx_name` (`NAME`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(64) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`bucket`, `key_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
