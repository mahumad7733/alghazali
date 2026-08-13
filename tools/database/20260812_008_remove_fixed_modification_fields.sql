-- الحقول التالية لها نموذج ثابت داخل bus_flight_bookings_details.php.
-- حذفها من show_fields يمنع إعادة رسمها كحقول ديناميكية وتكرارها.
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'modification_type,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',modification_type', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'requested_from_city_id,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',requested_from_city_id', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'requested_to_city_id,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',requested_to_city_id', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'requested_mod_date,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',requested_mod_date', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'requested_departure_time,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',requested_departure_time', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'mod_reason,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',mod_reason', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'charge_penalty,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',charge_penalty', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'modification_penalty_percent,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',modification_penalty_percent', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'modification_penalty_amount,', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, ',modification_penalty_amount', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = REPLACE(show_fields, 'modification_penalty_amount', '')
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
UPDATE workflow_steps
SET show_fields = TRIM(BOTH ',' FROM show_fields)
WHERE step_name LIKE '%تعديل%' OR step_name LIKE '%معدل%';
