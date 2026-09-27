CREATE TABLE IF NOT EXISTS `request_drafts` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `client_id` int DEFAULT NULL,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `body` text DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT 2,
  `items` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
