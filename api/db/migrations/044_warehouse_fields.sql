-- 044: расширенные поля склада — реквизиты, контакты, тип, флаги, ответственный.
--   CONTACT_NAME / PHONE / EMAIL / NOTE — контакты и заметки.
--   TYPE — тип склада: main|transit|returns|reserve|defect.
--   IS_DEFAULT — «основной» склад (единственный, по умолчанию в выборе).
--   ALLOW_ORDERS — доступен для заявок/самовывоза.
--   IN_REPORTS — учитывать в отчётах.
--   RESPONSIBLE_SID — ответственный (users.SID).
--   STOCK_NUM (номер) и SORT (порядок) уже есть в stocks.
ALTER TABLE `stocks`
  ADD COLUMN IF NOT EXISTS `CONTACT_NAME` varchar(255) DEFAULT NULL AFTER `ADDRESS`,
  ADD COLUMN IF NOT EXISTS `PHONE` varchar(64) DEFAULT NULL AFTER `CONTACT_NAME`,
  ADD COLUMN IF NOT EXISTS `EMAIL` varchar(255) DEFAULT NULL AFTER `PHONE`,
  ADD COLUMN IF NOT EXISTS `NOTE` text DEFAULT NULL AFTER `EMAIL`,
  ADD COLUMN IF NOT EXISTS `TYPE` varchar(20) NOT NULL DEFAULT 'main' AFTER `NOTE`,
  ADD COLUMN IF NOT EXISTS `IS_DEFAULT` tinyint(1) NOT NULL DEFAULT 0 AFTER `TYPE`,
  ADD COLUMN IF NOT EXISTS `ALLOW_ORDERS` tinyint(1) NOT NULL DEFAULT 1 AFTER `IS_DEFAULT`,
  ADD COLUMN IF NOT EXISTS `IN_REPORTS` tinyint(1) NOT NULL DEFAULT 1 AFTER `ALLOW_ORDERS`,
  ADD COLUMN IF NOT EXISTS `RESPONSIBLE_SID` char(50) DEFAULT NULL AFTER `IN_REPORTS`;
