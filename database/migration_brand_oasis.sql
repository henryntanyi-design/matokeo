-- Badilisha jina la mfumo lililowekwa na schema.sql ya zamani kwenda OASIS.
-- Inagusa tu kama jina bado ni lile la zamani; jina lililobadilishwa na mtumiaji haligusiwi.
UPDATE `settings`
   SET `setting_value` = 'Operations & Academic School Information (OASIS)'
 WHERE `setting_key` = 'system_name'
   AND `setting_value` IN (
     'Mfumo wa Matokeo',
     'Integrated Educational Performance Analysis System (iEPAS)',
     'Integrated Educational Performance Analysis System (iEPAS) '
   );
