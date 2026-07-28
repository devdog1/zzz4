-- schema.sql
-- Database initialization for On-Call Schedule System with RBAC, SSO, Audit Logs, and Trading

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

CREATE TABLE IF NOT EXISTS media (
    mediaid BIGINT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    userid BIGINT NOT NULL,
    mediatypeid BIGINT NOT NULL,
    sendto VARCHAR(100) NOT NULL
);

INSERT IGNORE INTO media (userid, mediatypeid, sendto) VALUES
(1, 4, '+1-555-0101'),
(2, 4, '+1-555-0102'),
(3, 4, '+1-555-0103'),
(4, 3, 'pager_address'),
(5, 4, '+1-555-0105');

-- 2. Create On-Call System Database & Schema
CREATE DATABASE IF NOT EXISTS oncall_system;
USE oncall_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zabbix_userid BIGINT UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    surname VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    azure_oid VARCHAR(255) DEFAULT NULL UNIQUE,
    display_name VARCHAR(255) DEFAULT NULL,
    last_login DATETIME DEFAULT NULL,
    auto_provisioned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    manager_user_id INT DEFAULT NULL,
    noc_mode TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
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

-- RBAC Tables for AzureADSSO & Auth.php
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS default_roles (
    role_id INT NOT NULL,
    PRIMARY KEY (role_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS azure_group_roles (
    azure_group_name VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (azure_group_name, role_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS denied_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- Shift Trades Table
CREATE TABLE IF NOT EXISTS trade_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    proposing_user_id INT NOT NULL,
    accepting_user_id INT DEFAULT NULL,
    offered_slot_id INT NOT NULL,
    counter_slot_id INT DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'open', -- 'open', 'offered', 'agreed', 'approved', 'rejected'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (proposing_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (accepting_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (offered_slot_id) REFERENCES schedule_slots(id) ON DELETE CASCADE,
    FOREIGN KEY (counter_slot_id) REFERENCES schedule_slots(id) ON DELETE CASCADE
);

-- Audit Logging Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    username VARCHAR(100) DEFAULT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert Roles & Basic Permissions
INSERT IGNORE INTO roles (id, role_name, description) VALUES
(1, 'admin', 'Global Administrator with full rights'),
(2, 'manager', 'Department manager with schedule management rights'),
(3, 'user', 'Standard user / team member');

INSERT IGNORE INTO permissions (id, permission_name, description) VALUES
(1, 'manage_departments', 'Create or delete departments'),
(2, 'manage_schedules', 'Generate schedules and overrides'),
(3, 'view_schedules', 'View calendar and on-call schedules');

INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES
(1, 1), (1, 2), (1, 3),
(2, 2), (2, 3),
(3, 3);

INSERT IGNORE INTO default_roles (role_id) VALUES (3);

-- Seed Initial Departments
INSERT IGNORE INTO departments (id, name) VALUES
(1, 'Infrastructure'),
(2, 'Development'),
(3, 'Security');

-- Seed NOC User in Users
INSERT IGNORE INTO users (id, zabbix_userid, username, name, surname, email) VALUES
(999, 999, 'noc@example.com', 'NOC', 'Service', 'noc@example.com');

-- Settings Table
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('zabbix_default_domain', 'example.com'),
('zabbix_api_url', 'http://127.0.0.1/zabbix/api_jsonrpc.php'),
('zabbix_api_token', 'mock_zabbix_api_token_value_here'),
('noc_zabbix_userid', '999');

-- NOC Business Hours Table
CREATE TABLE IF NOT EXISTS noc_business_hours (
    day_of_week INT NOT NULL PRIMARY KEY, -- 1 = Monday, 2 = Tuesday, ..., 7 = Sunday
    start_time TIME NOT NULL,
    end_time TIME NOT NULL
);

INSERT IGNORE INTO noc_business_hours (day_of_week, start_time, end_time) VALUES
(1, '08:00:00', '18:00:00'),
(2, '08:00:00', '18:00:00'),
(3, '08:00:00', '18:00:00'),
(4, '08:00:00', '18:00:00'),
(5, '08:00:00', '18:00:00'),
(6, '08:00:00', '18:00:00'),
(7, '08:00:00', '18:00:00');

-- Department Zabbix Groups Table
CREATE TABLE IF NOT EXISTS department_zabbix_groups (
    department_id INT NOT NULL,
    zabbix_usrgrp_id BIGINT NOT NULL,
    last_oncall_userid BIGINT DEFAULT NULL,
    PRIMARY KEY (department_id, zabbix_usrgrp_id),
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);
