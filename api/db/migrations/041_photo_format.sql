-- 041: формат отображения фото номенклатуры (системная настройка)
--   stocks.photo_ratio: dynamic | square | landscape | portrait
--   stocks.photo_fit:   contain | cover
INSERT INTO `settings` (`key`, `value`) VALUES
  ('stocks.photo_ratio', 'square'),
  ('stocks.photo_fit', 'contain')
ON DUPLICATE KEY UPDATE `value` = `value`;
