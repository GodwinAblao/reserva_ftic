-- Add parent_id column to facility table for hierarchy
ALTER TABLE facility ADD COLUMN parent_id INT NULL AFTER description;
ALTER TABLE facility ADD CONSTRAINT fk_facility_parent FOREIGN KEY (parent_id) REFERENCES facility(id) ON DELETE CASCADE;

-- Insert new facilities
INSERT INTO facility (name, capacity, description, created_at, updated_at) VALUES
('3D Printing Lab', 20, '3D Printing Laboratory equipped with modern 3D printers for prototyping and design projects.', NOW(), NOW()),
('Lounge 1', 30, 'Lounge Area Section 1 for small group discussions and casual meetings.', NOW(), NOW()),
('Lounge 2', 30, 'Lounge Area Section 2 for collaborative work and relaxation.', NOW(), NOW()),
('Lounge 3', 30, 'Lounge Area Section 3 for study groups and informal gatherings.', NOW(), NOW()),
('Lounge 4', 30, 'Lounge Area Section 4 for networking and social events.', NOW(), NOW());

-- Set Lounge 1-4 as children of Lounge Area
UPDATE facility f1
JOIN facility f2 ON f2.name = 'Lounge Area'
SET f1.parent_id = f2.id
WHERE f1.name IN ('Lounge 1', 'Lounge 2', 'Lounge 3', 'Lounge 4');
