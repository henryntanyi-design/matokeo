-- Nafasi/wadhifa wa watumiaji wa shule: Teacher, Academic Master, Second Master, Headmaster, Admin.
-- `role` inabaki kuwa ngazi ya ufikiaji (teacher/admin/super); `position` ni wadhifa unaoonekana.
ALTER TABLE `users`
  ADD COLUMN `position` varchar(40) NOT NULL DEFAULT '' AFTER `role`;

UPDATE `users` SET `position` = 'admin' WHERE `role` IN ('admin', 'super') AND `position` = '';
UPDATE `users` SET `position` = 'teacher' WHERE `role` = 'teacher' AND `position` = '';
