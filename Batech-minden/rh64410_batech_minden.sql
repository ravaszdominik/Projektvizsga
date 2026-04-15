-- ============================================
-- BaTech - Teljes adatbázis séma
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `rh64410_batech_minden`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `rh64410_batech_minden`;

-- ============================================
-- FELHASZNÁLÓK
-- ============================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`            int(11)       NOT NULL AUTO_INCREMENT,
  `name`          varchar(100)  NOT NULL,
  `email`         varchar(100)  NOT NULL,
  `phone`         varchar(20)   DEFAULT NULL,
  `address`       varchar(255)  DEFAULT NULL,
  `password_hash` varchar(255)  NOT NULL,
  `user_type`     enum('user','admin') NOT NULL DEFAULT 'user',
  `status`        enum('active','inactive','banned') NOT NULL DEFAULT 'active',
  `newsletter`    tinyint(1)    NOT NULL DEFAULT 0,
  `avatar`        varchar(255)  DEFAULT NULL,
  `email_verified`     tinyint(1)    NOT NULL DEFAULT 0,
  `verification_token` varchar(64)   DEFAULT NULL,
  `created_at`    datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SZOLGÁLTATÁSOK
-- ============================================
CREATE TABLE IF NOT EXISTS `services` (
  `id`                 int(11)       NOT NULL AUTO_INCREMENT,
  `name`               varchar(100)  NOT NULL,
  `title`              varchar(100)  NOT NULL,
  `description`        text          NOT NULL,
  `category`           varchar(50)   NOT NULL,
  `price_range`        varchar(100)  DEFAULT NULL,
  `estimated_duration` varchar(50)   DEFAULT NULL,
  `priority`           enum('normal','high','urgent') NOT NULL DEFAULT 'normal',
  `icon`               varchar(50)   DEFAULT 'fa-tools',
  `active`             tinyint(1)    NOT NULL DEFAULT 1,
  `display_order`      int(11)       NOT NULL DEFAULT 0,
  `created_at`         datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- IDŐPONTFOGLALÁSOK
-- ============================================
CREATE TABLE IF NOT EXISTS `bookings` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`      int(11)      NOT NULL,
  `service_type` varchar(100) NOT NULL,
  `booking_date` date         NOT NULL,
  `booking_time` varchar(10)  NOT NULL,
  `note`         text         DEFAULT NULL,
  `status`       enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `bookings_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ÉRTÉKELÉSEK
-- ============================================
CREATE TABLE IF NOT EXISTS `reviews` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    int(11)      DEFAULT NULL,
  `guest_name` varchar(100) DEFAULT NULL,
  `rating`     tinyint(1)   NOT NULL,
  `comment`    text         NOT NULL,
  `approved`   tinyint(1)   NOT NULL DEFAULT 0,
  `created_at` datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `reviews_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- REFERENCIÁK
-- ============================================
CREATE TABLE IF NOT EXISTS `references` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`        int(11)      DEFAULT NULL,
  `title`          varchar(150) NOT NULL,
  `description`    text         NOT NULL,
  `category`       varchar(50)  DEFAULT NULL,
  `image_url`      varchar(255) DEFAULT NULL,
  `duration`       varchar(50)  DEFAULT NULL,
  `price`          varchar(50)  DEFAULT NULL,
  `date_completed` date         DEFAULT NULL,
  `approved`       tinyint(1)   NOT NULL DEFAULT 0,
  `created_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `references_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- REFERENCIA KÉPEK
-- ============================================
CREATE TABLE IF NOT EXISTS `reference_images` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `reference_id` int(11)      NOT NULL,
  `image_url`    varchar(255) NOT NULL,
  `sort_order`   int(11)      NOT NULL DEFAULT 0,
  `created_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `reference_id` (`reference_id`),
  CONSTRAINT `ref_images_fk` FOREIGN KEY (`reference_id`) REFERENCES `references` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TÖRÖLT FELHASZNÁLÓK
-- ============================================
CREATE TABLE IF NOT EXISTS `deleted_users` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`      int(11)      DEFAULT NULL,
  `name`         varchar(100) NOT NULL,
  `email`        varchar(100) NOT NULL,
  `reason`       text         NOT NULL,
  `deleted_by`   int(11)      DEFAULT NULL,
  `deleted_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- KÉPEK (TINIFY FELTÖLTÉSEK)
