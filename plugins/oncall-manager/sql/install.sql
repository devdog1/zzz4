-- install.sql
CREATE TABLE IF NOT EXISTS plug_oncall_manager_departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    manager_user_id INT DEFAULT NULL,
    noc_mode TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_department_users (
    department_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (department_id, user_id)
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_schedule_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    user_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dept_time (department_id, start_time, end_time)
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    user_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_override_dept_time (department_id, start_time, end_time)
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_trade_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    proposing_user_id INT NOT NULL,
    accepting_user_id INT DEFAULT NULL,
    offered_slot_id INT NOT NULL,
    counter_slot_id INT DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_noc_business_hours (
    day_of_week INT NOT NULL PRIMARY KEY,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_commportal_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    phone_number VARCHAR(50) NOT NULL,
    password VARCHAR(100) NOT NULL,
    ext VARCHAR(20) DEFAULT NULL,
    last_forwarded_phone VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_zabbix_user_map (
    zabbix_userid BIGINT NOT NULL PRIMARY KEY,
    local_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plug_oncall_manager_department_zabbix_groups (
    department_id INT NOT NULL,
    zabbix_usrgrp_id BIGINT NOT NULL,
    last_oncall_userid BIGINT DEFAULT NULL,
    PRIMARY KEY (department_id, zabbix_usrgrp_id)
);
