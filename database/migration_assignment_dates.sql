-- Tarehe ya kusajiliwa somo kwa mwalimu (teacher_assignments.created_at).
-- Endesha mara moja: mysql -u root matokeo_db < database/migration_assignment_dates.sql
-- Au import kwenye phpMyAdmin.
--
-- Safu hii hutumika kwenye ukurasa wa "Shule zilizosajili somo" kuonyesha
-- tarehe ambayo somo lilisajiliwa kwa mwalimu wa shule husika.

ALTER TABLE `teacher_assignments`
  ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `stream`;
