-- 027: привязка клиент ↔ менеджер (возврат) + передачи клиентов
-- Возвращаем закрепление клиента за менеджером: таблица привязки, таблица
-- передач (на период/навсегда, с подтверждением получателем) и право
-- clients.assign для РОП/админа. Существующих клиентов привязываем к
-- менеджеру их самой свежей открытой заявки, чаты синхронизируем.

CREATE TABLE IF NOT EXISTS `client_managers` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `manager_id` int NOT NULL,
  `assigned_by` int DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uniq_client` (`client_id`),
  KEY `idx_manager` (`manager_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `client_transfers` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `from_manager_id` int DEFAULT NULL,
  `to_manager_id` int NOT NULL,
  `period_until` date DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `comment` varchar(500) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_by` int DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_client_status` (`client_id`,`status`),
  KEY `idx_to_status` (`to_manager_id`,`status`),
  KEY `idx_status_period` (`status`,`period_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `capabilities` (`code`,`title`) VALUES ('clients.assign','Клиенты: назначение менеджера')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT IGNORE INTO `level_capabilities` (`level`,`capability_code`) VALUES (50,'clients.assign'), (90,'clients.assign');

INSERT IGNORE INTO `client_managers` (`client_id`,`manager_id`,`assigned_by`)
SELECT r.client_id, r.manager_id, NULL
FROM `requests` r
INNER JOIN `request_statuses` s ON s.code = r.status_id
WHERE s.is_final = 0 AND r.manager_id IS NOT NULL
ORDER BY r.created_at DESC;

UPDATE `chat_threads` t
INNER JOIN `client_managers` cm ON cm.client_id = t.client_id
SET t.manager_id = cm.manager_id
WHERE t.client_id IS NOT NULL;
