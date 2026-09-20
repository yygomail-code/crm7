CREATE TABLE IF NOT EXISTS `capabilities` (
  `code` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `level_capabilities` (
  `level` int NOT NULL,
  `capability_code` varchar(64) NOT NULL,
  PRIMARY KEY (`level`,`capability_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `capabilities` (`code`,`title`) VALUES
  ('requests.view.all','Заявки: просмотр всех'),
  ('requests.view.own','Заявки: просмотр своих'),
  ('requests.create','Заявки: создание'),
  ('requests.assign','Заявки: назначение'),
  ('requests.transition','Заявки: смена статуса'),
  ('requests.comment','Заявки: комментарии'),
  ('clients.view.all','Клиенты: просмотр всех'),
  ('clients.view.own','Клиенты: просмотр своих'),
  ('clients.assign','Клиенты: назначение менеджера'),
  ('clients.edit','Клиенты: редактирование'),
  ('chat.use','Чат: использование'),
  ('chat.moderate','Чат: модерация'),
  ('reports.view.all','Отчёты: все'),
  ('reports.view.own','Отчёты: по себе'),
  ('reports.export','Отчёты: экспорт'),
  ('users.manage','Пользователи: управление'),
  ('roles.manage','Роли: управление'),
  ('audit.view','Аудит: просмотр'),
  ('settings.manage','Настройки: управление'),
  ('stocks.view','Склады: просмотр'),
  ('stocks.import','Склады: импорт'),
  ('stocks.manage','Склады: управление')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT IGNORE INTO `level_capabilities` (`level`,`capability_code`) VALUES
  (90,'requests.view.all'),(90,'requests.view.own'),(90,'requests.create'),(90,'requests.assign'),(90,'requests.transition'),(90,'requests.comment'),
  (90,'clients.view.all'),(90,'clients.view.own'),(90,'clients.assign'),(90,'clients.edit'),
  (90,'chat.use'),(90,'chat.moderate'),
  (90,'reports.view.all'),(90,'reports.view.own'),(90,'reports.export'),
  (90,'users.manage'),(90,'roles.manage'),(90,'audit.view'),(90,'settings.manage'),
  (90,'stocks.view'),(90,'stocks.import'),(90,'stocks.manage'),
  (50,'requests.view.all'),(50,'requests.view.own'),(50,'requests.create'),(50,'requests.assign'),(50,'requests.transition'),(50,'requests.comment'),
  (50,'clients.view.all'),(50,'clients.view.own'),(50,'clients.assign'),(50,'clients.edit'),
  (50,'chat.use'),(50,'chat.moderate'),
  (50,'reports.view.all'),(50,'reports.export'),
  (50,'users.manage'),(50,'audit.view'),
  (50,'stocks.view'),(50,'stocks.import'),(50,'stocks.manage'),
  (10,'requests.view.own'),(10,'requests.create'),(10,'requests.transition'),(10,'requests.comment'),
  (10,'clients.view.own'),
  (10,'chat.use'),
  (10,'reports.view.own'),
  (10,'stocks.view'),
  (5,'requests.view.own'),(5,'requests.create'),(5,'requests.comment'),
  (5,'chat.use');
