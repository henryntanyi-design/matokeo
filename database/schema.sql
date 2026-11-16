-- ============================================================
-- MFUMO WA MATOKEO (SHULE NYINGI) - Database kamili
-- Import kupitia phpMyAdmin > Import (itaunda database `matokeo_db`)
-- ============================================================
CREATE DATABASE IF NOT EXISTS `matokeo_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `matokeo_db`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `subject_logs`, `syllabus_topics`, `password_resets`, `scores`, `exam_candidates`, `exam_schools`, `exam_classes`, `exams`, `student_subjects`, `students`,
  `teacher_assignments`, `class_subjects`, `subjects`, `classes`, `grading_scales`, `academic_years`, `users`, `schools`, `settings`;

CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB;

CREATE TABLE `academic_years` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(20) NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_year` (`name`)
) ENGINE=InnoDB;

-- Shule (kila shule ina watumiaji, wanafunzi, alama na matokeo yake)
CREATE TABLE `schools` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `centre_no` varchar(20) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `ward` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `motto` varchar(200) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_school_name` (`name`),
  UNIQUE KEY `uq_centre_no` (`centre_no`)
) ENGINE=InnoDB;

-- Watumiaji: super (wilaya/DSEO - school_id NULL), admin (mkuu wa shule), teacher
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role` enum('super','admin','teacher') NOT NULL DEFAULT 'teacher',
  `position` varchar(40) NOT NULL DEFAULT '',
  `region` varchar(80) DEFAULT NULL,
  `district` varchar(80) DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `failed_attempts` int NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `must_change` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  KEY `idx_user_school` (`school_id`),
  CONSTRAINT `fk_user_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Maombi ya kureset nenosiri kupitia email (token huhifadhiwa kama hash, dakika 60, mara moja tu)
CREATE TABLE `password_resets` (
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

-- Madarasa / Vidato (level: O = Ordinary, A = Advanced)
CREATE TABLE `classes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `level` enum('O','A') NOT NULL DEFAULT 'O',
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_class_name` (`name`)
) ENGINE=InnoDB;

-- Masomo (level: O, A au B = yote mawili). is_subsidiary = somo la nyongeza la A-Level (GS, BAM)
CREATE TABLE `subjects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `abbr` varchar(20) DEFAULT NULL,
  `level` enum('O','A','B') NOT NULL DEFAULT 'B',
  `is_subsidiary` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subject_code` (`code`)
) ENGINE=InnoDB;

-- Madarasa, masomo na gredi ni vya pamoja kwa shule zote (hudhibitiwa na super admin).
-- Masomo yanayosomwa na kila darasa. Masomo yote ya darasa hufanywa na wanafunzi wote.
CREATE TABLE `class_subjects` (
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `is_compulsory` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`class_id`, `subject_id`),
  CONSTRAINT `fk_cs_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cs_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Mwalimu anafundisha somo gani darasa gani; stream tupu = darasa zima
CREATE TABLE `teacher_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `teacher_id` int NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `stream` varchar(20) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assign_stream` (`teacher_id`, `class_id`, `subject_id`, `stream`),
  CONSTRAINT `fk_ta_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ta_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ta_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Wanafunzi
CREATE TABLE `students` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `admission_no` varchar(40) NOT NULL,
  `prems_no` varchar(40) DEFAULT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `sex` enum('M','F') NOT NULL,
  `dob` date DEFAULT NULL,
  `nationality` varchar(80) DEFAULT 'TANZANIA',
  `class_id` int NOT NULL,
  `stream` varchar(20) DEFAULT NULL,
  `guardian_name` varchar(150) DEFAULT NULL,
  `guardian_phone` varchar(30) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `status` enum('active','transferred','graduated','inactive') NOT NULL DEFAULT 'active',
  `pin_hash` varchar(255) DEFAULT NULL,
  `pin_failed` int NOT NULL DEFAULT 0,
  `pin_locked_until` datetime DEFAULT NULL,
  `must_change` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_school_admission` (`school_id`, `admission_no`),
  UNIQUE KEY `uq_prems_no` (`prems_no`),
  KEY `idx_student_class` (`class_id`),
  KEY `idx_prems` (`prems_no`),
  CONSTRAINT `fk_student_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
) ENGINE=InnoDB;

-- Jedwali la zamani la masomo ya kuchagua ya mwanafunzi; halitumiki tena kwenye usajili/alama.
CREATE TABLE `student_subjects` (
  `student_id` int NOT NULL,
  `subject_id` int NOT NULL,
  PRIMARY KEY (`student_id`, `subject_id`),
  CONSTRAINT `fk_ss_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ss_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Mitihani. owner_school_id NULL = mtihani wa wilaya (super); vinginevyo mtihani wa shule hiyo.
