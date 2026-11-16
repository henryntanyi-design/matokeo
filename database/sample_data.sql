-- ============================================================
-- DATA ZA MFANO (hiari) - endesha BAADA ya install.php.
-- Inaongeza shule 2 za mfano, wanafunzi, na mtihani mmoja wa wilaya unaoshirikisha shule zote mbili.
-- Majina ni ya kubuni. Kisha: Super > Schools > (ingia shule) > Teachers kuunda admin/walimu.
-- ============================================================
USE `matokeo_db`;
SET NAMES utf8mb4;

INSERT INTO `schools` (`name`, `centre_no`, `region`, `district`) VALUES
 ('Mfano Secondary School', 'S1001', 'Kagera', 'Ngara'), ('Kilele Secondary School', 'S1002', 'Kagera', 'Ngara');
SET @s1 = (SELECT id FROM schools WHERE centre_no = 'S1001');
SET @s2 = (SELECT id FROM schools WHERE centre_no = 'S1002');

INSERT INTO `students` (`school_id`, `admission_no`, `first_name`, `middle_name`, `last_name`, `sex`, `class_id`) VALUES
 (@s1,'D001','Amina','Salum','Mwakyusa','F',(SELECT id FROM classes WHERE name='Form IV')),
 (@s1,'D002','Baraka','John','Kimaro','M',(SELECT id FROM classes WHERE name='Form IV')),
 (@s1,'D003','Christina','Peter','Msofe','F',(SELECT id FROM classes WHERE name='Form IV')),
 (@s1,'D004','Daniel','Elia','Mrema','M',(SELECT id FROM classes WHERE name='Form IV')),
 (@s1,'V001','Irene','David','Shayo','F',(SELECT id FROM classes WHERE name='Form V')),
 (@s1,'V002','Joseph','Mathias','Kawawa','M',(SELECT id FROM classes WHERE name='Form V')),
 (@s1,'V003','Khadija','Ally','Msangi','F',(SELECT id FROM classes WHERE name='Form V')),
 (@s2,'D001','Evelyn','Hassan','Njau','F',(SELECT id FROM classes WHERE name='Form IV')),
 (@s2,'D002','Frank','Godfrey','Lyimo','M',(SELECT id FROM classes WHERE name='Form IV')),
 (@s2,'D003','Glory','Emanuel','Tarimo','F',(SELECT id FROM classes WHERE name='Form IV')),
 (@s2,'D004','Hamisi','Omari','Bakari','M',(SELECT id FROM classes WHERE name='Form IV')),
 (@s2,'V001','Lameck','Paul','Simon','M',(SELECT id FROM classes WHERE name='Form V')),
 (@s2,'V002','Mariam','Juma','Chuwa','F',(SELECT id FROM classes WHERE name='Form V')),
 (@s2,'V003','Nuhu','Said','Mushi','M',(SELECT id FROM classes WHERE name='Form V'));

-- Mtihani wa wilaya (owner_school_id NULL) unaoshirikisha shule zote mbili
INSERT INTO `exams` (`owner_school_id`, `name`, `exam_type`, `academic_year_id`) VALUES
 (NULL, 'District Mid-Term Examination (Mfano)', 'Mid-Term', (SELECT id FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1));
SET @eid = LAST_INSERT_ID();
INSERT INTO `exam_classes` (`exam_id`, `class_id`) SELECT @eid, id FROM classes WHERE name IN ('Form IV', 'Form V');
INSERT INTO `exam_schools` (`exam_id`, `school_id`) VALUES (@eid, @s1), (@eid, @s2);
