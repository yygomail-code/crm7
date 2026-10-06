-- 039: фото товаров (номенклатуры), до 10 на товар
CREATE TABLE IF NOT EXISTS `nomenclature_photos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name_sid` CHAR(50) NOT NULL,
  `storage_path` VARCHAR(255) NOT NULL,
  `mime` VARCHAR(100) NOT NULL DEFAULT '',
  `size` INT NOT NULL DEFAULT 0,
  `sort` INT NOT NULL DEFAULT 0,
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nomenclature_photos_name` (`name_sid`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
