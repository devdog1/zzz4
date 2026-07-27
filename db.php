<?php
// db.php - Database connection helpers

function get_db_connection($dbname) {
    $config = require 'config.php';
    $host = $config['db']['local']['dbhost'] ?? '127.0.0.1';
    $user = $config['db']['local']['dbuser'] ?? 'oncall_user';
    $pass = $config['db']['local']['dbpass'] ?? 'oncall_pass';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}

function get_oncall_db() {
    return get_db_connection('oncall_system');
}

function get_zabbix_db() {
    return get_db_connection('zabbix');
}
