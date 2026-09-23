-- Маршрут без подписи: NULL в signature_id означает «подпись по умолчанию»,
-- поэтому «совсем без подписи» — отдельный флаг.

ALTER TABLE `routes` ADD COLUMN `no_signature` TINYINT(1) NOT NULL DEFAULT 0 AFTER `signature_id`;
