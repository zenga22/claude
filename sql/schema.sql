-- Staffing Event Signup Application - Database Schema
-- PHP 7.4 / MySQL

CREATE DATABASE IF NOT EXISTS staffing_events CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE staffing_events;

-- Users table (pre-defined accounts)
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Events defined by date and location
CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    event_date DATE NOT NULL,
    location VARCHAR(255) NOT NULL,
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Functions (roles/positions) within an event
CREATE TABLE event_functions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    function_name VARCHAR(255) NOT NULL,
    description TEXT,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Time periods within a function, each with a max number of signups
CREATE TABLE event_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    function_id INT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_signups INT UNSIGNED NOT NULL DEFAULT 1,
    FOREIGN KEY (function_id) REFERENCES event_functions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- User signups for specific periods
CREATE TABLE signups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    signed_up_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (period_id) REFERENCES event_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_signup (period_id, user_id)
) ENGINE=InnoDB;

-- -------------------------------------------------------
-- Seed data
-- -------------------------------------------------------

-- Default admin user (password: admin123)
INSERT INTO users (username, email, password_hash, is_admin) VALUES
('admin', 'admin@example.com', '$2y$12$rKOThvp7YybKaqxTgsiow.n64R77cJk6AiLCI2QvAPgP8axyfQQW2', 1);

-- Sample regular users (password: password123)
INSERT INTO users (username, email, password_hash, is_admin) VALUES
('jdoe',    'jdoe@example.com',    '$2y$12$aHeisQckSF0s1x7DnURRj.sRwQriHn8LterXiGfoX5l70quDnKuY2', 0),
('jsmith',  'jsmith@example.com',  '$2y$12$aHeisQckSF0s1x7DnURRj.sRwQriHn8LterXiGfoX5l70quDnKuY2', 0),
('mbrown',  'mbrown@example.com',  '$2y$12$aHeisQckSF0s1x7DnURRj.sRwQriHn8LterXiGfoX5l70quDnKuY2', 0);

-- Sample event
INSERT INTO events (title, event_date, location, description) VALUES
('Spring Community Fair', '2026-05-15', 'Main Street Community Center', 'Annual spring community fair requiring volunteer staff for various functions.');

-- Functions for the sample event
INSERT INTO event_functions (event_id, function_name, description) VALUES
(1, 'Registration Desk', 'Check in attendees and distribute badges'),
(1, 'Food Service',      'Serve food and beverages to attendees'),
(1, 'Setup & Teardown',  'Set up before and tear down after the event');

-- Time periods for Registration Desk (2 people per period)
INSERT INTO event_periods (function_id, start_time, end_time, max_signups) VALUES
(1, '08:00:00', '10:00:00', 2),
(1, '10:00:00', '12:00:00', 2),
(1, '12:00:00', '14:00:00', 2);

-- Time periods for Food Service (3 people per period)
INSERT INTO event_periods (function_id, start_time, end_time, max_signups) VALUES
(2, '11:00:00', '13:00:00', 3),
(2, '13:00:00', '15:00:00', 3);

-- Time periods for Setup & Teardown (4 people per period)
INSERT INTO event_periods (function_id, start_time, end_time, max_signups) VALUES
(3, '06:00:00', '08:00:00', 4),
(3, '15:00:00', '17:00:00', 4);
