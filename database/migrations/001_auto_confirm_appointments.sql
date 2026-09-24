-- Apply once to an existing installation. Safe to run again.
-- Payment status and vendor registration approval are independent of booking status.
ALTER TABLE appointments ALTER COLUMN status SET DEFAULT 'confirmed';
UPDATE appointments SET status = 'confirmed' WHERE status = 'pending';
