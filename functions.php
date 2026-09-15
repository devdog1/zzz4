<?php
// functions.php - Global Utility Helpers & Framework Wrappers
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Scheduler.php';
require_once __DIR__ . '/PluginManager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global Auth Reference
$auth = null;

function get_auth() {
    global $auth;
    if ($auth === null) {
        $config = require __DIR__ . '/config.php';
        $auth = new Auth($config);
    }
    return $auth;
}

// Global Plugin Manager Reference
$pluginManager = PluginManager::getInstance();

/* =========================================================
 * AZURE AD / MS TEAMS OAUTH ACCESS TOKEN HELPER
 * ========================================================= */

/**
 * Retrieve the active Microsoft Graph OAuth access token.
 * Checks all 4 standard session token locations:
 *  1. $_SESSION['user']['access_token']
 *  2. $_SESSION['access_token']
 *  3. $_SESSION['azure_access_token']
 *  4. $_SESSION['tokens']['access_token']
 */
function get_azure_access_token() {
    if (!empty($_SESSION['user']['access_token'])) {
        return $_SESSION['user']['access_token'];
    }
    if (!empty($_SESSION['access_token'])) {
        return $_SESSION['access_token'];
    }
    if (!empty($_SESSION['azure_access_token'])) {
        return $_SESSION['azure_access_token'];
    }
    if (!empty($_SESSION['tokens']['access_token'])) {
        return $_SESSION['tokens']['access_token'];
    }
    return null;
}

/* =========================================================
 * CORE HELPERS & PERMISSIONS
 * ========================================================= */

function has_permission($permission) {
    return get_auth()->hasPermission($permission);
}

function has_role($role) {
    return get_auth()->hasRole($role);
}

function require_login() {
    get_auth()->requireLogin();
}

/**
 * Return the logged-in user profile array from session, or null if unauthenticated.
 */
function current_user() {
    return $_SESSION['user'] ?? null;
}

/**
 * Safely escape strings for HTML output to prevent XSS.
 */
