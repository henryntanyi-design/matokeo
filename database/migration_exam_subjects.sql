-- Masomo ya kila darasa kwenye mtihani (exam form: chagua kidato + masomo yake).
-- Endesha mara moja: mysql -u root matokeo_db < database/migration_exam_subjects.sql
-- Au import kwenye phpMyAdmin.
--
-- Bila rekodi za darasa hapa, mfumo hutumia masomo yote ya darasa (mitihani ya zamani).

CREATE TABLE IF NOT EXISTS `exam_class_subjects` (
  `exam_id` int NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  PRIMARY KEY (`exam_id`, `class_id`, `subject_id`),
  KEY `idx_ecs_subject` (`subject_id`),
  CONSTRAINT `fk_ecs_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecs_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecs_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
