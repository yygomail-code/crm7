INSERT INTO capabilities (code, title)
VALUES ('stocks.edit', 'Склады: позиции (добавление и правка)')
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT IGNORE INTO level_capabilities (level, capability_code) VALUES (90, 'stocks.edit'), (50, 'stocks.edit');
