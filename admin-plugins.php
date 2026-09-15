<?php
// admin-plugins.php - Dedicated Module Discovery, Enablement, Cron Tasks, and Verbose Compatibility Inspector
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Scheduler.php';

// Enforce permission checks
if (!has_permission('manage_plugins')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. You do not have permission to manage portal modules.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$scheduler = Scheduler::getInstance();
$msg = '';
$err = '';
$inspection_report = null;

// Handle Activation, Deactivation, Manual Cron triggers, and Compatibility Inspection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'activate') {
        $slug = $_POST['plugin_slug'] ?? '';
        if ($pluginManager->activatePlugin($slug)) {
            $msg = "Module <strong>" . htmlspecialchars($slug) . "</strong> has been successfully activated!";
            log_action('ACTIVATE_PLUGIN', ['slug' => $slug]);
        } else {
            global $err;
            $err = $err ?: "Failed to activate module <strong>" . htmlspecialchars($slug) . "</strong>.";
        }
    } elseif ($action === 'deactivate') {
        $slug = $_POST['plugin_slug'] ?? '';
        $confirm_name = trim($_POST['confirm_name'] ?? '');
        $purge_tables = !empty($_POST['purge_tables']) ? true : false;

        $discovered = $pluginManager->discoverPlugins();
        $expected_name = $discovered[$slug]['name'] ?? $slug;

        if (strcasecmp($confirm_name, $expected_name) !== 0 && strcasecmp($confirm_name, $slug) !== 0) {
            $err = "Deactivation Canceled: Submitted confirmation name '<strong>" . htmlspecialchars($confirm_name) . "</strong>' does not match plugin name '<strong>" . htmlspecialchars($expected_name) . "</strong>'.";
        } else {
            if ($pluginManager->deactivatePlugin($slug, $purge_tables)) {
                $msg = "Module <strong>" . htmlspecialchars($slug) . "</strong> has been deactivated! " . ($purge_tables ? "(Database tables purged)" : "(Database tables retained)");
                log_action('DEACTIVATE_PLUGIN', ['slug' => $slug, 'purge_tables' => $purge_tables]);
            } else {
                $err = "Failed to deactivate module <strong>" . htmlspecialchars($slug) . "</strong>.";
            }
        }
    } elseif ($action === 'inspect') {
        $slug = $_POST['plugin_slug'] ?? '';
        $inspection_report = $pluginManager->inspectPlugin($slug);
        log_action('INSPECT_PLUGIN', ['slug' => $slug, 'compatible' => $inspection_report['compatible']]);
    } elseif ($action === 'trigger_cron') {
        try {
            $scheduler->runPendingTasks();
            $msg = "Task Scheduler triggered successfully! Pending tasks processed safely.";
            log_action('SCHEDULER_MANUAL_TRIGGER', []);
        } catch (Exception $e) {
            $err = "Scheduler Trigger Error: " . $e->getMessage();
        }
    }
}

// Fetch discovered plugins, errors, and task statuses
$plugins = $pluginManager->discoverPlugins();
$activeCount = count($pluginManager->getActivePlugins());
$failed_tasks_by_plugin = $scheduler->getFailedTasksByPlugin();
$boot_errors = $pluginManager->getPluginErrors();

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-puzzle-piece text-info me-2"></i>Module & Extension Manager</h1>
        <p class="text-muted">Discover new pluggable features, verify plugin compatibility with full diagnostic reports, toggle dynamic extensions, and monitor background crons.</p>
    </div>
</div>

<?php if (!empty($boot_errors) || !empty($failed_tasks_by_plugin)): ?>
    <div class="alert alert-danger shadow-sm mb-4 text-start" role="alert">
        <h5 class="alert-heading h6 fw-bold mb-2">
            <i class="fa-solid fa-shield-halved me-2"></i>Plugin Safeguard Alert: Isolated Errors Detected
        </h5>
        <p class="mb-1 small">The platform isolated errors in the following plugin(s) to prevent impacting core system functions or other plugins:</p>
        <ul class="mb-0 small ps-3">
            <?php foreach ($boot_errors as $slug => $err_text): ?>
                <li><strong>Module '<?= htmlspecialchars($slug) ?>' Boot Error:</strong> <?= htmlspecialchars($err_text) ?></li>
            <?php endforeach; ?>
            <?php foreach ($failed_tasks_by_plugin as $slug => $tasks): ?>
                <?php foreach ($tasks as $ft): ?>
                    <li><strong>Module '<?= htmlspecialchars($slug) ?>' Task Failure (<code><?= htmlspecialchars($ft['task_key']) ?></code>):</strong> <?= htmlspecialchars($ft['error_message'] ?: 'Execution crashed') ?></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $err ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Diagnostic Report Section -->
