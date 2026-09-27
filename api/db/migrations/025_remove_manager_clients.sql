-- 025: переход на модель «менеджер назначается на заявку»
-- Убираем привязку клиентов к менеджерам: право clients.assign и таблицу manager_clients.

DELETE FROM `level_capabilities` WHERE `capability_code` = 'clients.assign';
DELETE FROM `capabilities` WHERE `code` = 'clients.assign';
DROP TABLE IF EXISTS `manager_clients`;
