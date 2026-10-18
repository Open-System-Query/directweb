UPDATE plugins SET enabled=0 WHERE id='mail' AND EXISTS (SELECT 1 FROM settings WHERE key='mail' AND json_valid(value) AND json_extract(value,'$.enabled')=0);
UPDATE settings SET value=json_remove(value,'$.enabled') WHERE key='mail' AND json_valid(value);
