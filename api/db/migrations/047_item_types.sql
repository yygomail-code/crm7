-- 047: типы номенклатуры (товар/услуга/набор) и состав набора.
--   nomenclature.TYPE — product|service|set (по умолчанию product).
--   nomenclature_composition — состав набора: SET_SID (набор) → ITEM_SID (компонент) + QUANTITY.
--   Разрешённые типы хранятся в settings (stocks.item_types); отсутствие ключа = все типы.
ALTER TABLE `nomenclature`
  ADD COLUMN IF NOT EXISTS `TYPE` varchar(20) NOT NULL DEFAULT 'product' AFTER `ARTICLE`,
  ADD KEY IF NOT EXISTS `idx_nomenclature_type` (`TYPE`);

CREATE TABLE IF NOT EXISTS `nomenclature_composition` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `SET_SID` char(50) NOT NULL,
  `ITEM_SID` char(50) NOT NULL,
  `QUANTITY` decimal(12,3) NOT NULL DEFAULT 1.000,
  `SORT` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_nomenclature_composition` (`SET_SID`, `ITEM_SID`),
  KEY `idx_nomenclature_composition_item` (`ITEM_SID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
