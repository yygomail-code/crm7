-- 043: модуль «Склады» — адрес склада и настройки модуля
--   module.warehouses — включение/отключение модуля складов
--   stocks.single_name — имя виртуального склада в режиме без складов
ALTER TABLE `stocks`
  ADD COLUMN IF NOT EXISTS `ADDRESS` varchar(255) DEFAULT NULL AFTER `NAME_1C`;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('module.warehouses', '1'),
  ('stocks.single_name', 'Основной склад')
ON DUPLICATE KEY UPDATE `value` = `value`;
