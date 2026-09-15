<?php
// logout.php - Clear Sessions
require_once __DIR__ . '/functions.php';

get_auth()->logout();
header("Location: login.php");
exit;