-- status: open = alama zinaweza kuingizwa, closed = zimefungwa (inadhibitiwa na mmiliki wa mtihani).
CREATE TABLE `exams` (
  `id` int NOT NULL AUTO_INCREMENT,
  `owner_school_id` int DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `exam_type` varchar(40) NOT NULL DEFAULT 'Terminal',
  `academic_year_id` int DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exam_year` (`academic_year_id`),
  KEY `idx_exam_owner` (`owner_school_id`),
  CONSTRAINT `fk_exam_owner` FOREIGN KEY (`owner_school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exam_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `exam_classes` (
  `exam_id` int NOT NULL,
  `class_id` int NOT NULL,
  PRIMARY KEY (`exam_id`, `class_id`),
  CONSTRAINT `fk_ec_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ec_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Masomo ya kila darasa kwenye mtihani (huchaguliwa kwenye fomu ya mtihani).
-- Bila rekodi za darasa hapa, mfumo hutumia masomo yote ya darasa (mitihani ya zamani).
CREATE TABLE `exam_class_subjects` (
  `exam_id` int NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  PRIMARY KEY (`exam_id`, `class_id`, `subject_id`),
  KEY `idx_ecs_subject` (`subject_id`),
  CONSTRAINT `fk_ecs_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecs_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecs_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Shule zinazoshiriki mtihani; published = shule hiyo imechapisha matokeo kwa wanafunzi wake
CREATE TABLE `exam_schools` (
  `exam_id` int NOT NULL,
  `school_id` int NOT NULL,
  `published` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`exam_id`, `school_id`),
  CONSTRAINT `fk_es_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_es_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Watahiniwa waliosajiliwa kwenye mtihani (hutengenezwa kiotomatiki kutoka kwa wanafunzi wa madarasa ya mtihani)
CREATE TABLE `exam_candidates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exam_id` int NOT NULL,
  `school_id` int NOT NULL,
  `student_id` int NOT NULL,
  `class_id` int NOT NULL,
  `cand_no` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exam_student` (`exam_id`, `student_id`),
  UNIQUE KEY `uq_exam_candno` (`exam_id`, `cand_no`),
  KEY `idx_cand_class` (`exam_id`, `school_id`, `class_id`),
  CONSTRAINT `fk_cand_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cand_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Alama
CREATE TABLE `scores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exam_id` int NOT NULL,
  `school_id` int NOT NULL,
  `student_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `class_id` int NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `status` enum('PRESENT','ABSENT') NOT NULL DEFAULT 'PRESENT',
  `source` enum('MANUAL','IMPORT') NOT NULL DEFAULT 'MANUAL',
  `entered_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_score` (`exam_id`, `student_id`, `subject_id`),
  KEY `idx_score_class` (`exam_id`, `school_id`, `class_id`, `subject_id`),
  CONSTRAINT `fk_sc_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sc_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sc_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_score` CHECK (`score` IS NULL OR (`score` >= 0 AND `score` <= 100))
) ENGINE=InnoDB;

-- Gredi (kila level)
CREATE TABLE `grading_scales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `level` enum('O','A') NOT NULL,
  `grade` varchar(3) NOT NULL,
  `min_score` decimal(5,2) NOT NULL,
  `points` int NOT NULL,
  `remark` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_level_grade` (`level`, `grade`)
) ENGINE=InnoDB;

-- Mitala / scheme of work: mada za kila somo kwa darasa, muhula na wiki.
CREATE TABLE `syllabus_topics` (
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
CREATE TABLE `subject_logs` (
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

-- ================= DATA ZA MSINGI =================
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('system_name', 'Mfumo wa Matokeo');

INSERT INTO `classes` (`name`, `level`, `sort_order`) VALUES
 ('Form I','O',1), ('Form II','O',2), ('Form III','O',3), ('Form IV','O',4), ('Form V','A',5), ('Form VI','A',6);

INSERT INTO `subjects` (`code`, `name`, `abbr`, `level`, `is_subsidiary`) VALUES
 ('CIV','Civics','CIV','O',0), ('HIST','History','HIST','B',0), ('GEO','Geography','GEO','B',0),
 ('KISW','Kiswahili','KISW','B',0), ('ENGL','English Language','ENGL','B',0), ('BIO','Biology','BIO','B',0),
 ('CHEM','Chemistry','CHEM','B',0), ('PHY','Physics','PHY','B',0), ('BMATH','Basic Mathematics','B/MATH','O',0),
 ('AMATH','Advanced Mathematics','ADV/MATHS','A',0), ('ECON','Economics','ECON','A',0), ('COMM','Commerce','COMM','B',0),
 ('ACC','Accountancy','ACCOUNT','A',0), ('LIT','Literature in English','LIT','B',0), ('BK','Book-Keeping','B/KEEP','O',0),
 ('GS','General Studies','GS','A',1), ('BAM','Basic Applied Mathematics','BAM','A',1);

-- Masomo ya msingi ya Form I-IV (yote ya lazima)
INSERT INTO `class_subjects` (`class_id`, `subject_id`, `is_compulsory`)
SELECT c.id, s.id, 1 FROM `classes` c JOIN `subjects` s
WHERE c.level = 'O' AND s.code IN ('CIV','HIST','GEO','KISW','ENGL','BIO','CHEM','PHY','BMATH');

-- Form V-VI: GS na BAM ni lazima (subsidiary); masomo ya principal ni ya kuchagua (combination ya mwanafunzi)
INSERT INTO `class_subjects` (`class_id`, `subject_id`, `is_compulsory`)
SELECT c.id, s.id, IF(s.is_subsidiary = 1, 1, 0) FROM `classes` c JOIN `subjects` s
WHERE c.level = 'A' AND s.code IN ('GS','BAM','HIST','GEO','KISW','ENGL','PHY','CHEM','BIO','AMATH','ECON','COMM','ACC','LIT');

-- Gredi za NECTA
INSERT INTO `grading_scales` (`level`, `grade`, `min_score`, `points`, `remark`) VALUES
 ('O','A',75,1,'Excellent'), ('O','B',65,2,'Very Good'), ('O','C',45,3,'Good'), ('O','D',30,4,'Satisfactory'), ('O','F',0,5,'Fail'),
 ('A','A',80,1,'Excellent'), ('A','B',70,2,'Very Good'), ('A','C',60,3,'Good'), ('A','D',50,4,'Average'),
 ('A','E',40,5,'Satisfactory'), ('A','S',35,6,'Subsidiary'), ('A','F',0,7,'Fail');
-- Super admin wa kwanza hutengenezwa kupitia install.php (hakuna nenosiri la kudumu). Shule huongezwa na super admin.
