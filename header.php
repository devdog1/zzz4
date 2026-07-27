<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'models.php';

// Redirect to login if not logged in (unless on login.php or callback.php)
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'login.php' && $current_page !== 'callback.php') {
    require_login();
}

$user_display_name = $_SESSION['user']['name'] ?? 'User';
$user_roles = isset($_SESSION['roles']) ? array_keys($_SESSION['roles']) : [];
$user_roles_str = implode(', ', array_map('ucfirst', $user_roles));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>On-Call Schedule Manager</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <!-- FullCalendar CSS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #f2f2f2;
            font-weight: 600;
        }
        .oncall-active {
            border-left: 5px solid #28a745;
        }
        .oncall-override {
            border-left: 5px solid #ffc107;
        }
        .fc-event {
            cursor: pointer;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="fa-solid fa-clock-rotate-left me-2"></i>On-Call Manager
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php"><i class="fa-solid fa-house me-1"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="calendar.php"><i class="fa-solid fa-calendar-days me-1"></i> Calendar</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="trades.php"><i class="fa-solid fa-right-left me-1"></i> Shift Trades</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="overrides.php"><i class="fa-solid fa-circle-exclamation me-1"></i> Overrides</a>
                </li>
                <?php if (is_admin()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="departments.php"><i class="fa-solid fa-sitemap me-1"></i> Departments</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sync.php"><i class="fa-solid fa-users-gear me-1"></i> Sync & Users</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logs.php"><i class="fa-solid fa-receipt me-1"></i> Audit Trail</a>
                    </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="generate.php"><i class="fa-solid fa-arrows-spin me-1"></i> Generate Rotation</a>
                </li>
            </ul>

            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="d-flex align-items-center text-white">
                    <div class="me-3 text-end">
                        <div class="fw-bold small"><?= htmlspecialchars($user_display_name) ?></div>
                        <div class="text-muted small" style="font-size: 0.75rem;"><?= htmlspecialchars($user_roles_str) ?></div>
                    </div>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-right-from-bracket me-1"></i>Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container pb-5">
