<?php
// logout.php - Log out of session
require_once 'Auth.php';
require_once 'models.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

log_action('LOGOUT', [
    'user_id' => $_SESSION['user_id'] ?? null
]);

$config = require 'config.php';
$auth = new Auth($config);
$auth->logout();

header("Location: login.php");
exit;
