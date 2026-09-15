<?php
/*
Plugin Name: Sample Manager
Description: A sample plugin showing how to register custom settings, custom route, navigation tab, background tasks, widgets, dynamic table creation, custom Roles, and shared inter-plugin services inside the Base Framework.
Version: 1.6.0
Author: Framework Developers
Permissions: manage_sample_settings, view_sample_stats
Roles: analyst:view_sample_stats; specialist:manage_sample_settings,view_sample_stats
*/

// Prevent direct access
if (!class_exists('PluginManager')) {
    exit;
}

// 1. Navigation Registration Filter
PluginManager::getInstance()->addFilter('theme_nav_links', function ($links) {
    $links[] = [
        'route' => 'sample_manager_dashboard',
        'label' => 'Sample Manager',
        'icon'  => 'fa-solid fa-wand-magic-sparkles',
        'permission' => 'sample_manager_view_sample_stats'
    ];
    return $links;
});

// 2. Route Registration (Includes separate view template)
PluginManager::getInstance()->registerRoute('sample_manager_dashboard', function () {
    require_once __DIR__ . '/views/dashboard-view.php';
});

// 3. Footer Script Hook
PluginManager::getInstance()->addAction('theme_footer', function() {
    echo "<!-- Sample Manager Plugin Loaded Successfully -->";
});

// 4. Background Task Registration (Includes separate task script)
require_once __DIR__ . '/tasks/cleanup-task.php';
require_once __DIR__ . '/../../Scheduler.php';

Scheduler::getInstance()->registerTask(
    'sample_cleanup_task',
    'sample_manager_run_cleanup_task',
    300, // 5 minutes
    'sample-manager'
);

// 5. Inter-Plugin Service API
PluginManager::getInstance()->registerService(
    'sample_fetch_system_status',
    function($arg1) {
        return [
            'status' => 'online',
            'token_configured' => get_setting('sample_manager_api_token') ? true : false,
            'argument_passed' => $arg1,
            'queried_at' => date('Y-m-d H:i:s')
        ];
    },
    'sample-manager'
);

// 6. User-Context Dashboard Widget
PluginManager::getInstance()->addAction('index_dashboard_widgets', function($userContext) {
    $roles_str = implode(', ', array_map('ucfirst', $userContext['roles'] ?? []));
    ?>
    <div class="col-12 col-md-6 col-lg-4 widget-block" data-widget-key="sample_user_context_widget" data-widget-title="User Context Widget">
        <div class="card bg-gradient shadow-sm border-start border-5 border-info text-start">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="bg-light rounded-circle p-2 text-center me-3" style="width: 45px; height: 45px;">
                        <i class="fa-solid fa-id-card-clip text-info fs-4"></i>
                    </div>
                    <div>
                        <h6 class="card-title fw-bold mb-0 text-dark">User Context Widget</h6>
                        <small class="text-muted">Registered by Sample Plugin</small>
                    </div>
                </div>
                <hr class="my-2">
                <p class="card-text small mb-1"><strong>Hello,</strong> <?= htmlspecialchars($userContext['display_name']) ?>!</p>
                <p class="card-text small mb-1"><strong>Active Privilege Roles:</strong> <code class="text-secondary"><?= htmlspecialchars($roles_str) ?></code></p>
                <p class="card-text small mb-0"><strong>Your Login ID:</strong> <code><?= htmlspecialchars($userContext['username']) ?></code></p>
            </div>
        </div>
    </div>
    <?php
});

// 7. Lifecycle Activation / Deactivation Hooks
require_once __DIR__ . '/../../PluginDatabase.php';

PluginManager::getInstance()->addAction('activate_plugin_sample-manager', function() {
    $pdb = new PluginDatabase('sample-manager');
    $pdb->createTable('logs', "
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_message VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ");
    log_action('SAMPLE_PLUGIN_ACTIVATE_DB_SUCCESS', []);
});

PluginManager::getInstance()->addAction('deactivate_plugin_sample-manager', function($purge_tables = false) {
    if (!$purge_tables) {
        return;
    }
    $pdb = new PluginDatabase('sample-manager');
    $pdb->dropTable('logs');
    log_action('SAMPLE_PLUGIN_DEACTIVATE_DB_SUCCESS', []);
});
