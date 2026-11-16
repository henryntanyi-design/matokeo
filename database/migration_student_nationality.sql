-- Uraia (nationality) wa mwanafunzi + PREMS No kuwa kitambulisho cha kipekee shule zote.
-- Endesha mara moja: mysql -u root matokeo_db < database/migration_student_nationality.sql
-- Au import kwenye phpMyAdmin.

ALTER TABLE `students`
  ADD COLUMN IF NOT EXISTS `nationality` varchar(80) DEFAULT 'TANZANIA' AFTER `dob`;

-- PREMS No ni ya lazima kwa usajili mpya na lazima itofautishe mwanafunzi shule zote.
SET @has_uq := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'students' AND index_name = 'uq_prems_no'
);
SET @sql_uq := IF(@has_uq = 0,
  'ALTER TABLE `students` ADD UNIQUE KEY `uq_prems_no` (`prems_no`)',
  'SELECT 1'
);
PREPARE stmt_uq FROM @sql_uq;
EXECUTE stmt_uq;
DEALLOCATE PREPARE stmt_uq;
