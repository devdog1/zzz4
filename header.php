<?php
// header.php - Beautiful themed layout with Bootstrap 5 and Extensible Plugin Navigation Hooks
require_once __DIR__ . '/functions.php';

$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'login.php' && $current_page !== 'callback.php') {
    require_login();
}

$user_display_name = $_SESSION['user']['name'] ?? 'User';
$user_roles = isset($_SESSION['roles']) ? array_keys($_SESSION['roles']) : [];
$user_roles_str = implode(', ', array_map('ucfirst', $user_roles));
$site_name = get_setting('site_name', 'Framework Portal');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">

    <!-- Action Hook for custom plugin styles / scripts inside <head> -->
    <?php $pluginManager->doAction('theme_head'); ?>

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
        /* Widget Resizing Rules: ensure plugin widgets stretch to fill 100% width of resized grid container */
        .widget-item {
            display: flex;
            flex-direction: column;
        }
        .widget-inner-content,
        .widget-inner-content > div,
        .widget-inner-content .card,
        .widget-inner-content .widget-block {
            width: 100% !important;
            max-width: 100% !important;
            flex: 1 1 auto;
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #f2f2f2;
            font-weight: 600;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="fa-solid fa-cubes me-2 text-info"></i><?= htmlspecialchars($site_name) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <!-- Left Side Navbar Menu -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'index.php' && !isset($_GET['route'])) ? 'active' : '' ?>" href="index.php">
                        <i class="fa-solid fa-house me-1"></i> Dashboard
                    </a>
                </li>

                <!-- Filter Hook for plugins to add navigation links dynamically with admin reorder/visibility overrides -->
                <?php
                $raw_nav_links = [];
                $raw_nav_links = $pluginManager->applyFilters('theme_nav_links', $raw_nav_links);

                $nav_config_json = get_setting('nav_menu_config', '{}');
                $nav_config = json_decode($nav_config_json, true) ?: [];

                $processed_nav_links = [];
                $default_order = 100;

                foreach ($raw_nav_links as $link) {
                    $item_id = !empty($link['route']) ? $link['route'] : preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($link['label']));
                    $cfg = $nav_config[$item_id] ?? [];

                    // 1. Visibility Check (default: 1 = visible)
                    if (isset($cfg['visible']) && (int)$cfg['visible'] === 0) {
                        continue;
                    }

                    // 2. Custom Label Override
                    if (!empty($cfg['label'])) {
                        $link['label'] = $cfg['label'];
                    }

                    // 3. Custom Order Override
                    $link['_order'] = isset($cfg['order']) ? (int)$cfg['order'] : $default_order;
                    $default_order += 10;

                    $processed_nav_links[] = $link;
                }

                // Sort navigation items by order ASC
                usort($processed_nav_links, function($a, $b) {
                    return ($a['_order'] ?? 100) <=> ($b['_order'] ?? 100);
                });

                foreach ($processed_nav_links as $link) {
                    // Check top-level permission constraint
                    if (isset($link['permission']) && !has_permission($link['permission'])) {
                        continue;
                    }

                    if (!empty($link['children']) && is_array($link['children'])) {
                        // Render nested Bootstrap 5 Dropdown sub-menu
                        echo '<li class="nav-item dropdown">';
                        echo '<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">';
                        if (isset($link['icon'])) {
                            echo '<i class="' . htmlspecialchars($link['icon']) . ' me-1"></i> ';
                        }
                        echo htmlspecialchars($link['label']);
                        echo '</a>';
                        echo '<ul class="dropdown-menu">';

                        foreach ($link['children'] as $child) {
                            if (isset($child['permission']) && !has_permission($child['permission'])) {
                                continue;
                            }
                            echo '<li>';
                            echo '<a class="dropdown-item" href="index.php?route=' . urlencode($child['route']) . '">';
                            if (isset($child['icon'])) {
                                echo '<i class="' . htmlspecialchars($child['icon']) . ' me-2 text-secondary"></i> ';
                            }
                            echo htmlspecialchars($child['label']);
                            echo '</a>';
                            echo '</li>';
                        }

                        echo '</ul>';
                        echo '</li>';
                    } else {
                        // Render standard Top-Level navigation node
                        $active_class = (isset($_GET['route']) && $_GET['route'] === $link['route']) ? 'active' : '';
                        echo '<li class="nav-item">';
                        echo '<a class="nav-link ' . $active_class . '" href="index.php?route=' . urlencode($link['route']) . '">';
                        if (isset($link['icon'])) {
                            echo '<i class="' . htmlspecialchars($link['icon']) . ' me-1"></i> ';
                        }
                        echo htmlspecialchars($link['label']);
                        echo '</a>';
                        echo '</li>';
                    }
                }
                ?>
            </ul>

            <!-- Right Side Navbar Menu (Consolidating Core Admin links to the right dropdown) -->
            <ul class="navbar-nav ms-auto align-items-center me-3">
                <?php if (has_permission('manage_plugins') || has_permission('manage_settings')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-screwdriver-wrench me-1 text-warning"></i> Administration
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if (has_permission('manage_plugins')): ?>
                                <li>
                                    <a class="dropdown-item <?= ($current_page === 'admin-plugins.php') ? 'active' : '' ?>" href="admin-plugins.php">
                                        <i class="fa-solid fa-puzzle-piece me-2 text-primary"></i> Modules & Plugins
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= ($current_page === 'admin-scheduler.php') ? 'active' : '' ?>" href="admin-scheduler.php">
                                        <i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i> Task Scheduler
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if (has_permission('manage_settings')): ?>
                                <li>
                                    <a class="dropdown-item <?= ($current_page === 'admin-nav.php') ? 'active' : '' ?>" href="admin-nav.php">
                                        <i class="fa-solid fa-bars-staggered me-2 text-info"></i> Navigation Manager
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= ($current_page === 'admin-users.php') ? 'active' : '' ?>" href="admin-users.php">
                                        <i class="fa-solid fa-users-gear me-2 text-success"></i> Users & RBAC
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= ($current_page === 'admin-roles.php') ? 'active' : '' ?>" href="admin-roles.php">
                                        <i class="fa-solid fa-user-shield me-2 text-warning"></i> Roles & Permissions
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= ($current_page === 'admin-azure-groups.php') ? 'active' : '' ?>" href="admin-azure-groups.php">
                                        <i class="fa-brands fa-microsoft me-2 text-info"></i> Azure AD Groups
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= ($current_page === 'admin-logs.php') ? 'active' : '' ?>" href="admin-logs.php">
                                        <i class="fa-solid fa-receipt me-2 text-primary"></i> Audit Trail Logs
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= ($current_page === 'admin-diagnostics.php') ? 'active' : '' ?>" href="admin-diagnostics.php">
                                        <i class="fa-solid fa-stethoscope me-2 text-danger"></i> System Diagnostics
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>

            <!-- User Session profile & logout -->
            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="d-flex align-items-center text-white">
                    <div class="me-3 text-end">
                        <div class="fw-bold small"><?= htmlspecialchars($user_display_name) ?></div>
                        <div class="text-muted small" style="font-size: 0.75rem;"><?= htmlspecialchars($user_roles_str) ?></div>
                    </div>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">
                        <i class="fa-solid fa-right-from-bracket me-1"></i>Logout
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container pb-5">

<?php
// Render Site-Wide Broadcast Announcement Banner if enabled
if (get_setting('broadcast_banner_enabled', '0') === '1') {
    $banner_msg = get_setting('broadcast_banner_message', '');
    $banner_type = htmlspecialchars(get_setting('broadcast_banner_type', 'info'));
    if (!empty($banner_msg)) {
        echo '<div class="alert alert-' . $banner_type . ' alert-dismissible fade show mb-4 text-start shadow-sm fw-semibold" role="alert">';
        echo '<i class="fa-solid fa-bullhorn me-2"></i>' . $banner_msg;
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}
?>
