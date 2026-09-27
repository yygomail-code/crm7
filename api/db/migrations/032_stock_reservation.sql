ALTER TABLE request_items ADD COLUMN stock_level_id INT NULL DEFAULT NULL AFTER warehouse_name;
ALTER TABLE request_items ADD KEY idx_stock_level (stock_level_id);

CREATE TABLE IF NOT EXISTS `stock_reservations` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `stock_level_id` int NOT NULL,
  `quantity` decimal(20,5) NOT NULL DEFAULT 0.00000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_request_level` (`request_id`, `stock_level_id`),
  KEY `idx_level` (`stock_level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
