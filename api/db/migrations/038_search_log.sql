-- 038: журнал поиска по позициям (аналитика: что искали пользователи)
CREATE TABLE IF NOT EXISTS `search_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `query` VARCHAR(255) NOT NULL,
  `results` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_search_log_created` (`created_at`),
  KEY `idx_search_log_query` (`query`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
