CREATE TABLE IF NOT EXISTS `chat_attachments` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` int NOT NULL,
  `message_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `idx_thread` (`thread_id`),
  KEY `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
