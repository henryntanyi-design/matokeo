-- ============================================================
-- MIGRATION: Moduli ya Kufundisha
-- Ratiba ya kufundisha, scheme of work, logbook na mitala.
-- Import kupitia phpMyAdmin > Import (baada ya schema.sql).
-- Inaunda majedwali matupu pekee; hakuna data iliyopo inayobadilishwa.
-- ============================================================
USE `matokeo_db`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Mitala / scheme of work: mada za kila somo kwa darasa, muhula na wiki.
CREATE TABLE IF NOT EXISTS `syllabus_topics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `academic_year_id` int NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `term` enum('I','II') NOT NULL DEFAULT 'I',
  `week_no` int NOT NULL DEFAULT 1,
  `periods` int NOT NULL DEFAULT 1,
  `topic` varchar(200) NOT NULL,
  `subtopic` varchar(200) DEFAULT NULL,
  `objectives` text,
  `activities` text,
  `materials` text,
  `assessment` text,
  `refs` text,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_syl_lookup` (`school_id`,`academic_year_id`,`class_id`,`subject_id`,`term`,`week_no`),
  CONSTRAINT `fk_syl_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_syl_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_syl_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_syl_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Logbook ya somo: kumbukumbu za kila kipindi kilichofundishwa.
CREATE TABLE IF NOT EXISTS `subject_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `academic_year_id` int NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `teacher_id` int DEFAULT NULL,
  `log_date` date NOT NULL,
  `period` varchar(20) DEFAULT NULL,
  `topic` varchar(200) NOT NULL,
  `activities` text,
  `remarks` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_lookup` (`school_id`,`academic_year_id`,`class_id`,`subject_id`,`log_date`),
  KEY `idx_log_teacher` (`teacher_id`),
  CONSTRAINT `fk_log_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_log_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_log_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_log_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_log_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Ratiba zilizoundwa (timetables) na vipindi vyake.
-- ============================================================
CREATE TABLE IF NOT EXISTS `timetables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `academic_year_id` int NOT NULL,
  `group_level` enum('ordinary','advance','all') NOT NULL DEFAULT 'ordinary',
  `status` enum('Pending','Active','Archived') NOT NULL DEFAULT 'Pending',
  `sessions_per_day` tinyint NOT NULL DEFAULT 11,
  `session_duration` smallint NOT NULL DEFAULT 40,
  `start_time` time NOT NULL DEFAULT '08:00:00',
  `end_time` time NOT NULL DEFAULT '16:30:00',
  `include_saturday` tinyint(1) NOT NULL DEFAULT 0,
  `include_sunday` tinyint(1) NOT NULL DEFAULT 0,
  `breaks_json` text,
  `events_json` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tt_lookup` (`school_id`,`academic_year_id`,`group_level`,`status`),
  CONSTRAINT `fk_tt_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Mahitaji ya vipindi kwa wiki kwa kila darasa, stream na somo.
-- stream = '' inamaanisha darasa zima (hakuna mgawanyo wa stream).
CREATE TABLE IF NOT EXISTS `timetable_requirements` (
  `timetable_id` int NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `stream` varchar(20) NOT NULL DEFAULT '',
  `periods_per_week` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`timetable_id`,`class_id`,`subject_id`,`stream`),
  CONSTRAINT `fk_ttr_tt` FOREIGN KEY (`timetable_id`) REFERENCES `timetables` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ttr_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ttr_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Vipindi halisi vilivyopangwa: darasa, stream, somo, mwalimu, siku na kipindi.
CREATE TABLE IF NOT EXISTS `timetable_entries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `timetable_id` int NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `stream` varchar(20) NOT NULL DEFAULT '',
  `teacher_id` int DEFAULT NULL,
  `day` enum('MON','TUE','WED','THU','FRI','SAT','SUN') NOT NULL,
  `period_no` tinyint NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tte_slot_stream` (`timetable_id`,`class_id`,`stream`,`day`,`period_no`),
  KEY `idx_tte_teacher` (`timetable_id`,`teacher_id`,`day`,`period_no`),
  CONSTRAINT `fk_tte_tt` FOREIGN KEY (`timetable_id`) REFERENCES `timetables` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tte_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tte_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tte_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
