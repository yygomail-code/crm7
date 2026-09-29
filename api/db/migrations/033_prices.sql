CREATE TABLE IF NOT EXISTS `price_types` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `CODE` varchar(50) NOT NULL,
  `TITLE` varchar(255) NOT NULL,
  `SORT` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_code` (`CODE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `price_types` (`CODE`,`TITLE`,`SORT`) VALUES
  ('retail', 'Розничная', 10),
  ('wholesale', 'Оптовая', 20),
  ('special', 'Специальная', 30)
ON DUPLICATE KEY UPDATE `TITLE` = `TITLE`;

ALTER TABLE `users` ADD COLUMN `price_type_id` int NULL DEFAULT NULL AFTER `LEVEL`;
ALTER TABLE `users` ADD KEY `idx_price_type` (`price_type_id`);

CREATE TABLE IF NOT EXISTS `nomenclature_prices` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `name_sid` char(50) NOT NULL,
  `price_type_id` int NOT NULL,
  `price` decimal(20,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_name_type` (`name_sid`, `price_type_id`),
  KEY `idx_type` (`price_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `request_items`
  ADD COLUMN `price` decimal(20,2) NULL DEFAULT NULL AFTER `quantity`,
  ADD COLUMN `price_type_id` int NULL DEFAULT NULL AFTER `price`;

INSERT INTO `settings` (`key`,`value`) VALUES ('prices.enabled', '0')
ON DUPLICATE KEY UPDATE `value` = `value`;
