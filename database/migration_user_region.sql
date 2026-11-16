-- Mkoa na wilaya ya mtumiaji (hutumika kwa super admin / DSEO wa wilaya).
-- Endesha mara moja: mysql -u root matokeo_db < database/migration_user_region.sql
-- Au import kwenye phpMyAdmin.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `region` varchar(80) DEFAULT NULL AFTER `position`,
  ADD COLUMN IF NOT EXISTS `district` varchar(80) DEFAULT NULL AFTER `region`;
