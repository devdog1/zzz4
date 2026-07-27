<?php
// callback.php - Azure AD SSO Callback handler
require_once 'Auth.php';

$config = require 'config.php';
$auth = new Auth($config);

try {
    if ($auth->handleCallback()) {
        header("Location: index.php");
        exit;
    } else {
        header("Location: login.php?error=callback_failed");
        exit;
    }
} catch (Exception $e) {
    header("Location: login.php?error=" . urlencode($e->getMessage()));
    exit;
}
