-- 037: пользовательские настройки фильтров/сортировки (перенос между устройствами и сессиями)
CREATE TABLE IF NOT EXISTS `user_filters` (
  `user_id` INT NOT NULL,
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
