<?php
// cron.php - Command-line (CLI) and Web-safe entry point to execute scheduled tasks in parallel
// Usage in crontab: * * * * * php /path/to/cron.php >/dev/null 2>&1

// Ensure database connections and active plugins boot cleanly
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Scheduler.php';

// Check if running from CLI or authorized request
$is_cli = (php_sapi_name() === 'cli');
$is_authorized_web = isset($_GET['key']) && $_GET['key'] === get_csrf_token();

if (!$is_cli && !$is_authorized_web) {
    http_response_code(403);
    die("Access Denied: Scheduled tasks cron can only be triggered via server CLI or authorized web hooks.");
}

// Parse single task execution argument
$single_task_key = null;
if ($is_cli) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--task=') === 0) {
            $single_task_key = substr($arg, 7);
        }
    }
} else {
    $single_task_key = $_GET['task'] ?? null;
}

try {
    $scheduler = Scheduler::getInstance();

    if (!empty($single_task_key)) {
        // Execute ONLY this specific task directly in this process
        $scheduler->executeSingleTask($single_task_key);
        if (!$is_cli) {
            echo "<h1>Execution of task [" . htmlspecialchars($single_task_key) . "] complete.</h1>";
        }
    } else {
        // Evaluate all due tasks and spawn them asynchronously in parallel background processes
        $scheduler->runPendingTasks();
        if (!$is_cli) {
            echo "<h1>Task Scheduler parallel triggers initialized.</h1>";
        }
    }
} catch (Throwable $t) {
    error_log("Task Scheduler Cron Failure: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
    if (!$is_cli) {
        echo "<h1>Scheduler Error Occurred</h1><p>" . htmlspecialchars($t->getMessage()) . "</p>";
    }
}
