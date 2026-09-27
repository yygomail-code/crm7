-- 026: чат между сотрудниками и выбор собеседника
-- Тред клиента остаётся один (uniq_client), но client_id может быть NULL —
-- это треды «сотрудник ↔ сотрудник»: manager_id — инициатор/первый участник, peer_id — второй.

ALTER TABLE `chat_threads` MODIFY `client_id` INT(11) NULL;

ALTER TABLE `chat_threads`
  ADD COLUMN `peer_id` INT(11) NULL AFTER `manager_id`,
  ADD INDEX `idx_peer` (`peer_id`);
