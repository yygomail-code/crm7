-- 048: активность позиции номенклатуры и право на деактивацию.
--   nomenclature.ACTIVE — enum('Y','N'), уже есть в схеме: 'Y' — активна, 'N' — деактивирована.
--   Ниже значение нормализуется на случай иной схемы.
--   stocks.deactivate — активация/деактивация, просмотр неактивных и фильтр «Активные/Неактивные».
--   Удаление позиции — по праву stocks.manage.
ALTER TABLE `nomenclature`
  MODIFY COLUMN `ACTIVE` enum('Y','N') NOT NULL DEFAULT 'Y';

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`) VALUES
  (90, 'stocks.deactivate'),
  (50, 'stocks.deactivate'),
  (10, 'stocks.deactivate');
