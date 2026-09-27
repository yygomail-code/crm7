CREATE TABLE IF NOT EXISTS `request_attachments` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `size` bigint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