<?php if ($inspection_report): ?>
    <div class="card shadow-sm border-<?= $inspection_report['compatible'] ? 'success' : 'danger' ?> mb-4">
        <div class="card-header bg-<?= $inspection_report['compatible'] ? 'success' : 'danger' ?> text-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0">
                <i class="fa-solid fa-microscope me-2"></i>
                Plugin Compatibility & Test Activation Report: <strong><?= htmlspecialchars($inspection_report['meta']['name'] ?? $inspection_report['slug']) ?></strong>
            </h5>
            <span class="badge bg-light text-<?= $inspection_report['compatible'] ? 'success' : 'danger' ?> fs-6">
                <?= $inspection_report['compatible'] ? '<i class="fa-solid fa-circle-check me-1"></i> Compatible' : '<i class="fa-solid fa-triangle-exclamation me-1"></i> Issues Detected' ?>
            </span>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-7">
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-list-check me-2 text-primary"></i>Diagnostic Checks Performed:</h6>
                    <div class="list-group list-group-flush">
                        <?php foreach ($inspection_report['checks'] as $chk): ?>
                            <div class="list-group-item d-flex align-items-start px-0 py-2">
                                <div class="me-3 mt-1">
                                    <?php if ($chk['status'] === 'pass'): ?>
                                        <i class="fa-solid fa-circle-check text-success fs-5"></i>
                                    <?php elseif ($chk['status'] === 'warn'): ?>
                                        <i class="fa-solid fa-triangle-exclamation text-warning fs-5"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-circle-xmark text-danger fs-5"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong class="d-block text-dark"><?= htmlspecialchars($chk['title']) ?></strong>
                                    <small class="text-muted"><?= htmlspecialchars($chk['message']) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="border rounded p-3 bg-light h-100">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-gears me-2 text-info"></i>Expected Activation Actions & Changes:</h6>
                        <?php if (empty($inspection_report['expected_changes'])): ?>
                            <p class="text-muted small">No database schema, permission, or role changes declared for this plugin.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0 small">
                                <?php foreach ($inspection_report['expected_changes'] as $change): ?>
                                    <li class="mb-2 text-secondary">
                                        <i class="fa-solid fa-arrow-right-long text-primary me-2"></i><?= htmlspecialchars($change) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="mt-4 pt-3 border-top d-flex gap-2">
                            <?php if ($inspection_report['compatible']): ?>
                                <form method="POST" class="m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="plugin_slug" value="<?= htmlspecialchars($inspection_report['slug']) ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="fa-solid fa-bolt me-1"></i>Proceed with Activation
                                    </button>
                                </form>
                            <?php endif; ?>
                            <a href="admin-plugins.php" class="btn btn-sm btn-outline-secondary">Dismiss Report</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Plugins Table List -->
    <div class="col-lg-8">
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-puzzle-piece me-2"></i>Available Extension Modules / Plugins</span>
                <span class="badge bg-info"><?= $activeCount ?> Active / <?= count($plugins) ?> Discovered</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($plugins)): ?>
                    <p class="text-muted p-4 mb-0">No plugin modules discovered in the <code>plugins/</code> directory yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Module Info</th>
                                    <th>Slug</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plugins as $slug => $meta):
                                    $has_error = isset($boot_errors[$slug]) || isset($failed_tasks_by_plugin[$slug]);
                                ?>
                                    <tr>
                                        <td>
                                            <h5 class="h6 mb-0 fw-bold text-primary"><?= htmlspecialchars($meta['name']) ?></h5>
                                            <p class="text-muted small mb-1"><?= htmlspecialchars($meta['description']) ?></p>
                                            <small class="text-muted">Version: <?= htmlspecialchars($meta['version']) ?> | Author: <?= htmlspecialchars($meta['author']) ?></small>
                                            <?php if (!empty($meta['permissions'])): ?>
                                                <div class="mt-1"><span class="small text-secondary"><strong>Permissions Provided:</strong> <code><?= htmlspecialchars($meta['permissions']) ?></code></span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($slug) ?></code></td>
                                        <td>
                                            <?php if ($meta['active']): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i>Active</span>
                                                <?php if ($has_error): ?>
                                                    <span class="badge bg-danger ms-1" title="Safeguard Warning: Faulty behavior or task crash isolated for this plugin."><i class="fa-solid fa-triangle-exclamation me-1"></i>Error Isolated</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="m-0 d-inline-block">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="plugin_slug" value="<?= htmlspecialchars($slug) ?>">
                                                <input type="hidden" name="action" value="inspect">
                                                <button type="submit" class="btn btn-sm btn-outline-info me-1" title="Run Verbose Compatibility Inspector">
                                                    <i class="fa-solid fa-stethoscope me-1"></i>Check Compatibility
                                                </button>
                                            </form>

                                            <?php if ($meta['active']): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deactivateModal_<?= md5($slug) ?>">
                                                    <i class="fa-solid fa-power-off me-1"></i>Deactivate
                                                </button>

                                                <!-- Deactivation Confirmation & Purge Selection Modal -->
                                                <div class="modal fade text-start" id="deactivateModal_<?= md5($slug) ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-md">
                                                        <div class="modal-content">
                                                            <div class="modal-header bg-danger text-white">
                                                                <h5 class="modal-title h6 fw-bold"><i class="fa-solid fa-power-off me-2"></i>Deactivate Plugin: <?= htmlspecialchars($meta['name']) ?></h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST">
                                                                <?php csrf_field(); ?>
                                                                <input type="hidden" name="action" value="deactivate">
                                                                <input type="hidden" name="plugin_slug" value="<?= htmlspecialchars($slug) ?>">

                                                                <div class="modal-body">
                                                                    <h6 class="fw-bold text-dark mb-2">1. Database Tables Handling</h6>
                                                                    <div class="form-check mb-2 border rounded p-2 ps-4 bg-light">
                                                                        <input class="form-check-input" type="radio" name="purge_tables" id="keep_db_<?= md5($slug) ?>" value="0" checked>
                                                                        <label class="form-check-label fw-bold text-success" for="keep_db_<?= md5($slug) ?>">
                                                                            <i class="fa-solid fa-database me-1"></i> Keep Database Tables (Recommended)
                                                                        </label>
                                                                        <div class="form-text text-muted small">Retain plugin database tables so your data is preserved if reactivated later.</div>
                                                                    </div>

                                                                    <div class="form-check mb-3 border rounded p-2 ps-4 bg-light">
                                                                        <input class="form-check-input" type="radio" name="purge_tables" id="purge_db_<?= md5($slug) ?>" value="1">
                                                                        <label class="form-check-label fw-bold text-danger" for="purge_db_<?= md5($slug) ?>">
                                                                            <i class="fa-solid fa-trash-can me-1"></i> Purge & Drop Database Tables
                                                                        </label>
                                                                        <div class="form-text text-muted small">Completely drop all database tables created by this plugin. <strong class="text-danger">Warning: This action cannot be undone.</strong></div>
                                                                    </div>

                                                                    <hr class="my-3">

                                                                    <h6 class="fw-bold text-dark mb-2">2. Confirmation Required</h6>
                                                                    <p class="small text-muted mb-2">Type the exact plugin name <code><?= htmlspecialchars($meta['name']) ?></code> to confirm deactivation:</p>
                                                                    <input type="text" name="confirm_name" class="form-control form-control-sm" placeholder="Type '<?= htmlspecialchars($meta['name']) ?>' here" required autocomplete="off">
                                                                </div>

                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                                        <i class="fa-solid fa-power-off me-1"></i>Confirm Deactivation
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <form method="POST" class="m-0 d-inline-block">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="plugin_slug" value="<?= htmlspecialchars($slug) ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                                        <i class="fa-solid fa-bolt me-1"></i>Activate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Sidebar Quick Info -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-info mb-4">
            <div class="card-header bg-info text-dark">
                <i class="fa-solid fa-circle-info me-2"></i>Extension Info & Testing
            </div>
            <div class="card-body small">
                <h6>Deactivation Options:</h6>
                <p class="text-muted mb-3">When deactivating a plugin, you can choose to <strong>Keep Database Tables</strong> or <strong>Purge Database Tables</strong>, and you must type the plugin name to confirm.</p>
                <h6>Plugin Safeguards:</h6>
                <p class="text-muted mb-3">The framework wraps each plugin's booting, routes, and background tasks in isolated try-catch shields, ensuring bad plugins never block other plugins or crash the system.</p>
                <h6>Verbose Compatibility Checker:</h6>
                <p class="text-muted mb-3">Click <strong>Check Compatibility</strong> on any plugin to run a dry-run diagnostic test verifying syntax, permission namespaces, role collisions, and database SQL scripts before activation.</p>
                <h6>Database Prefix Sandbox:</h6>
                <p class="text-muted mb-0">Plugins operate inside segregated database contexts using the prefix format <code>plug_{slug}_</code> to guarantee other modules remain uncompromised.</p>
            </div>
        </div>
    </div>
