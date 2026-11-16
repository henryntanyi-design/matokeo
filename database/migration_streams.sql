-- ============================================================
-- MIGRATION: Streams kwenye ugawaji wa walimu na ratiba
-- Import kupitia phpMyAdmin > Import (mara moja tu).
-- Inaongeza safu `stream` kwenye majedwali yaliyopo. Data iliyopo
-- inabaki vile vile (stream = '' = darasa zima / hakuna stream).
--
-- Kumbuka: MariaDB 10.4 haitumii RENAME INDEX. Kwa hiyo tunaongeza
-- index mpya kwanza (ili foreign key iwe na index mbadala), kisha
-- tunaondoa index ya zamani.
-- ============================================================
USE `matokeo_db`;
SET NAMES utf8mb4;

-- 1) Ugawaji wa mwalimu: mwalimu anaweza kufundisha darasa/somo kwa stream fulani.
ALTER TABLE `teacher_assignments`
  ADD COLUMN IF NOT EXISTS `stream` varchar(20) NOT NULL DEFAULT '' AFTER `subject_id`;
ALTER TABLE `teacher_assignments`
  ADD UNIQUE KEY IF NOT EXISTS `uq_assign_stream` (`teacher_id`, `class_id`, `subject_id`, `stream`);
ALTER TABLE `teacher_assignments`
  DROP INDEX IF EXISTS `uq_assign`;

-- 2) Mahitaji ya vipindi kwa kila darasa + stream + somo.
ALTER TABLE `timetable_requirements`
  ADD COLUMN IF NOT EXISTS `stream` varchar(20) NOT NULL DEFAULT '' AFTER `subject_id`;
ALTER TABLE `timetable_requirements`
  ADD UNIQUE KEY IF NOT EXISTS `uq_ttr_entity` (`timetable_id`, `class_id`, `subject_id`, `stream`);
ALTER TABLE `timetable_requirements`
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (`timetable_id`, `class_id`, `subject_id`, `stream`);
ALTER TABLE `timetable_requirements`
  DROP INDEX IF EXISTS `uq_ttr_entity`;

-- 3) Vipindi halisi: kila stream ina safu yake ya ratiba.
ALTER TABLE `timetable_entries`
  ADD COLUMN IF NOT EXISTS `stream` varchar(20) NOT NULL DEFAULT '' AFTER `class_id`;
ALTER TABLE `timetable_entries`
  ADD UNIQUE KEY IF NOT EXISTS `uq_tte_slot_stream` (`timetable_id`, `class_id`, `stream`, `day`, `period_no`);
ALTER TABLE `timetable_entries`
  DROP INDEX IF EXISTS `uq_tte_slot`;
