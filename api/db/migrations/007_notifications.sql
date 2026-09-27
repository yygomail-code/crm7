CREATE TABLE IF NOT EXISTS `notifications` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` varchar(1000) DEFAULT NULL,
  `request_id` bigint unsigned DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_user_read` (`user_id`,`read_at`),
  KEY `idx_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
