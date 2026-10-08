-- 045: модуль «Отчёты» — флаг module.reports (вкл/выкл).
-- При выключении страница/API отчётов недоступны, пункт меню скрыт.
INSERT INTO `settings` (`key`, `value`) VALUES ('module.reports', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;
