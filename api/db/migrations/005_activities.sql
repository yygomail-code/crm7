CREATE TABLE IF NOT EXISTS `activity_types` (
  `code` varchar(32) NOT NULL,
  `title` varchar(255) NOT NULL,
  `audience` enum('manager','client','all') NOT NULL DEFAULT 'all',
  `sort` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `activity_types` (`code`,`title`,`audience`,`sort`) VALUES
  ('call','Звонок клиенту','manager',10),
  ('meeting','Встреча','manager',20),
  ('email_sent','Отправил письмо','manager',30),
  ('invoice_sent','Отправил счёт','manager',40),
  ('agreed','Договорились','manager',50),
  ('no_answer','Не дозвонились','manager',60),
  ('visit','Выезд на объект','manager',70),
  ('supply_started','Передали в поставку','manager',80),
  ('other','Другое (описать)','manager',999),
  ('invoice_received','Получил счёт','client',10),
  ('invoice_paid','Оплатил счёт','client',20),
  ('on_the_way','Выехал за товаром','client',30),
  ('goods_received','Получил товар','client',40),
  ('question','Вопрос по заявке','client',50),
  ('other_client','Другое (описать)','client',999)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `audience` = VALUES(`audience`), `sort` = VALUES(`sort`);

CREATE TABLE IF NOT EXISTS `request_activities` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL,
  `type_code` varchar(32) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` varchar(2000) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_request` (`request_id`),
  KEY `idx_type` (`type_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
