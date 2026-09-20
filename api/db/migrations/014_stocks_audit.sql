CREATE TABLE IF NOT EXISTS `stocks` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `ACTIVE` enum('Y','N') NOT NULL DEFAULT 'Y',
  `TIME_ADD` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `SID` char(50) DEFAULT (uuid()),
  `NAME` char(255) DEFAULT NULL,
  `NAME_1C` char(255) DEFAULT NULL,
  `STOCK_NUM` decimal(2,0) DEFAULT 0,
  `SORT` decimal(3,0) DEFAULT NULL,
  `LAST_ACTIVITY_DATE` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `LAST_STOCK_UPDATE_SID` char(50) DEFAULT NULL,
  `LEVEL` char(255) DEFAULT '{50},{10},{5},',
  `STATUS` enum('Y','N') DEFAULT 'Y',
  PRIMARY KEY (`ID`),
  UNIQUE KEY `NAME_1C` (`NAME_1C`),
  UNIQUE KEY `NAME` (`NAME`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nomenclature` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `ACTIVE` enum('Y','N') NOT NULL DEFAULT 'Y',
  `TIME_ADD` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `SID` char(50) DEFAULT (uuid()),
  `NAME` char(255) DEFAULT NULL,
  `NAME_1C` char(255) DEFAULT NULL,
  `UNIT` char(255) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `NAME_1C` (`NAME_1C`),
  UNIQUE KEY `NAME` (`NAME`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_levels` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `ACTIVE` enum('Y','N') NOT NULL DEFAULT 'Y',
  `TIME_ADD` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `SID` char(50) DEFAULT (uuid()),
  `LAST_STOCK_UPDATE_SID` char(50) DEFAULT NULL,
  `ACTUAL_DATE` date DEFAULT NULL,
  `STOCK` char(255) DEFAULT NULL,
  `STOCK_SID` char(50) DEFAULT NULL,
  `NAME` char(255) DEFAULT NULL,
  `NAME_SID` char(50) DEFAULT NULL,
  `UNIT` char(255) DEFAULT NULL,
  `QUANTITY` decimal(20,5) DEFAULT 0.00000,
  PRIMARY KEY (`ID`),
  KEY `idx_stock` (`STOCK_SID`),
  KEY `idx_name` (`NAME_SID`),
  KEY `idx_update` (`LAST_STOCK_UPDATE_SID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_level_stock` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `ACTIVE` enum('Y','N') DEFAULT 'Y',
  `TIME_ADD` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `SID` char(50) NOT NULL DEFAULT (uuid()),
  `FULL_NAME` char(255) DEFAULT NULL,
  `USER_SID` char(50) DEFAULT NULL,
  `STOCK_SID` char(50) DEFAULT NULL,
  `STATUS` enum('Y','N') DEFAULT 'Y',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `last_stock_update` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `ACTIVE` enum('Y','N') NOT NULL DEFAULT 'Y',
  `TIME_ADD` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `SID` char(50) DEFAULT (uuid()),
  `ACTUAL_DATE` date DEFAULT NULL,
  `FILE` char(255) DEFAULT NULL,
  `STATUS_UPLOAD` char(50) DEFAULT 'New',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_import_jobs` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `FILE_NAME` varchar(255) NOT NULL,
  `ACTUAL_DATE` date NOT NULL,
  `STATUS` enum('processing','done','failed') NOT NULL DEFAULT 'processing',
  `ROWS_TOTAL` int NOT NULL DEFAULT 0,
  `ROWS_IMPORTED` int NOT NULL DEFAULT 0,
  `ROWS_SKIPPED` int NOT NULL DEFAULT 0,
  `ERRORS` text DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_status` (`STATUS`,`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `entity` varchar(64) DEFAULT NULL,
  `entity_id` varchar(64) DEFAULT NULL,
  `data_json` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_user` (`user_id`,`ID`),
  KEY `idx_action` (`action`,`ID`),
  KEY `idx_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`,`value`) VALUES
  ('sla.reaction_hours', '2'),
  ('sla.resolution_hours', '24'),
  ('mail.spf_checklist', '0')
ON DUPLICATE KEY UPDATE `value` = `value`;
