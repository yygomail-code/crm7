-- 036: индекс для лимита входа по IP (защита от перебора с одного устройства)
ALTER TABLE `login_attempts` ADD KEY `idx_type_ip_created` (`type`, `ip`, `created_at`);