function e($string) {
    return htmlspecialchars((string)($string ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Set a flash message to display to the user on the next page load.
 *
 * @param string $type Message category (success, danger, warning, info)
 * @param string $message The message body
 */
function set_flash_message($type, $message) {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Retrieve and consume all pending flash messages from session.
 *
 * @return array List of flash message arrays [['type' => ..., 'message' => ...]]
 */
function get_flash_messages() {
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/* =========================================================
 * PLUGIN & HOOK HELPER WRAPPERS
 * ========================================================= */

function add_action($hook, $callback, $priority = 10) {
    PluginManager::getInstance()->addAction($hook, $callback, $priority);
}

function add_filter($hook, $callback, $priority = 10) {
    PluginManager::getInstance()->addFilter($hook, $callback, $priority);
}

function register_route($route_name, $callback) {
    PluginManager::getInstance()->registerRoute($route_name, $callback);
}

function url_for($route_name) {
    return 'index.php?route=' . urlencode($route_name);
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

/* =========================================================
 * SECURITY: CSRF (Anti-Forgery Tokens)
 * ========================================================= */

/**
 * Generate a cryptographically secure CSRF token and store it in session.
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden HTML input containing the active CSRF token.
 */
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(get_csrf_token()) . '">';
}

/**
 * Validate that the incoming request contains a valid anti-forgery token.
 */
function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        $session_token = $_SESSION['csrf_token'] ?? '';
        if (empty($token) || empty($session_token) || !hash_equals($session_token, $token)) {
            // Log security warning
            log_action('SECURITY_CSRF_VIOLATION', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);

            // Render basic warning screen and exit
            http_response_code(403);
            die("<h1>403 Forbidden: Security CSRF Verification Failed.</h1><p>Please reload the page and try again.</p>");
        }
    }
}

function csrf_verify() {
    validate_csrf();
}

/* =========================================================
 * SYSTEM & PLUGIN SETTINGS API
 * ========================================================= */

/**
 * Retrieve a plugin-specific setting value with key prefix isolation.
 */
function get_plugin_setting($plugin_slug, $key, $default = null) {
    $prefixed_key = 'plug_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $plugin_slug) . '_' . $key;
    return get_setting($prefixed_key, $default);
}

/**
 * Save or update a plugin-specific setting value.
 */
function set_plugin_setting($plugin_slug, $key, $value) {
    $prefixed_key = 'plug_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $plugin_slug) . '_' . $key;
    return set_setting($prefixed_key, $value);
}

function get_setting($key, $default = null) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function set_setting($key, $value) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
        ");
        $stmt->execute([$key, $value, $value]);
        log_action('SET_SETTING', ['key' => $key, 'value' => $value]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* =========================================================
 * AUDIT LOGGING API
 * ========================================================= */

function log_action($action, $details) {
    try {
        $db = get_db_connection();
        $user_id = $_SESSION['user_id'] ?? null;
        $username = null;

        if ($user_id) {
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $res = $stmt->fetch();
            $username = $res ? $res['username'] : null;
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (is_array($details) || is_object($details)) {
            $details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, username, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $username, $action, $details, $ip_address]);
    } catch (Exception $e) {
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

function get_audit_logs($limit = 100) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            SELECT al.*, u.display_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.timestamp DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/* =========================================================
 * USER DASHBOARD WIDGET PREFERENCES API
 * ========================================================= */

/**
 * Fetch a user's widget preferences indexed by widget_key.
 */
function get_user_widget_preferences($user_id) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT widget_key, is_visible, width_class, sort_order FROM user_widget_preferences WHERE user_id = ? ORDER BY sort_order ASC");
        $stmt->execute([(int)$user_id]);
        $rows = $stmt->fetchAll();
        $prefs = [];
        foreach ($rows as $r) {
            $prefs[$r['widget_key']] = $r;
        }
        return $prefs;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Save user's widget preference for a specific widget key.
 */
function save_user_widget_preference($user_id, $widget_key, $is_visible, $width_class, $sort_order) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            INSERT INTO user_widget_preferences (user_id, widget_key, is_visible, width_class, sort_order)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_visible = VALUES(is_visible),
                width_class = VALUES(width_class),
                sort_order = VALUES(sort_order),
                updated_at = NOW()
        ");
        $stmt->execute([(int)$user_id, $widget_key, (int)$is_visible, $width_class, (int)$sort_order]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* =========================================================
 * USER DIRECTORY MANAGEMENT API
 * ========================================================= */

function get_all_users() {
    try {
        $db = get_db_connection();
        $stmt = $db->query("SELECT * FROM users ORDER BY display_name ASC");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function get_user_by_id($id) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

/* =========================================================
 * CORE FRAMEWORK ROUTES & HEALTH CHECK ENDPOINT
 * ========================================================= */

add_action('register_routes', function() {
    // Health Check Endpoint (returns JSON platform metrics)
    register_route('health_check', function() {
        header('Content-Type: application/json');

        $db_ok = false;
        $db_error = null;
        try {
            $db = get_db_connection();
            $db->query("SELECT 1");
            $db_ok = true;
        } catch (Exception $e) {
            $db_error = $e->getMessage();
        }

        $active_plugins = PluginManager::getInstance()->getActivePlugins();
        $registered_tasks = Scheduler::getInstance()->getRegisteredTasks();

        $metrics = [
            'status' => $db_ok ? 'healthy' : 'unhealthy',
            'timestamp' => date('c'),
            'php_version' => PHP_VERSION,
            'site_name' => get_setting('site_name', 'Framework Portal'),
            'database' => [
                'status' => $db_ok ? 'connected' : 'error',
                'error' => $db_error
            ],
            'active_plugins_count' => count($active_plugins),
            'active_plugins' => array_values($active_plugins),
            'registered_tasks_count' => count($registered_tasks)
        ];

        echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    });
});

/* =========================================================
 * BOOTSTRAP HOOKS / PLUGINS SYSTEM
 * ========================================================= */

// Boot active plugins on system inclusion
$pluginManager->boot();
