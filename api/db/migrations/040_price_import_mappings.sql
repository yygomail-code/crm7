CREATE TABLE IF NOT EXISTS `price_import_mappings` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `source_name` varchar(191) NOT NULL,
  `price_type_id` int NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_source_name` (`source_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
