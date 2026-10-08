-- 042: варианты размеров фото номенклатуры (превью / карточка / максимум)
--   storage_path — максимальный размер; card_path/preview_path — производные
--   width/height — размеры максимума; пустые card_path/preview_path — для пересчёта скриптом
ALTER TABLE `nomenclature_photos`
  ADD COLUMN IF NOT EXISTS `width` int NOT NULL DEFAULT 0 AFTER `size`,
  ADD COLUMN IF NOT EXISTS `height` int NOT NULL DEFAULT 0 AFTER `width`,
  ADD COLUMN IF NOT EXISTS `card_path` varchar(255) NOT NULL DEFAULT '' AFTER `storage_path`,
  ADD COLUMN IF NOT EXISTS `preview_path` varchar(255) NOT NULL DEFAULT '' AFTER `card_path`;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('stocks.photo_size_preview', '160'),
  ('stocks.photo_size_card', '600'),
  ('stocks.photo_size_max', '1600')
ON DUPLICATE KEY UPDATE `value` = `value`;
