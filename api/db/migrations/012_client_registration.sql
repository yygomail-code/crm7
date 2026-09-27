CREATE TABLE IF NOT EXISTS `user_history` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `event` varchar(50) NOT NULL,
  `actor_id` int DEFAULT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_user` (`user_id`,`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `reg_state` enum('active','pending','rejected') NOT NULL DEFAULT 'active';

ALTER TABLE `login_attempts`
  MODIFY COLUMN `type` enum('login','reset','register') NOT NULL DEFAULT 'login';

INSERT INTO `capabilities` (`code`,`title`) VALUES
  ('clients.confirm','Регистрация клиентов: подтверждение')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT IGNORE INTO `level_capabilities` (`level`,`capability_code`) VALUES
  (10,'clients.confirm'),
  (50,'clients.confirm'),
  (90,'clients.confirm');

INSERT INTO `email_templates` (`code`,`subject`,`body`) VALUES
  ('user_approved','Регистрация подтверждена','Здравствуйте!\n\n{title}\n{body}\n\n— CRM'),
  ('user_rejected','Регистрация отклонена','Здравствуйте!\n\n{title}\n{body}\n\n— CRM')
ON DUPLICATE KEY UPDATE `subject` = `subject`;
