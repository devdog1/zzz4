-- install.sql
CREATE TABLE IF NOT EXISTS plug_sample_manager_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    log_message VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
