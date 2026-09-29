CREATE TABLE IF NOT EXISTS `item_groups` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `TITLE` varchar(255) NOT NULL,
  `SORT` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_title` (`TITLE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `nomenclature` ADD COLUMN `group_id` int NULL DEFAULT NULL AFTER `UNIT`;
ALTER TABLE `nomenclature` ADD KEY `idx_group` (`group_id`);

INSERT INTO `settings` (`key`,`value`) VALUES ('groups.enabled', '0')
ON DUPLICATE KEY UPDATE `value` = `value`;
