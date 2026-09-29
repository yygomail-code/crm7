ALTER TABLE `nomenclature_prices` ADD COLUMN `stock_sid` char(50) NOT NULL DEFAULT '' AFTER `name_sid`;
ALTER TABLE `nomenclature_prices` DROP INDEX `uq_name_type`;
ALTER TABLE `nomenclature_prices` ADD UNIQUE KEY `uq_stock_name_type` (`stock_sid`,`name_sid`,`price_type_id`);
