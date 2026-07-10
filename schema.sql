-- schema.sql
-- Database initialization for On-Call Schedule System

-- 1. Create Zabbix Database & Mock Data
CREATE DATABASE IF NOT EXISTS zabbix;
USE zabbix;

CREATE TABLE IF NOT EXISTS users (
    userid BIGINT NOT NULL PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    surname VARCHAR(100) NOT NULL
);

INSERT IGNORE INTO users (userid, username, name, surname) VALUES
(1, 'alice', 'Alice', 'Smith'),
(2, 'bob', 'Bob', 'Jones'),
(3, 'charlie', 'Charlie', 'Brown'),
(4, 'david', 'David', 'Miller'),
(5, 'eve', 'Eve', 'Johnson'),
(6, 'frank', 'Frank', 'Wright'),
(7, 'grace', 'Grace', 'Davis');

-- 2. Create On-Call System Database & Schema
CREATE DATABASE IF NOT EXISTS oncall_system;
USE oncall_system;

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zabbix_userid BIGINT UNIQUE,
    username VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    surname VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS department_users (
    department_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (department_id, user_id),
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS schedule_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    user_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_dept_time (department_id, start_time, end_time)
);

CREATE TABLE IF NOT EXISTS overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    user_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_override_dept_time (department_id, start_time, end_time)
);

INSERT IGNORE INTO departments (id, name) VALUES
(1, 'Infrastructure'),
(2, 'Development'),
(3, 'Security');