</div>

<!-- Task Scheduler Dashboard -->
<div class="row">
    <div class="col-lg-12">
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i>Background Task Scheduler (Sandboxed)</span>
                <form method="POST" class="m-0">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="trigger_cron">
                    <button type="submit" class="btn btn-xs btn-outline-warning text-white border-white btn-sm">
                        <i class="fa-solid fa-arrows-spin me-1"></i>Trigger Cron Check
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php $states = $scheduler->getTaskExecutionStates(); ?>
                <?php if (empty($states)): ?>
                    <p class="text-muted p-4 mb-0"><i class="fa-solid fa-circle-info me-1 text-primary"></i>No background tasks have been logged by active plugins yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Task Identifier</th>
                                    <th>Plugin Source</th>
                                    <th>Frequency</th>
                                    <th>Last Execution</th>
                                    <th>Next Run</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($states as $task): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($task['task_key']) ?></code></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($task['plugin_slug']) ?></span></td>
                                        <td><?= htmlspecialchars($task['interval_seconds']) ?>s</td>
                                        <td><?= htmlspecialchars($task['last_run']) ?></td>
                                        <td><?= htmlspecialchars($task['next_run']) ?></td>
                                        <td>
                                            <?php if ($task['status'] === 'success'): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Success</span>
                                            <?php elseif ($task['status'] === 'failed'): ?>
                                                <span class="badge bg-danger" title="<?= htmlspecialchars($task['error_message']) ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i>Failed</span>
                                            <?php else: ?>
                                                <span class="badge bg-info"><?= htmlspecialchars($task['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
