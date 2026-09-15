-- schema.sql
-- Base Framework Database Initialization with RBAC, SSO, Settings, Audit Logs, and Plugin activation tracking.

CREATE DATABASE IF NOT EXISTS base_framework;
USE base_framework;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    azure_oid VARCHAR(255) DEFAULT NULL UNIQUE,
    display_name VARCHAR(255) DEFAULT NULL,
    last_login DATETIME DEFAULT NULL,
    auto_provisioned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. RBAC Tables
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed Initial Roles & Basic Permissions
INSERT IGNORE INTO roles (id, role_name, description) VALUES
(1, 'admin', 'Global Administrator with full rights'),
(2, 'manager', 'Manager with limited administrative rights'),
(3, 'user', 'Standard user');

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

-- 3. Settings Table
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 4. Audit Logging Table
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

-- 5. Plugins Activation Table
CREATE TABLE IF NOT EXISTS active_plugins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plugin_slug VARCHAR(100) NOT NULL UNIQUE,
    activated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. Task Scheduler Tracking Table with Dynamic Overrides and Enablement
CREATE TABLE IF NOT EXISTS scheduled_tasks (
    task_key VARCHAR(150) NOT NULL PRIMARY KEY,
    plugin_slug VARCHAR(100) NOT NULL,
    interval_seconds INT NOT NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    custom_interval_seconds INT DEFAULT NULL,
    fixed_day_of_week INT DEFAULT NULL, -- 1=Monday, ..., 7=Sunday
    fixed_time_of_day TIME DEFAULT NULL, -- HH:MM:SS
    last_run DATETIME DEFAULT NULL,
    next_run DATETIME DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'idle',
    error_message TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 7. Task Scheduler Historical Execution Logs Table with duration column
CREATE TABLE IF NOT EXISTS scheduled_tasks_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_key VARCHAR(150) NOT NULL,
    run_started DATETIME NOT NULL,
    run_ended DATETIME DEFAULT NULL,
    status VARCHAR(50) NOT NULL, -- 'running', 'success', 'failed'
    duration_seconds DECIMAL(10, 4) DEFAULT 0.0000,
    error_message TEXT DEFAULT NULL,
    FOREIGN KEY (task_key) REFERENCES scheduled_tasks(task_key) ON DELETE CASCADE
);

-- 8. User Dashboard Widget Preferences Table
CREATE TABLE IF NOT EXISTS user_widget_preferences (
    user_id INT NOT NULL,
    widget_key VARCHAR(150) NOT NULL,
    is_visible TINYINT(1) DEFAULT 1,
    width_class VARCHAR(50) DEFAULT 'col-12', -- col-12, col-lg-8, col-lg-6, col-lg-4
    sort_order INT DEFAULT 100,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, widget_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT IGNORE INTO permissions (id, permission_name, description) VALUES
(1, 'manage_settings', 'Modify system and plugin settings'),
(2, 'manage_plugins', 'Activate/Deactivate plugins'),
(3, 'view_dashboard', 'Access the generic dashboard');

INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES
(1, 1), (1, 2), (1, 3),
(2, 3),
(3, 3);

INSERT IGNORE INTO default_roles (role_id) VALUES (3);

-- Seed Initial Settings
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('site_name', 'Framework Portal'),
('azure_default_domain', 'example.com');
