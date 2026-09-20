CREATE TABLE IF NOT EXISTS `request_assignments` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned NOT NULL,
  `from_manager_id` int DEFAULT NULL,
  `to_manager_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `comment` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_request` (`request_id`),
  KEY `idx_to_manager` (`to_manager_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
