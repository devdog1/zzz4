<?php
// cleanup-task.php - Background cleanup task for Sample Manager plugin

if (!defined('ABSPATH') && !class_exists('PluginManager')) {
    exit;
}

function sample_manager_run_cleanup_task() {
    log_action('SAMPLE_CLEANUP_CRON', ['status' => 'executed_cleanly']);
}
