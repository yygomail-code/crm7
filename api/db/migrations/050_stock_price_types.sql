-- 050: используемые типы цен на складе.
--   stock_price_types — какие типы цен ведёт склад (из доступных).
--   PRICE_TYPES_SET = 0 (по умолчанию) — настройка не задана, склад использует все типы.
--   PRICE_TYPES_SET = 1 — используется явный набор из stock_price_types (может быть пустым).
ALTER TABLE `stocks`
  ADD COLUMN IF NOT EXISTS `PRICE_TYPES_SET` tinyint(1) NOT NULL DEFAULT 0 AFTER `IN_REPORTS`;

CREATE TABLE IF NOT EXISTS `stock_price_types` (
  `stock_sid` char(50) NOT NULL,
  `price_type_id` int NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`stock_sid`,`price_type_id`),
  KEY `idx_price_type` (`price_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
