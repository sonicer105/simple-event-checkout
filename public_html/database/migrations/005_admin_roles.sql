-- Add admin roles so check-in-only accounts can be created.

ALTER TABLE admins
  ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'full' AFTER email;

UPDATE admins
SET role = 'full'
WHERE role IS NULL OR role = '';
