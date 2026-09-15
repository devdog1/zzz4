<?php
// admin-scheduler.php - Standalone Task Scheduler Overrides, Enablement, On-Demand Run, and Execution Logs Interface
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Scheduler.php';

// Enforce admin permission
if (!has_permission('manage_plugins')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$db = get_db_connection();
$scheduler = Scheduler::getInstance();
$msg = '';
$err = '';
$on_demand_result = null;

// Handle configuration updates, manual cron triggers, and on-demand execution
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_task') {
            $task_key = $_POST['task_key'] ?? '';
            $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
            $custom_interval = !empty($_POST['custom_interval_seconds']) ? (int)$_POST['custom_interval_seconds'] : null;

            $fixed_day = $_POST['fixed_day_of_week'] !== '' ? (int)$_POST['fixed_day_of_week'] : null;
            $fixed_time = $_POST['fixed_time_of_day'] !== '' ? $_POST['fixed_time_of_day'] : null;

            $stmt = $db->prepare("
                UPDATE scheduled_tasks
                SET is_enabled = ?,
                    custom_interval_seconds = ?,
                    fixed_day_of_week = ?,
                    fixed_time_of_day = ?,
                    next_run = NULL
                WHERE task_key = ?
            ");
            $stmt->execute([$is_enabled, $custom_interval, $fixed_day, $fixed_time, $task_key]);

            $msg = "Scheduler overrides for task <code>" . htmlspecialchars($task_key) . "</code> updated successfully!";
            log_action('SCHEDULER_TASK_OVERRIDE_SAVE', ['task' => $task_key]);
        } elseif ($action === 'run_on_demand') {
            $task_key = $_POST['task_key'] ?? '';
            $on_demand_result = $scheduler->runTaskOnDemand($task_key);
            $msg = "Task <code>" . htmlspecialchars($task_key) . "</code> executed on demand in " . $on_demand_result['duration'] . "s!";
            log_action('SCHEDULER_RUN_ON_DEMAND', ['task' => $task_key, 'duration' => $on_demand_result['duration']]);
        } elseif ($action === 'trigger_cron') {
            $scheduler->runPendingTasks();
            $msg = "Background cron check forced successfully!";
            log_action('SCHEDULER_MANUAL_TRIGGER', []);
        }
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// Fetch registered tasks from active modules and merge with DB states
$registered_tasks = $scheduler->getRegisteredTasks();
$db_states_raw = $scheduler->getTaskExecutionStates();
$db_states = [];
foreach ($db_states_raw as $s) {
    $db_states[$s['task_key']] = $s;
}

// Merge registered active tasks with DB states
$merged_tasks = [];
foreach ($registered_tasks as $scoped_key => $task_info) {
    $db_row = $db_states[$scoped_key] ?? [
        'task_key' => $scoped_key,
        'plugin_slug' => $task_info['plugin'],
        'interval_seconds' => $task_info['interval'],
        'is_enabled' => 1,
        'custom_interval_seconds' => null,
        'fixed_day_of_week' => null,
        'fixed_time_of_day' => null,
        'last_run' => null,
        'next_run' => null,
        'status' => 'idle',
        'error_message' => null
    ];
    $merged_tasks[$scoped_key] = $db_row;
}

$failed_tasks = $scheduler->getFailedTasksByPlugin();
$boot_errors = $pluginManager->getPluginErrors();
$logs = $scheduler->getRecentExecutionLogs(30);

$days_map = [
    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
    4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'
];

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>Task Scheduler Overrides</h1>
        <p class="text-muted">Directly manage background crons, run tasks on demand, inspect real-time execution output, toggle execution, or set custom schedule overrides.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <form method="POST" class="d-inline-block">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="trigger_cron">
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="fa-solid fa-arrows-spin me-1"></i>Force Cron Check
            </button>
        </form>
    </div>
</div>

<?php if ($on_demand_result): ?>
    <div class="card shadow-sm border-<?= $on_demand_result['success'] ? 'success' : 'danger' ?> mb-4 text-start">
        <div class="card-header bg-<?= $on_demand_result['success'] ? 'success' : 'danger' ?> text-white d-flex justify-content-between align-items-center py-2">
            <span class="fw-bold">
                <i class="fa-solid fa-play me-2"></i>On-Demand Execution Result: <code><?= htmlspecialchars($on_demand_result['task_key']) ?></code>
            </span>
            <span class="badge bg-light text-dark">
                Duration: <?= $on_demand_result['duration'] ?>s
            </span>
        </div>
        <div class="card-body">
            <?php if ($on_demand_result['error']): ?>
                <div class="alert alert-danger small mb-3">
                    <strong>Error:</strong> <?= htmlspecialchars($on_demand_result['error']) ?>
                </div>
            <?php endif; ?>

            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-terminal me-1 text-secondary"></i>Task Captured Output:</h6>
            <?php if (!empty($on_demand_result['output'])): ?>
                <pre class="bg-dark text-light p-3 rounded small mb-0" style="max-height: 250px; overflow-y: auto; font-family: monospace;"><?= htmlspecialchars($on_demand_result['output']) ?></pre>
            <?php else: ?>
                <p class="text-muted small border rounded p-2 bg-light mb-0"><em>No stdout output was emitted by this task callback.</em></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($failed_tasks) || !empty($boot_errors)): ?>
    <div class="alert alert-danger shadow-sm mb-4 text-start" role="alert">
        <h5 class="alert-heading h6 fw-bold mb-2">
            <i class="fa-solid fa-shield-halved me-2"></i>Plugin Safeguard Alert: Isolated Errors Detected
        </h5>
        <p class="mb-2 small">The platform isolated errors in the following plugin(s) to prevent impacting core system functions or other plugins' tasks:</p>
        <ul class="mb-0 small ps-3">
            <?php foreach ($boot_errors as $slug => $err_text): ?>
                <li><strong>Module '<?= htmlspecialchars($slug) ?>' Boot Error:</strong> <?= htmlspecialchars($err_text) ?></li>
            <?php endforeach; ?>
            <?php foreach ($failed_tasks as $slug => $tasks): ?>
                <?php foreach ($tasks as $ft): ?>
                    <li><strong>Module '<?= htmlspecialchars($slug) ?>' Task Failure (<code><?= htmlspecialchars($ft['task_key']) ?></code>):</strong> <?= htmlspecialchars($ft['error_message'] ?: 'Execution crashed') ?></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show text-start"><i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show text-start"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row text-start">
    <!-- Tasks list -->
    <div class="col-lg-12">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-clock me-1"></i>Tasks from Enabled Modules (<?= count($merged_tasks) ?>)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($merged_tasks)): ?>
                    <p class="text-muted p-4 mb-0">No background tasks registered by currently enabled modules. Make sure you activate at least one module with scheduled tasks.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Task Identifier</th>
                                    <th>Source Plugin</th>
                                    <th>Base Interval</th>
                                    <th>Custom Override</th>
                                    <th>Status</th>
                                    <th>Next Scheduled Execution</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($merged_tasks as $task): ?>
                                    <tr>
                                        <td>
                                            <strong><code><?= htmlspecialchars($task['task_key']) ?></code></strong>
                                            <?php if ((int)$task['is_enabled'] === 0): ?>
                                                <span class="badge bg-danger ms-1">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($task['plugin_slug']) ?></span></td>
                                        <td><?= htmlspecialchars($task['interval_seconds']) ?>s</td>
                                        <td>
                                            <?php if ($task['fixed_day_of_week'] !== null || $task['fixed_time_of_day'] !== null): ?>
                                                <span class="small font-monospace text-dark">
                                                    Fixed: <?= $task['fixed_day_of_week'] !== null ? $days_map[$task['fixed_day_of_week']] : 'Daily' ?>
                                                    at <?= htmlspecialchars($task['fixed_time_of_day']) ?>
                                                </span>
                                            <?php elseif ($task['custom_interval_seconds'] !== null): ?>
                                                <span class="small font-monospace text-dark">Interval: <?= htmlspecialchars($task['custom_interval_seconds']) ?>s</span>
                                            <?php else: ?>
                                                <span class="text-muted small">None (Default)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($task['status'] === 'success'): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Success</span>
                                            <?php elseif ($task['status'] === 'failed'): ?>
                                                <span class="badge bg-danger" title="<?= htmlspecialchars($task['error_message']) ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i>Failed</span>
                                            <?php elseif ($task['status'] === 'running'): ?>
                                                <span class="badge bg-warning text-dark"><i class="fa-solid fa-arrows-spin fa-spin me-1"></i>Running</span>
                                            <?php else: ?>
                                                <span class="badge bg-info"><?= htmlspecialchars($task['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($task['next_run'] ?: 'Pending Trigger') ?></code></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline-block me-1">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="run_on_demand">
                                                <input type="hidden" name="task_key" value="<?= htmlspecialchars($task['task_key']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Execute this task immediately and view its output">
                                                    <i class="fa-solid fa-play me-1"></i>Run On-Demand
                                                </button>
                                            </form>

                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#taskModal<?= md5($task['task_key']) ?>">
                                                <i class="fa-solid fa-gears me-1"></i>Configure
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- MODAL: Customize Overrides -->
                                    <div class="modal fade" id="taskModal<?= md5($task['task_key']) ?>" tabindex="-1">
                                        <div class="modal-dialog modal-md">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title fw-bold">Configure Task: <?= htmlspecialchars($task['task_key']) ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="update_task">
                                                    <input type="hidden" name="task_key" value="<?= htmlspecialchars($task['task_key']) ?>">

                                                    <div class="modal-body text-start">
                                                        <!-- Enable / Disable Switch -->
                                                        <div class="form-check form-switch mb-3">
                                                            <input class="form-check-input" type="checkbox" name="is_enabled" id="enabled_<?= md5($task['task_key']) ?>" value="1" <?= (int)$task['is_enabled'] === 1 ? 'checked' : '' ?>>
                                                            <label class="form-check-label fw-bold" for="enabled_<?= md5($task['task_key']) ?>">Enable Task Execution</label>
                                                            <div class="form-text">If disabled, the task runner will completely skip this background cron.</div>
                                                        </div>

                                                        <hr class="my-3">

                                                        <!-- Option A: Custom Interval -->
                                                        <h6 class="fw-bold mb-2">Option A: Custom Interval (Seconds)</h6>
                                                        <div class="mb-3">
                                                            <input type="number" name="custom_interval_seconds" class="form-control form-control-sm" value="<?= htmlspecialchars($task['custom_interval_seconds'] ?? '') ?>" placeholder="e.g. 600 for 10 minutes">
                                                            <div class="form-text">Overrides the base interval defined in code. Leave blank to use defaults.</div>
                                                        </div>

                                                        <h6 class="fw-bold mb-2 text-center text-muted">-- OR --</h6>

                                                        <!-- Option B: Fixed schedule -->
                                                        <h6 class="fw-bold mb-2">Option B: Fixed Day/Time Schedule</h6>
                                                        <div class="row g-2 mb-2">
                                                            <div class="col-md-6">
                                                                <label class="form-label small">Day of Week</label>
                                                                <select name="fixed_day_of_week" class="form-select form-select-sm">
                                                                    <option value="">Daily (Every Day)</option>
                                                                    <option value="1" <?= $task['fixed_day_of_week'] == 1 ? 'selected' : '' ?>>Monday</option>
                                                                    <option value="2" <?= $task['fixed_day_of_week'] == 2 ? 'selected' : '' ?>>Tuesday</option>
                                                                    <option value="3" <?= $task['fixed_day_of_week'] == 3 ? 'selected' : '' ?>>Wednesday</option>
                                                                    <option value="4" <?= $task['fixed_day_of_week'] == 4 ? 'selected' : '' ?>>Thursday</option>
                                                                    <option value="5" <?= $task['fixed_day_of_week'] == 5 ? 'selected' : '' ?>>Friday</option>
                                                                    <option value="6" <?= $task['fixed_day_of_week'] == 6 ? 'selected' : '' ?>>Saturday</option>
                                                                    <option value="7" <?= $task['fixed_day_of_week'] == 7 ? 'selected' : '' ?>>Sunday</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label small">Time of Day</label>
                                                                <input type="time" name="fixed_time_of_day" class="form-control form-control-sm" value="<?= htmlspecialchars($task['fixed_time_of_day'] ?? '') ?>">
                                                            </div>
                                                        </div>
                                                        <div class="form-text mb-2">Define specific exact weekly day and time triggers for this background job. Ensure Option A is blank if you configure Option B.</div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                        <button type="submit" class="btn btn-primary">Save Overrides</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Task historical execution logs -->
<div class="row text-start">
    <div class="col-lg-12">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white"><i class="fa-solid fa-list-check me-1"></i>Recent Task Execution Logs</div>
            <div class="card-body p-0">
                <?php if (empty($logs)): ?>
                    <p class="text-muted p-4 mb-0">No historical execution logs recorded yet. Execute trigger cron to log runs.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-striped align-middle mb-0 small">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Task Identifier</th>
                                    <th>Run Started</th>
                                    <th>Run Ended</th>
                                    <th>Status</th>
                                    <th>Duration (Seconds)</th>
                                    <th>Error details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $l): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($l['task_key']) ?></code></td>
                                        <td><?= htmlspecialchars($l['run_started']) ?></td>
                                        <td><?= htmlspecialchars($l['run_ended'] ?? 'Active/Aborted') ?></td>
                                        <td>
                                            <?php if ($l['status'] === 'success'): ?>
                                                <span class="badge bg-success">Success</span>
                                            <?php elseif ($l['status'] === 'failed'): ?>
                                                <span class="badge bg-danger">Failed</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><?= htmlspecialchars($l['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($l['duration_seconds']) ?>s</strong></td>
                                        <td>
                                            <?php if ($l['error_message']): ?>
                                                <span class="text-danger font-monospace text-xs"><?= htmlspecialchars($l['error_message']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
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
