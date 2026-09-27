-- 028: передача клиента v2 — даты «с/до», отложенное вступление в силу, передача в пул
-- Добавляем дату начала передачи (date_from) и момент фактического применения
-- (started_at), переименовываем period_until в date_to, разрешаем передачу без
-- получателя (в пул, to_manager_id = NULL). Статусы: pending (ждёт получателя),
-- scheduled (принята/создана, ждёт даты), active (действует), expired, declined,
-- cancelled. Старые accepted-записи становятся active.

ALTER TABLE `client_transfers` RENAME COLUMN `period_until` TO `date_to`;
ALTER TABLE `client_transfers` MODIFY COLUMN `to_manager_id` int NULL;
ALTER TABLE `client_transfers` ADD COLUMN `date_from` date NULL AFTER `to_manager_id`;
ALTER TABLE `client_transfers` ADD COLUMN `started_at` datetime NULL AFTER `status`;

UPDATE `client_transfers` SET `date_from` = DATE(`created_at`) WHERE `date_from` IS NULL;
UPDATE `client_transfers` SET `started_at` = `decided_at` WHERE `status` = 'accepted' AND `started_at` IS NULL;
UPDATE `client_transfers` SET `status` = 'active' WHERE `status` = 'accepted';
