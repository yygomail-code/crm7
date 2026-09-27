INSERT IGNORE INTO `level_capabilities` (`level`,`capability_code`) VALUES (50,'settings.manage');

DELETE FROM `level_capabilities`
WHERE `capability_code` = 'settings.manage' AND `level` IN (1, 5, 10);
