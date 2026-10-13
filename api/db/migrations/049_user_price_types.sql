-- 049: несколько закреплённых типов цен за пользователем + тип по умолчанию.
--   user_price_types — закреплённый набор типов цен пользователя; is_default — тип,
--   по которому считаются корзина, заявки и заказы. Дублируется в users.price_type_id
--   для обратной совместимости и текущих расчётов (effectiveTypeIdForUser).
--   prices.assign — право закреплять типы цен за любым пользователем (менеджер, админ).
CREATE TABLE IF NOT EXISTS `user_price_types` (
  `user_id` int NOT NULL,
  `price_type_id` int NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`price_type_id`),
  KEY `idx_price_type` (`price_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `user_price_types` (`user_id`,`price_type_id`,`sort`,`is_default`)
SELECT `ID`, `price_type_id`, 0, 1 FROM `users` WHERE `price_type_id` IS NOT NULL;

INSERT INTO `capabilities` (`code`,`title`)
VALUES ('prices.assign','Цены: закрепление типов цен за пользователями')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT IGNORE INTO `level_capabilities` (`level`,`capability_code`) VALUES
  (90,'prices.assign'),
  (50,'prices.assign'),
  (10,'prices.assign');
