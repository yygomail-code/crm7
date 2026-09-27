CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_templates` (
  `code` varchar(64) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `email_templates` (`code`,`subject`,`body`) VALUES
  ('request_new','Новая заявка {request_number}','Здравствуйте!\n\nВам назначена новая заявка {request_number}.\n{title}\n\n{body}\n\nОткрыть заявку: {link}'),
  ('request_claim','Заявка {request_number} взята в работу','Здравствуйте!\n\nЗаявка {request_number} взята в работу.\n{body}\n\nОткрыть заявку: {link}'),
  ('request_status','Статус заявки {request_number} изменён','Здравствуйте!\n\n{title}\n{body}\n\nОткрыть заявку: {link}'),
  ('request_comment','Новый комментарий по заявке {request_number}','Здравствуйте!\n\nПо заявке {request_number} добавлен комментарий:\n\n{body}\n\nОткрыть заявку: {link}'),
  ('request_assigned','Вам передана заявка {request_number}','Здравствуйте!\n\nВам передана заявка {request_number}.\nПричина: {body}\n\nОткрыть заявку: {link}'),
  ('request_transferred','Заявка {request_number} передана другому менеджеру','Здравствуйте!\n\nЗаявка {request_number} передана другому менеджеру.\nПричина: {body}\n\nОткрыть заявку: {link}'),
  ('request_assign','Заявка {request_number}: смена менеджера','Здравствуйте!\n\n{title}\n\nОткрыть заявку: {link}')
ON DUPLICATE KEY UPDATE `subject` = `subject`;
