-- ============================================================
-- Rekebisho kwa database ILIYOKWISHA kusakinishwa (matokeo_db): huduma ya kureset nenosiri.
-- Endesha kwenye phpMyAdmin > SQL (database matokeo_db). Ni salama kuiendesha mara mbili (MariaDB/XAMPP).
-- Data hazifutwi. Usiiendeshe kama umeimport schema.sql mpya (tayari ina haya).
-- ============================================================
USE `matokeo_db`;

ALTER TABLE `users`    ADD COLUMN IF NOT EXISTS `must_change` tinyint(1) NOT NULL DEFAULT 0 AFTER `locked_until`;
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `must_change` tinyint(1) NOT NULL DEFAULT 0 AFTER `pin_locked_until`;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_pr_user` (`user_id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
