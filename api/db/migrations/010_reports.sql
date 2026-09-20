CREATE TABLE IF NOT EXISTS `report_schedules` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `report_type` enum('summary','managers') NOT NULL DEFAULT 'summary',
  `frequency` enum('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
  `time_of_day` char(5) NOT NULL DEFAULT '09:00',
  `day_of_week` tinyint DEFAULT NULL,
  `day_of_month` tinyint DEFAULT NULL,
  `recipients` text NOT NULL,
  `extra_emails` text,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_sent_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `email_templates` (`code`,`subject`,`body`) VALUES
  ('report_scheduled','Отчёт CRM: {title}','Здравствуйте!\n\nОтчёт «{title}» за период {period}.\n\n{report}\n\n— CRM')
ON DUPLICATE KEY UPDATE `subject` = `subject`;
