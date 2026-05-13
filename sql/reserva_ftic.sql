CREATE DATABASE IF NOT EXISTS `reserva_ftic`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `reserva_ftic`;

START TRANSACTION;

DROP TABLE IF EXISTS `facility_image`;
DROP TABLE IF EXISTS `facility_schedule_block`;
DROP TABLE IF EXISTS `reservation`;
DROP TABLE IF EXISTS `mentoring_appointment`;
DROP TABLE IF EXISTS `mentor_custom_request`;
DROP TABLE IF EXISTS `mentor_availability`;
DROP TABLE IF EXISTS `mentor_application`;
DROP TABLE IF EXISTS `mentor_profile`;
DROP TABLE IF EXISTS `research_content`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `facility`;
DROP TABLE IF EXISTS `messenger_messages`;
DROP TABLE IF EXISTS `user`;
DROP TABLE IF EXISTS `doctrine_migration_versions`;

CREATE TABLE IF NOT EXISTS `user` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `email` VARCHAR(180) NOT NULL,
    `roles` JSON NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `is_verified` TINYINT(1) NOT NULL,
    `verification_code` VARCHAR(10) DEFAULT NULL,
    `first_name` VARCHAR(100) DEFAULT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) DEFAULT NULL,
    `degree` VARCHAR(50) DEFAULT NULL,
    `degree_name` VARCHAR(255) DEFAULT NULL,
    `profile_picture` VARCHAR(255) DEFAULT NULL,
    UNIQUE INDEX `UNIQ_8D93D649E7927C74` (`email`),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `facility` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `capacity` INT NOT NULL,
    `description` LONGTEXT DEFAULT NULL,
    `parent_id` INT DEFAULT NULL,
    `image` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `IDX_9DFB30D727ACA70` (`parent_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_facility_parent`
        FOREIGN KEY (`parent_id`)
        REFERENCES `facility` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mentor_profile` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `user_id` INT NOT NULL,
    `display_name` VARCHAR(255) NOT NULL,
    `specialization` VARCHAR(255) NOT NULL,
    `bio` LONGTEXT DEFAULT NULL,
    `engagement_points` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    UNIQUE INDEX `UNIQ_185C512AA76ED395` (`user_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_185C512AA76ED395`
        FOREIGN KEY (`user_id`)
        REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mentor_application` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `student_id` INT NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) DEFAULT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) DEFAULT NULL,
    `contact_number` VARCHAR(20) DEFAULT NULL,
    `specialization` VARCHAR(255) NOT NULL,
    `reason` LONGTEXT DEFAULT NULL,
    `years_of_experience` INT DEFAULT NULL,
    `current_profession` VARCHAR(150) DEFAULT NULL,
    `highest_education` VARCHAR(150) DEFAULT NULL,
    `supporting_description` LONGTEXT DEFAULT NULL,
    `proof_of_expertise` JSON DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
    `valid_until` DATETIME DEFAULT NULL,
    `admin_note` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `IDX_E001ECD7CB944F1A` (`student_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_E001ECD7CB944F1A`
        FOREIGN KEY (`student_id`)
        REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mentor_availability` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `mentor_id` INT NOT NULL,
    `available_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `is_booked` TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `IDX_CB274DDFDB403044` (`mentor_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_CB274DDFDB403044`
        FOREIGN KEY (`mentor_id`)
        REFERENCES `mentor_profile` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reservation` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `user_id` INT NOT NULL,
    `facility_id` INT NOT NULL,
    `suggested_facility_id` INT DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `contact` VARCHAR(20) NOT NULL,
    `reservation_date` DATETIME NOT NULL,
    `reservation_start_time` TIME NOT NULL,
    `reservation_end_time` TIME NOT NULL,
    `capacity` INT NOT NULL,
    `purpose` LONGTEXT DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    `rejection_reason` VARCHAR(255) DEFAULT NULL,
    INDEX `IDX_42C84955A76ED395` (`user_id`),
    INDEX `IDX_42C84955A7014910` (`facility_id`),
    INDEX `IDX_42C8495568500A62` (`suggested_facility_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_42C84955A76ED395`
        FOREIGN KEY (`user_id`)
        REFERENCES `user` (`id`),
    CONSTRAINT `FK_42C849556F96B3A5`
        FOREIGN KEY (`facility_id`)
        REFERENCES `facility` (`id`),
    CONSTRAINT `FK_42C84955B63F4517`
        FOREIGN KEY (`suggested_facility_id`)
        REFERENCES `facility` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mentoring_appointment` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `student_id` INT NOT NULL,
    `mentor_id` INT NOT NULL,
    `availability_id` INT DEFAULT NULL,
    `scheduled_at` DATETIME NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
    `topic` LONGTEXT DEFAULT NULL,
    `notes` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `IDX_8538E640CB944F1A` (`student_id`),
    INDEX `IDX_8538E640DB403044` (`mentor_id`),
    INDEX `IDX_8538E64061778466` (`availability_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_8538E640CB944F1A`
        FOREIGN KEY (`student_id`)
        REFERENCES `user` (`id`),
    CONSTRAINT `FK_8538E640DB403044`
        FOREIGN KEY (`mentor_id`)
        REFERENCES `mentor_profile` (`id`),
    CONSTRAINT `FK_8538E64061778466`
        FOREIGN KEY (`availability_id`)
        REFERENCES `mentor_availability` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mentor_custom_request` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `student_id` INT NOT NULL,
    `mentor_profile_id` INT DEFAULT NULL,
    `message` LONGTEXT NOT NULL,
    `status` VARCHAR(50) NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    `mentor_response` LONGTEXT DEFAULT NULL,
    `full_name` VARCHAR(255) DEFAULT NULL,
    `department_course` VARCHAR(255) DEFAULT NULL,
    `preferred_expertise` VARCHAR(255) DEFAULT NULL,
    `preferred_schedule` VARCHAR(255) DEFAULT NULL,
    `assigned_mentor_name` VARCHAR(255) DEFAULT NULL,
    `assigned_mentor_expertise` VARCHAR(255) DEFAULT NULL,
    `available_dates` VARCHAR(255) DEFAULT NULL,
    `available_time` VARCHAR(255) DEFAULT NULL,
    `meeting_method` VARCHAR(50) DEFAULT NULL,
    `admin_instructions` LONGTEXT DEFAULT NULL,
    `responded_at` DATETIME DEFAULT NULL,
    INDEX `IDX_3A5F8C4FCB944F1A` (`student_id`),
    INDEX `IDX_3A5F8C4C20F29E8B` (`mentor_profile_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_3A5F8C4FCB944F1A`
        FOREIGN KEY (`student_id`)
        REFERENCES `user` (`id`),
    CONSTRAINT `FK_3A5F8C4C20F29E8B`
        FOREIGN KEY (`mentor_profile_id`)
        REFERENCES `mentor_profile` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `research_content` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `author_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'Article',
    `category` VARCHAR(100) NOT NULL DEFAULT 'General',
    `tags` VARCHAR(255) DEFAULT NULL,
    `summary` LONGTEXT DEFAULT NULL,
    `body` LONGTEXT DEFAULT NULL,
    `file_path` VARCHAR(255) DEFAULT NULL,
    `embedded_link` LONGTEXT DEFAULT NULL,
    `external_link` LONGTEXT DEFAULT NULL,
    `visibility` VARCHAR(30) NOT NULL DEFAULT 'Public',
    `repository_type` VARCHAR(100) DEFAULT NULL,
    `authors` LONGTEXT DEFAULT NULL,
    `abstract` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `IDX_676B80B3F675F31B` (`author_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_676B80B3F675F31B`
        FOREIGN KEY (`author_id`)
        REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `user_id` INT NOT NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'general',
    `title` VARCHAR(255) NOT NULL,
    `message` LONGTEXT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    `reference_id` INT DEFAULT NULL,
    INDEX `IDX_16C413C5A76ED395` (`user_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_16C413C5A76ED395`
        FOREIGN KEY (`user_id`)
        REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `facility_image` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `facility_id` INT NOT NULL,
    `path` VARCHAR(255) NOT NULL,
    `caption` VARCHAR(255) DEFAULT NULL,
    `position` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    INDEX `IDX_39CDA0059F7E4405` (`facility_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_39CDA0059F7E4405`
        FOREIGN KEY (`facility_id`)
        REFERENCES `facility` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `facility_schedule_block` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `facility_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `block_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `source` VARCHAR(255) DEFAULT NULL,
    `schedule_identifier` VARCHAR(255) DEFAULT NULL,
    `notes` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `IDX_77E5F95D9F7E4405` (`facility_id`),
    INDEX `facility_schedule_block_lookup_idx`
        (`facility_id`, `block_date`, `start_time`, `end_time`),
    PRIMARY KEY (`id`),
    CONSTRAINT `FK_77E5F95D9F7E4405`
        FOREIGN KEY (`facility_id`)
        REFERENCES `facility` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doctrine_migration_versions` (
    `version` VARCHAR(191) NOT NULL,
    `executed_at` DATETIME DEFAULT NULL,
    `execution_time` INT DEFAULT NULL,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messenger_messages` (
    `id` BIGINT AUTO_INCREMENT NOT NULL,
    `body` LONGTEXT NOT NULL,
    `headers` LONGTEXT NOT NULL,
    `queue_name` VARCHAR(190) NOT NULL,
    `created_at` DATETIME NOT NULL,
    `available_at` DATETIME NOT NULL,
    `delivered_at` DATETIME DEFAULT NULL,
    INDEX `IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750`
        (`queue_name`, `available_at`, `delivered_at`, `id`),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `user`
(`email`, `roles`, `password`, `is_verified`, `verification_code`,
 `first_name`, `middle_name`, `last_name`,
 `degree`, `degree_name`, `profile_picture`)
VALUES
(
    'SuperAdmin@feutech.edu.ph',
    '["ROLE_SUPER_ADMIN", "ROLE_USER"]',
    '$2y$10$kavRT7C4.meNbM8Pt.Eu5.a5/oSjTQ6wso5qUrx/8X7BpPIpOLRmq',
    1,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL
),
(
    'admin@feutech.edu.ph',
    '["ROLE_ADMIN"]',
    '$2y$10$kavRT7C4.meNbM8Pt.Eu5.a5/oSjTQ6wso5qUrx/8X7BpPIpOLRmq',
    1,
    NULL,
    'Admin',
    NULL,
    'User',
    NULL,
    NULL,
    NULL
),
(
    'faculty.test@feutech.edu.ph',
    '["ROLE_FACULTY", "ROLE_USER"]',
    '$2y$10$v442gQr85NGBS6zxvVX8b.DjPSrhiURe1hKOpOzhz2O2HZkZqaYm6',
    1,
    NULL,
    'Faculty',
    NULL,
    'Tester',
    NULL,
    NULL,
    NULL
);

INSERT INTO `facility`
(`name`, `capacity`, `description`, `image`, `created_at`, `updated_at`)
VALUES
(
    'CS Project Room',
    48,
    'Computer Science Project Room equipped with modern facilities and workstations for collaborative project work.',
    NULL,
    NOW(),
    NOW()
),
(
    'Discussion Room 3',
    6,
    'Intimate discussion room perfect for small group meetings and focused discussions.',
    NULL,
    NOW(),
    NOW()
),
(
    'Discussion Room 4',
    8,
    'Versatile discussion room suitable for group projects and team collaborations.',
    NULL,
    NOW(),
    NOW()
),
(
    'Presentation Room 1',
    40,
    'Professional presentation room with advanced audio-visual equipment for seminars and presentations.',
    NULL,
    NOW(),
    NOW()
),
(
    'Presentation Room 2',
    60,
    'Large presentation room designed for major conferences, lectures, and large-scale presentations.',
    NULL,
    NOW(),
    NOW()
),
(
    'COE Project Room',
    48,
    'College of Engineering dedicated project room equipped for engineering-related collaborative work.',
    NULL,
    NOW(),
    NOW()
),
(
    'Lounge Area',
    150,
    'Spacious lounge area perfect for networking, informal gatherings, and social events.',
    NULL,
    NOW(),
    NOW()
),
(
    '3D Printing',
    30,
    '3D Printing area equipped for prototyping and fabrication activities.',
    NULL,
    NOW(),
    NOW()
);

INSERT INTO `mentor_profile`
(`user_id`, `display_name`, `specialization`, `bio`, `engagement_points`, `created_at`)
VALUES
(
    3,
    'Faculty Tester',
    'Faculty Mentor',
    'Automatically added faculty mentor for testing.',
    0,
    NOW()
);

INSERT INTO `doctrine_migration_versions`
(`version`, `executed_at`, `execution_time`)
VALUES
('DoctrineMigrations\\Version20260221184810', NOW(), 100),
('DoctrineMigrations\\Version20260222000000', NOW(), 100),
('DoctrineMigrations\\Version20260222090000', NOW(), 100),
('DoctrineMigrations\\Version20260222120000', NOW(), 100),
('DoctrineMigrations\\Version20260428143000', NOW(), 100),
('DoctrineMigrations\\Version20260428150000', NOW(), 100),
('DoctrineMigrations\\Version20260501102253', NOW(), 100),
('DoctrineMigrations\\Version20260501104423', NOW(), 100),
('DoctrineMigrations\\Version20260508145037', NOW(), 100),
('DoctrineMigrations\\Version20260509084221', NOW(), 100),
('DoctrineMigrations\\Version20260509150000', NOW(), 100),
('DoctrineMigrations\\Version20260509220000', NOW(), 100),
('DoctrineMigrations\\Version20260510010000', NOW(), 100),
('DoctrineMigrations\\Version20260512073552', NOW(), 100),
('DoctrineMigrations\\Version20260512082900', NOW(), 100),
('DoctrineMigrations\\Version20260512130000', NOW(), 100),
('DoctrineMigrations\\Version20261202000000', NOW(), 100);

COMMIT;