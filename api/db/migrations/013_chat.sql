CREATE TABLE IF NOT EXISTS `chat_threads` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `manager_id` int DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uniq_client` (`client_id`),
  KEY `idx_manager` (`manager_id`),
  KEY `idx_last_message` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` int NOT NULL,
  `user_id` int NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `idx_thread` (`thread_id`,`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_reads` (
  `thread_id` int NOT NULL,
  `user_id` int NOT NULL,
  `last_read_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`thread_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_quick_replies` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `body` varchar(500) NOT NULL,
  `sort` int NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `chat_quick_replies` (`body`, `sort`) VALUES
  ('Здравствуйте! Заявка принята в работу.', 10),
  ('Уточняю информацию, вернусь с ответом.', 20),
  ('Уточните, пожалуйста, детали: что именно требуется?', 30);
