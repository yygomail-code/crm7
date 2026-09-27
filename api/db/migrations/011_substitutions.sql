CREATE TABLE IF NOT EXISTS `substitutions` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `manager_id` int NOT NULL,
  `substitute_id` int NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_manager` (`manager_id`),
  KEY `idx_substitute` (`substitute_id`),
  KEY `idx_dates` (`date_from`,`date_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `requests`
  ADD COLUMN IF NOT EXISTS `substitution_id` int DEFAULT NULL,
  ADD KEY `idx_substitution` (`substitution_id`);

INSERT INTO `email_templates` (`code`,`subject`,`body`) VALUES
  ('substitution_created','Замещение: {title}','Здравствуйте!\n\n{title}\n{body}\n\n— CRM'),
  ('substitution_ended','Замещение завершено','Здравствуйте!\n\n{title}\n{body}\n\n— CRM')
ON DUPLICATE KEY UPDATE `subject` = `subject`;
