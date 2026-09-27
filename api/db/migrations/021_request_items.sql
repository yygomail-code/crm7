CREATE TABLE IF NOT EXISTS `request_items` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `warehouse_id` int DEFAULT NULL,
  `warehouse_name` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `quantity` decimal(20,5) NOT NULL DEFAULT 0.00000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `idx_request` (`request_id`),
  KEY `idx_warehouse` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
