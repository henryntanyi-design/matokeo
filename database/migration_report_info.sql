-- Taarifa za awali za ripoti (Report preliminary information)
-- Inatumika na admin/report_info.php; jedwali pia hutengenezwa kiotomatiki mara ya kwanza.
CREATE TABLE IF NOT EXISTS `report_info` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `class_id` int NOT NULL,
  `term` enum('I','II') NOT NULL DEFAULT 'I',
  `closing_date` date DEFAULT NULL,
  `opening_date` date DEFAULT NULL,
  `class_teacher_id` int DEFAULT NULL,
  `subject_teacher_id` int DEFAULT NULL,
  `headmaster_id` int DEFAULT NULL,
  `instructions` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_info` (`school_id`,`class_id`,`term`),
  CONSTRAINT `fk_ri_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ri_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
