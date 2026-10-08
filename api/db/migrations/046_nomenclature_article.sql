-- 046: артикул позиции (общий для всех складов, хранится в nomenclature).
ALTER TABLE `nomenclature`
  ADD COLUMN IF NOT EXISTS `ARTICLE` varchar(128) DEFAULT NULL AFTER `NAME_1C`;
