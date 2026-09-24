ALTER TABLE appointments ADD COLUMN IF NOT EXISTS service_name VARCHAR(120) NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS staff_name VARCHAR(120) NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS salon_name VARCHAR(160) NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS duration_minutes SMALLINT UNSIGNED NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS request_key VARCHAR(64) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_booking_request ON appointments(customer_id, request_key);
CREATE INDEX IF NOT EXISTS idx_staff_availability ON appointments(staff_id, appointment_date, status, start_time, end_time);
ALTER TABLE appointments DROP INDEX IF EXISTS unique_staff_slot;
ALTER TABLE appointments ALTER COLUMN status SET DEFAULT 'confirmed';
UPDATE appointments a JOIN services s ON s.id=a.service_id JOIN staff st ON st.id=a.staff_id JOIN salons sl ON sl.id=a.salon_id SET a.service_name=COALESCE(a.service_name,s.name),a.staff_name=COALESCE(a.staff_name,st.name),a.salon_name=COALESCE(a.salon_name,sl.name),a.duration_minutes=COALESCE(a.duration_minutes,TIME_TO_SEC(TIMEDIFF(a.end_time,a.start_time))/60);
UPDATE appointments SET status='confirmed' WHERE status='pending';
UPDATE users u JOIN salons s ON s.vendor_id=u.id SET u.status=CASE WHEN s.status='approved' THEN 'active' ELSE 'rejected' END WHERE u.status='pending' AND s.status IN ('approved','rejected');
CREATE TABLE IF NOT EXISTS appointment_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 appointment_id INT UNSIGNED NOT NULL,
 actor_id INT UNSIGNED NOT NULL,
 action VARCHAR(30) NOT NULL,
 old_schedule VARCHAR(40) NULL,
 new_schedule VARCHAR(40) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS payment_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 payment_id INT UNSIGNED NOT NULL,
 actor_id INT UNSIGNED NOT NULL,
 request_key VARCHAR(64) NOT NULL,
 status ENUM('pending','paid','failed','refunded') NOT NULL,
 reference VARCHAR(100) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY idx_payment_request(payment_id,request_key),
 FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
