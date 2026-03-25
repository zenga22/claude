-- Sample schema for cross_db_lookup.php
-- Run this to set up the two databases with test data.

CREATE DATABASE IF NOT EXISTS db1;
CREATE DATABASE IF NOT EXISTS db2;

-- ── db1 ─────────────────────────────────────────────────────────────────────

USE db1;

CREATE TABLE IF NOT EXISTS table1 (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    email_address VARCHAR(255) NOT NULL,
    name          VARCHAR(255) NOT NULL,
    user_name     VARCHAR(255) NOT NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1
);

-- Sample rows (active = 1 matches the default config query)
INSERT INTO table1 (email_address, name, user_name, active) VALUES
    ('alice@example.com',   'Alice Smith',   'asmith',   1),
    ('bob@example.com',     'Bob Jones',     'bjones',   1),
    ('charlie@example.com', 'Charlie Brown', 'cbrown',   1);

-- ── db2 ─────────────────────────────────────────────────────────────────────

USE db2;

CREATE TABLE IF NOT EXISTS table2 (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    email_address VARCHAR(255) NOT NULL UNIQUE,
    name          VARCHAR(255) NOT NULL,
    user_name     VARCHAR(255) NOT NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 0
);

-- Pre-existing row so the "found" path is exercised
INSERT INTO table2 (email_address, name, user_name, active) VALUES
    ('alice@example.com', 'Alice Smith', 'asmith', 0);

CREATE TABLE IF NOT EXISTS table3 (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    userID  INT NOT NULL,
    groupID INT NOT NULL
);

CREATE TABLE IF NOT EXISTS table4 (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    userID  INT NOT NULL,
    role    VARCHAR(50)  NOT NULL DEFAULT 'member',
    status  VARCHAR(50)  NOT NULL DEFAULT 'active'
);
