CREATE DATABASE IF NOT EXISTS reserva_ftic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE reserva_ftic;

START TRANSACTION;

DROP TABLE IF EXISTS `reservation`;
DROP TABLE IF EXISTS `facility`;
DROP TABLE IF EXISTS `messenger_messages`;
DROP TABLE IF EXISTS `user`;

CREATE TABLE IF NOT EXISTS `user` (
	`id` INT AUTO_INCREMENT NOT NULL,
	`email` VARCHAR(180) NOT NULL,
	`roles` JSON NOT NULL,
	`password` VARCHAR(255) NOT NULL,
	`identification` VARCHAR(100) DEFAULT NULL,
	`institutional_email` VARCHAR(180) DEFAULT NULL,
	`first_name` VARCHAR(100) DEFAULT NULL,
	`middle_name` VARCHAR(100) DEFAULT NULL,
	`last_name` VARCHAR(100) DEFAULT NULL,
	`degree` VARCHAR(50) DEFAULT NULL,
	`degree_name` VARCHAR(255) DEFAULT NULL,
	`profile_picture` VARCHAR(255) DEFAULT NULL,
	UNIQUE INDEX UNIQ_8D93D649E7927C74 (`email`),
	PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS `facility` (
	`id` INT AUTO_INCREMENT NOT NULL,
	`name` VARCHAR(255) NOT NULL,
	`capacity` INT NOT NULL,
	`description` LONGTEXT DEFAULT NULL,
	`image` VARCHAR(255) DEFAULT NULL,
	`created_at` DATETIME NOT NULL,
	`updated_at` DATETIME NOT NULL,
	PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

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
	INDEX IDX_42C84955A76ED395 (user_id),
	INDEX IDX_42C849556F96B3A5 (facility_id),
	INDEX IDX_42C84955B63F4517 (suggested_facility_id),
	PRIMARY KEY(`id`),
	CONSTRAINT FK_42C84955A76ED395 FOREIGN KEY (user_id) REFERENCES user (id),
	CONSTRAINT FK_42C849556F96B3A5 FOREIGN KEY (facility_id) REFERENCES facility (id),
	CONSTRAINT FK_42C84955B63F4517 FOREIGN KEY (suggested_facility_id) REFERENCES facility (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS `messenger_messages` (
	`id` BIGINT AUTO_INCREMENT NOT NULL,
	`body` LONGTEXT NOT NULL,
	`headers` LONGTEXT NOT NULL,
	`queue_name` VARCHAR(190) NOT NULL,
	`created_at` DATETIME NOT NULL,
	`available_at` DATETIME NOT NULL,
	`delivered_at` DATETIME DEFAULT NULL,
	INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (`queue_name`, `available_at`, `delivered_at`, `id`),
	PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

INSERT INTO `user` (`email`, `roles`, `password`, `identification`, `institutional_email`, `first_name`, `middle_name`, `last_name`, `degree`, `degree_name`, `profile_picture`) VALUES
	('gmablao@fit.edu.ph', '["ROLE_USER"]', '$2y$10$kavRT7C4.meNbM8Pt.Eu5.a5/oSjTQ6wso5qUrx/8X7BpPIpOLRmq', NULL, 'gmablao@fit.edu.ph', 'Godwin Aldrich', 'Mangaoil', 'Ablao', 'BSITBA', 'Bachelor of Science in Information Technology with specialization in Business Analytics', NULL),
	('asbernil@fit.edu.ph', '["ROLE_USER"]', '$2y$10$kavRT7C4.meNbM8Pt.Eu5.a5/oSjTQ6wso5qUrx/8X7BpPIpOLRmq', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
	('jccolina@fit.edu.ph', '["ROLE_USER"]', '$2y$10$kavRT7C4.meNbM8Pt.Eu5.a5/oSjTQ6wso5qUrx/8X7BpPIpOLRmq', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
	('dghurst@fit.edu.ph', '["ROLE_USER"]', '$2y$10$kavRT7C4.meNbM8Pt.Eu5.a5/oSjTQ6wso5qUrx/8X7BpPIpOLRmq', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
	('SuperAdmin@fit.edu.ph', '["ROLE_SUPER_ADMIN", "ROLE_USER"]', '$2y$10$kavRT7C4.meNbM8Pt.Eu5.a5/oSjTQ6wso5qUrx/8X7BpPIpOLRmq', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

INSERT INTO `facility` (`name`, `capacity`, `description`, `image`, `created_at`, `updated_at`) VALUES
	('CS Project Room', 48, 'Computer Science Project Room equipped with modern facilities and workstations for collaborative project work.', NULL, NOW(), NOW()),
	('Discussion Room 3', 6, 'Intimate discussion room perfect for small group meetings and focused discussions.', NULL, NOW(), NOW()),
	('Discussion Room 4', 8, 'Versatile discussion room suitable for group projects and team collaborations.', NULL, NOW(), NOW()),
	('Presentation Room 1', 40, 'Professional presentation room with advanced audio-visual equipment for seminars and presentations.', NULL, NOW(), NOW()),
	('Presentation Room 2', 60, 'Large presentation room designed for major conferences, lectures, and large-scale presentations.', NULL, NOW(), NOW()),
	('COE Project Room', 48, 'College of Engineering dedicated project room equipped for engineering-related collaborative work.', NULL, NOW(), NOW()),
	('Lounge Area', 150, 'Spacious lounge area perfect for networking, informal gatherings, and social events.', NULL, NOW(), NOW());

COMMIT;
