CREATE TABLE IF NOT EXISTS `request_statuses` (
  `code` varchar(32) NOT NULL,
  `title` varchar(255) NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  `color` varchar(16) DEFAULT NULL,
  `is_final` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `request_statuses` (`code`,`title`,`sort`,`color`,`is_final`) VALUES
  ('new','Новая',10,'#2563eb',0),
  ('accepted','Принята',20,'#7c3aed',0),
  ('in_progress','В работе',30,'#d97706',0),
  ('waiting','Ожидание',40,'#0891b2',0),
  ('resolved','Решена',50,'#059669',0),
  ('closed','Закрыта',60,'#6b7280',1),
  ('canceled','Отменена',70,'#dc2626',1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `sort` = VALUES(`sort`), `color` = VALUES(`color`), `is_final` = VALUES(`is_final`);

CREATE TABLE IF NOT EXISTS `requests` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(32) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text,
  `client_id` int NOT NULL,
  `manager_id` int DEFAULT NULL,
  `status_id` varchar(32) NOT NULL DEFAULT 'new',
  `priority` tinyint NOT NULL DEFAULT 2,
  `source` varchar(32) NOT NULL DEFAULT 'cabinet',
  `due_at` datetime DEFAULT NULL,
  `first_response_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `version` int NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_number` (`number`),
  KEY `idx_client` (`client_id`),
  KEY `idx_manager` (`manager_id`),
  KEY `idx_status` (`status_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `request_history` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL,
  `from_status_id` varchar(32) DEFAULT NULL,
  `to_status_id` varchar(32) DEFAULT NULL,
  `comment` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `request_comments` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL,
  `body` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_clients` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `manager_id` int NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 1,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` int DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_pair` (`client_id`,`manager_id`),
  KEY `idx_manager` (`manager_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
