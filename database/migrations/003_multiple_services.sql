ALTER TABLE appointments ADD COLUMN IF NOT EXISTS service_items TEXT NULL;
ALTER TABLE appointments MODIFY COLUMN service_name TEXT NULL;
