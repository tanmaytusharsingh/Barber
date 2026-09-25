CREATE TABLE IF NOT EXISTS appointment_services (
 appointment_id INT UNSIGNED NOT NULL,
 position TINYINT UNSIGNED NOT NULL,
 service_id INT UNSIGNED NOT NULL,
 staff_id INT UNSIGNED NOT NULL,
 start_time TIME NOT NULL,
 end_time TIME NOT NULL,
 PRIMARY KEY (appointment_id,position),
 INDEX idx_segment_availability (staff_id,start_time,end_time),
 FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
 FOREIGN KEY (service_id) REFERENCES services(id),
 FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB;
