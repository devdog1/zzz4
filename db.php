<?php
// db.php - Database connection helpers

function get_db_connection() {
    $config = require __DIR__ . '/config.php';
    $host = $config['db']['local']['dbhost'] ?? '127.0.0.1';
    $dbname = $config['db']['local']['dbname'] ?? 'base_framework';
    $user = $config['db']['local']['dbuser'] ?? 'framework_user';
    $pass = $config['db']['local']['dbpass'] ?? 'framework_pass';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);

        // Auto-migration check: Ensure roles table contains is_active column
        static $roles_schema_checked = false;
        if (!$roles_schema_checked) {
            $roles_schema_checked = true;
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM roles LIKE 'is_active'")->fetchAll();
                if (empty($cols)) {
                    $pdo->exec("ALTER TABLE roles ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER description");
                }
            } catch (Exception $e) {
                // Ignore schema migration exceptions if table does not yet exist
            }
        }

        return $pdo;
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}