-- ============================================
CREATE TABLE IF NOT EXISTS `images` (
  `id`                int(11)      NOT NULL AUTO_INCREMENT,
  `original_filename` varchar(255) NOT NULL,
  `hashed_filename`   varchar(255) NOT NULL,
  `filesize`          int(11)      NOT NULL,
  `created_at`        datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EMLÉKEZZ RÁM TOKENEK
-- ============================================
CREATE TABLE IF NOT EXISTS `user_tokens` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    int(11)      NOT NULL,
  `token`      varchar(64)  NOT NULL,
  `expires_at` datetime     NOT NULL,
  `created_at` datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `tokens_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DEMO ADATOK
-- ============================================

-- Admin felhasználó (jelszó: Admin123)
INSERT INTO `users` (`name`, `email`, `password_hash`, `user_type`, `status`) VALUES
('Adminisztrátor', 'admin@batech.hu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active');

-- Szolgáltatások
INSERT INTO `services` (`name`, `title`, `description`, `category`, `price_range`, `estimated_duration`, `priority`, `icon`, `active`, `display_order`) VALUES
('Csapcsere', 'Csapcsere', 'Mosogató vagy fürdőszobai csap cseréje', 'vízszerelés', '8.000 - 15.000', '1-2 óra', 'normal', 'fa-faucet', 1, 1),
('Vízvezeték javítás', 'Vízvezeték javítás', 'Szivárgó cső javítása vagy cseréje', 'vízszerelés', '12.000 - 25.000', '2-4 óra', 'normal', 'fa-wrench', 1, 2),
('Vízmelegítő telepítés', 'Vízmelegítő telepítés', 'Új vízmelegítő telepítése és bekötése', 'vízszerelés', '25.000 - 40.000', '3-5 óra', 'normal', 'fa-thermometer-half', 1, 3),
('WC javítás', 'WC javítás', 'Öblítő rendszer javítása, tömítés csere', 'vízszerelés', '10.000 - 18.000', '1-2 óra', 'normal', 'fa-toilet', 1, 4),
('Gázbojler telepítés', 'Gázbojler telepítés', 'Új gázbojler telepítése és bekötése', 'gázerősítés', '35.000 - 50.000', '4-6 óra', 'normal', 'fa-fire', 1, 1),
('Gázvezeték ellenőrzés', 'Gázvezeték ellenőrzés', 'Teljes biztonsági ellenőrzés és nyomáspróba', 'gázerősítés', '15.000', '1-2 óra', 'high', 'fa-search', 1, 2),
('Gázkonvektor javítás', 'Gázkonvektor javítás', 'Gázkonvektor karbantartása és javítása', 'gázerősítés', '20.000 - 30.000', '2-3 óra', 'normal', 'fa-tools', 1, 3),
('Vészhelyzeti kijövetel', 'Vészhelyzeti kijövetel', '0-24 órás azonnali kiszállás', 'sürgősségi', '20.000', '1 óra', 'urgent', 'fa-exclamation-triangle', 1, 1),
('Azonnali javítás', 'Azonnali javítás', '2 órán belüli megjelenés és javítás', 'sürgősségi', '30.000 + anyag', '2-4 óra', 'urgent', 'fa-bolt', 1, 2);

-- ============================================
-- JELSZÓ VISSZAÁLLÍTÁS
-- ============================================
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `email`      varchar(100) NOT NULL,
  `token`      varchar(64)  NOT NULL,
  `expires_at` datetime     NOT NULL,
  `created_at` datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `token` (`token`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- BEJELENTKEZÉSI KÍSÉRLETEK (RATE LIMITING)
-- ============================================
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `ip`         varchar(45)  NOT NULL,
  `email`      varchar(100) DEFAULT NULL,
  `attempted_at` datetime   NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- KAPCSOLATFELVÉTELI ÜZENETEK
-- ============================================
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `email`      varchar(100) NOT NULL,
  `phone`      varchar(20)  DEFAULT NULL,
  `subject`    varchar(200) DEFAULT NULL,
  `message`    text         NOT NULL,
  `read`       tinyint(1)   NOT NULL DEFAULT 0,
  `replied`    tinyint(1)   NOT NULL DEFAULT 0,
  `created_at` datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SZOLGÁLTATÁSI TERÜLETEK
-- ============================================
CREATE TABLE IF NOT EXISTS `service_areas` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `city`        varchar(100) NOT NULL,
  `postal_code` varchar(10)  NOT NULL,
  `district`    varchar(50)  DEFAULT NULL,
  `active`      tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `postal_code` (`postal_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADMIN AUDIT LOG
-- ============================================
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     int(11)      DEFAULT NULL,
  `user_name`   varchar(100) DEFAULT NULL,
  `action`      varchar(100) NOT NULL,
  `target_type` varchar(50)  DEFAULT NULL,
  `target_id`   int(11)      DEFAULT NULL,
  `details`     text         DEFAULT NULL,
  `ip_address`  varchar(45)  DEFAULT NULL,
  `created_at`  datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- ÉRTESÍTÉSEK
-- ============================================
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`      int(11)      NOT NULL,
  `type`         varchar(50)  DEFAULT NULL,
  `title`        varchar(255) DEFAULT NULL,
  `message`      text         DEFAULT NULL,
  `reference_id` int(11)      DEFAULT NULL,
  `read`         tinyint(1)   NOT NULL DEFAULT 0,
  `created_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- ============================================
-- INDEXEK (TELJESÍTMÉNY)
-- ============================================
ALTER TABLE `bookings`    ADD INDEX IF NOT EXISTS `idx_status`   (`status`);
ALTER TABLE `reviews`     ADD INDEX IF NOT EXISTS `idx_approved` (`approved`);
ALTER TABLE `references`  ADD INDEX IF NOT EXISTS `idx_approved` (`approved`);
ALTER TABLE `login_attempts` ADD INDEX IF NOT EXISTS `idx_ip_time` (`ip`, `attempted_at`);
ALTER TABLE `password_resets` ADD INDEX IF NOT EXISTS `idx_expires` (`expires_at`);

