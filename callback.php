<?php
// callback.php - Azure AD SSO Callback handler
require_once __DIR__ . '/functions.php';

try {
    if (get_auth()->handleCallback()) {
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
