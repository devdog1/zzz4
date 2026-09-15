<?php
// PluginManager.php - Robust WordPress-inspired Hooks, Filters, Routing & Plugin Management Engine
require_once __DIR__ . '/db.php';

class PluginManager
{
    private static $instance = null;
    private $actions = [];
    private $filters = [];
    private $routes = [];
    private $services = []; // Inter-Plugin Shared Service Registry
    private $activePlugins = [];
    private $pluginMeta = [];
    private $pluginErrors = [];

    private function __construct()
    {
        $this->loadActivePlugins();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* =========================================================
     * AUTOMATED CROSS-PLUGIN AUDIT TRAIL LOGGING HELPERS
     * ========================================================= */

    /**
     * Determine the plugin slug associated with a given file path.
     */
    private function getPluginSlugFromPath($filepath)
    {
        if (empty($filepath)) return null;
        $normalized = str_replace('\\', '/', $filepath);
        if (preg_match('#/plugins/([^/]+)/#i', $normalized, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Determine the plugin slug associated with a given callback.
     */
    private function getPluginSlugFromCallback($callback)
    {
        try {
            if (is_array($callback)) {
                $ref = new ReflectionMethod($callback[0], $callback[1]);
                return $this->getPluginSlugFromPath($ref->getFileName());
            } elseif ($callback instanceof Closure || is_string($callback)) {
                $ref = new ReflectionFunction($callback);
                return $this->getPluginSlugFromPath($ref->getFileName());
            }
        } catch (Throwable $t) {
            // Unable to reflect callback
        }
        return null;
    }

    /**
     * Inspect execution stack backtrace to determine if caller originates from a plugin module.
     */
    private function getCallerPluginSlug()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($trace as $frame) {
            if (!empty($frame['file'])) {
                $slug = $this->getPluginSlugFromPath($frame['file']);
                if ($slug !== null) {
                    return $slug;
                }
            }
        }
        return null;
    }

    /* =========================================================
     * HOOKS: ACTIONS (Side-effects, outputting HTML, events)
     * ========================================================= */
    public function addAction($tag, $callback, $priority = 10)
    {
        if (!isset($this->actions[$tag])) {
            $this->actions[$tag] = [];
        }
        $this->actions[$tag][$priority][] = $callback;
    }

    public function doAction($tag, ...$arg)
    {
        if (!isset($this->actions[$tag])) {
            return;
        }

        $callerSlug = $this->getCallerPluginSlug();

        $priorities = $this->actions[$tag];
        ksort($priorities);

        foreach ($priorities as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $targetSlug = $this->getPluginSlugFromCallback($callback);

                // Audit trail: Log when a plugin calls action hooks for another plugin
                if ($callerSlug !== null && $targetSlug !== null && $callerSlug !== $targetSlug) {
                    if (function_exists('log_action')) {
                        log_action('PLUGIN_CROSS_HOOK_CALL', [
                            'type' => 'action',
                            'hook_tag' => $tag,
                            'caller_plugin' => $callerSlug,
                            'target_plugin' => $targetSlug
                        ]);
                    }
                }

                // SANDBOX SHIELD: Wrap each plugin hook execution in try/catch
                try {
                    call_user_func_array($callback, $arg);
                } catch (Throwable $t) {
                    $error_msg = "Error executing action hook [{$tag}]: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();
                    error_log($error_msg);
                    if (function_exists('log_action')) {
                        log_action('PLUGIN_HOOK_CRASH', ['tag' => $tag, 'error' => $error_msg]);
                    }
                }
            }
        }
    }

    /* =========================================================
     * HOOKS: FILTERS (Modifying variables or HTML structures)
     * ========================================================= */
    public function addFilter($tag, $callback, $priority = 10)
    {
        if (!isset($this->filters[$tag])) {
            $this->filters[$tag] = [];
        }
        $this->filters[$tag][$priority][] = $callback;
    }

    public function applyFilters($tag, $value, ...$arg)
    {
        if (!isset($this->filters[$tag])) {
            return $value;
        }

        $callerSlug = $this->getCallerPluginSlug();

        $priorities = $this->filters[$tag];
        ksort($priorities);

        foreach ($priorities as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $targetSlug = $this->getPluginSlugFromCallback($callback);

                // Audit trail: Log when a plugin calls filter hooks for another plugin
                if ($callerSlug !== null && $targetSlug !== null && $callerSlug !== $targetSlug) {
                    if (function_exists('log_action')) {
                        log_action('PLUGIN_CROSS_HOOK_CALL', [
                            'type' => 'filter',
                            'hook_tag' => $tag,
                            'caller_plugin' => $callerSlug,
                            'target_plugin' => $targetSlug
                        ]);
                    }
                }

                // SANDBOX SHIELD: Wrap each filter hook execution in try/catch
                try {
                    $value = call_user_func_array($callback, array_merge([$value], $arg));
                } catch (Throwable $t) {
                    $error_msg = "Error executing filter hook [{$tag}]: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();
                    error_log($error_msg);
                    if (function_exists('log_action')) {
                        log_action('PLUGIN_FILTER_CRASH', ['tag' => $tag, 'error' => $error_msg]);
                    }
                }
            }
        }

        return $value;
    }

    /* =========================================================
     * INTER-PLUGIN EXPOSED SERVICES & FUNCTIONS
     * ========================================================= */

    /**
     * Expose a function or complete service capability to other plugins securely,
     * maintaining active user session/context verification.
     */
    public function registerService($service_name, $callback, $plugin_slug)
    {
        $this->services[$service_name] = [
            'callback' => $callback,
            'plugin_slug' => $plugin_slug
        ];
    }

    /**
     * Call an exposed function or service registered by another plugin.
     * Guarantees active user authentication context during cross-plugin communication.
     */
    public function callService($service_name, ...$args)
    {
        if (!isset($this->services[$service_name])) {
            throw new Exception("Service/Function '{$service_name}' is not registered or available.");
        }

        $service = $this->services[$service_name];

        // Ensure the owner plugin is active before running
        if (!$this->isPluginActive($service['plugin_slug'])) {
            throw new Exception("The module '{$service['plugin_slug']}' registering service '{$service_name}' is currently deactivated.");
        }

        // Context check: Enforce that user session context is active during execution
        if (session_status() === PHP_SESSION_NONE || !isset($_SESSION['user_id'])) {
            throw new Exception("Security context violation: An active user session is required to invoke cross-plugin services.");
        }

        $callerSlug = $this->getCallerPluginSlug();
        $targetSlug = $service['plugin_slug'];

        // Audit trail: Log cross-plugin service invocation
        if ($callerSlug !== null && $targetSlug !== null && $callerSlug !== $targetSlug) {
            if (function_exists('log_action')) {
                log_action('PLUGIN_CROSS_SERVICE_CALL', [
                    'service_name' => $service_name,
                    'caller_plugin' => $callerSlug,
                    'target_plugin' => $targetSlug
                ]);
            }
        }

        // Execute inside try/catch sandbox
        try {
            return call_user_func_array($service['callback'], $args);
        } catch (Throwable $t) {
            $error_msg = "Error during cross-plugin service execution [{$service_name}]: " . $t->getMessage();
            error_log($error_msg);
            throw new Exception($error_msg);
        }
    }

    /* =========================================================
     * EXTENSIBLE ROUTING
     * ========================================================= */
    public function registerRoute($route, $callback)
    {
        $this->routes[$route] = $callback;
    }

    public function handleRoute($route)
    {
        if (isset($this->routes[$route])) {
            // SANDBOX SHIELD: Wrap plugin route handlers in try/catch block
            try {
                call_user_func($this->routes[$route]);
            } catch (Throwable $t) {
                echo '<div class="alert alert-danger"><i class="fa-solid fa-bug me-1"></i> <strong>Critical Plugin Error:</strong> An uncaught exception occurred in this module view. Please check system audit trail.</div>';
                $error_msg = "Uncaught route exception in [{$route}]: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();
                error_log($error_msg);
                if (function_exists('log_action')) {
                    log_action('PLUGIN_ROUTE_CRASH', ['route' => $route, 'error' => $error_msg]);
                }
            }
            return true;
        }
        return false;
    }

    public function getRoutes()
    {
        return $this->routes;
    }

    /* =========================================================
     * PLUGIN DISCOVERY AND LIFE-CYCLE
     * ========================================================= */
    private function loadActivePlugins()
    {
        try {
            $db = get_db_connection();
            $stmt = $db->query("SELECT plugin_slug FROM active_plugins");
            $this->activePlugins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $this->activePlugins = [];
        }
    }

    public function getActivePlugins()
    {
        return $this->activePlugins;
    }

    public function getPluginErrors()
    {
        return $this->pluginErrors;
    }

    public function getPluginError($slug)
    {
        return $this->pluginErrors[$slug] ?? null;
    }

    public function discoverPlugins()
    {
        $pluginsDir = __DIR__ . '/plugins';
        if (!is_dir($pluginsDir)) {
            mkdir($pluginsDir, 0755, true);
        }

        $discovered = [];
        $items = scandir($pluginsDir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $pluginFile = $pluginsDir . '/' . $item . '/plugin.php';
            if (file_exists($pluginFile)) {
                $meta = $this->parsePluginHeader($pluginFile);
                $meta['slug'] = $item;
                $meta['active'] = in_array($item, $this->activePlugins);
                $discovered[$item] = $meta;
            }
        }

        $this->pluginMeta = $discovered;
        return $discovered;
    }

    private function parsePluginHeader($file)
    {
        $content = file_get_contents($file);
        $meta = [
            'name' => basename(dirname($file)),
            'description' => 'No description provided.',
            'version' => '1.0.0',
            'author' => 'Anonymous',
            'permissions' => '',
            'roles' => ''
        ];

        if (preg_match('/Plugin Name:\s*(.*)$/mi', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        }
        if (preg_match('/Description:\s*(.*)$/mi', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        }
        if (preg_match('/Version:\s*(.*)$/mi', $content, $matches)) {
            $meta['version'] = trim($matches[1]);
        }
        if (preg_match('/Author:\s*(.*)$/mi', $content, $matches)) {
            $meta['author'] = trim($matches[1]);
        }
        if (preg_match('/Permissions:\s*(.*)$/mi', $content, $matches)) {
            $meta['permissions'] = trim($matches[1]);
        }
        if (preg_match('/Roles:\s*(.*)$/mi', $content, $matches)) {
            $meta['roles'] = trim($matches[1]);
        }

        return $meta;
    }

    public function isPluginActive($slug)
    {
        return in_array($slug, $this->activePlugins);
    }

    /**
     * Perform a comprehensive dry-run compatibility inspection and analysis of a plugin.
     * Returns a full diagnostic report including checks, expected changes, and compatibility verdict.
     */
    public function inspectPlugin($slug)
    {
        $pluginsDir = __DIR__ . '/plugins';
        $pluginDir = $pluginsDir . '/' . $slug;
        $pluginFile = $pluginDir . '/plugin.php';

        $report = [
            'slug' => $slug,
            'compatible' => true,
            'meta' => [],
            'checks' => [],
            'expected_changes' => []
        ];

        // 1. File existence check
        if (!file_exists($pluginFile)) {
            $report['compatible'] = false;
            $report['checks'][] = [
                'type' => 'file_structure',
                'status' => 'fail',
                'title' => 'Entry File missing',
                'message' => "Expected plugin entry file at 'plugins/{$slug}/plugin.php' was not found."
            ];
            return $report;
        }

        $report['checks'][] = [
            'type' => 'file_structure',
            'status' => 'pass',
            'title' => 'Entry File Present',
            'message' => "Plugin entry file 'plugins/{$slug}/plugin.php' located successfully."
        ];

        // 2. Metadata parsing
        $meta = $this->parsePluginHeader($pluginFile);
        $report['meta'] = $meta;

        $clean_slug = preg_replace('/[^a-zA-Z0-9_]/', '_', $slug);
        $required_prefix = $clean_slug . '_';

        // 3. Syntax checks on PHP files
        $php_files = [$pluginFile];
        foreach (['models', 'views', 'tasks'] as $sub_dir) {
            $d = $pluginDir . '/' . $sub_dir;
            if (is_dir($d)) {
                $items = scandir($d);
                foreach ($items as $item) {
                    if (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
                        $php_files[] = $d . '/' . $item;
                    }
                }
            }
        }

        $syntax_errors = [];
        $php_bin = 'php';
        if (defined('PHP_BINARY') && PHP_BINARY && strpos(PHP_BINARY, 'fpm') === false) {
            $php_bin = PHP_BINARY;
        } elseif (file_exists('/usr/bin/php')) {
            $php_bin = '/usr/bin/php';
        }

        foreach ($php_files as $f) {
            $rel_path = str_replace($pluginsDir . '/', '', $f);
            $output = [];
            $return_var = 0;
            @exec(escapeshellcmd("{$php_bin} -l " . escapeshellarg($f)), $output, $return_var);

            $output_str = implode(' ', $output);
            if ($return_var !== 0 || strpos($output_str, 'Usage:') !== false) {
                // Fallback to native PHP token parser (TOKEN_PARSE)
                try {
                    $code = file_get_contents($f);
                    if (defined('TOKEN_PARSE')) {
                        token_get_all($code, TOKEN_PARSE);
                    } else {
                        token_get_all($code);
                    }
                } catch (ParseError $pe) {
                    $syntax_errors[] = $rel_path . ': Parse error - ' . $pe->getMessage() . ' on line ' . $pe->getLine();
                } catch (Throwable $t) {
                    $syntax_errors[] = $rel_path . ': ' . $t->getMessage();
                }
            }
        }

        if (!empty($syntax_errors)) {
            $report['compatible'] = false;
            $report['checks'][] = [
                'type' => 'syntax',
                'status' => 'fail',
                'title' => 'PHP Syntax Check',
                'message' => "Syntax errors detected: " . implode('; ', $syntax_errors)
            ];
        } else {
            $report['checks'][] = [
                'type' => 'syntax',
                'status' => 'pass',
                'title' => 'PHP Syntax Check',
                'message' => "All " . count($php_files) . " PHP files passed lint/syntax validation without errors."
            ];
        }

        // 4. Permission collision check
        $db = get_db_connection();
        $perms_to_register = [];
        if (!empty($meta['permissions'])) {
            $perm_list = array_map('trim', explode(',', $meta['permissions']));
            foreach ($perm_list as $perm_raw) {
                $clean_perm = preg_replace('/[^a-zA-Z0-9_]/', '', $perm_raw);
                if (strpos($clean_perm, $required_prefix) !== 0) {
                    $clean_perm = $required_prefix . $clean_perm;
                }
                $perms_to_register[] = $clean_perm;

                if (!$this->isPluginActive($slug)) {
                    $stmt = $db->prepare("SELECT id FROM permissions WHERE permission_name = ?");
                    $stmt->execute([$clean_perm]);
                    if ($stmt->fetch()) {
                        $report['compatible'] = false;
                        $report['checks'][] = [
                            'type' => 'permissions',
                            'status' => 'fail',
                            'title' => 'Permission Name Conflict',
                            'message' => "Permission '{$clean_perm}' is already registered in the database by another module."
                        ];
                    }
                }
            }

            if (!empty($perms_to_register)) {
                $report['expected_changes'][] = "Register " . count($perms_to_register) . " dynamic permissions: " . implode(', ', $perms_to_register);
                $report['checks'][] = [
                    'type' => 'permissions',
                    'status' => 'pass',
                    'title' => 'Permission Namespace Inspection',
                    'message' => "Permissions properly prefixed with '{$required_prefix}' namespace."
                ];
            }
        }

        // 5. Role collision check
        if (!empty($meta['roles'])) {
            $roles_list = array_map('trim', explode(';', $meta['roles']));
            $roles_to_register = [];
            foreach ($roles_list as $role_expr) {
                if (strpos($role_expr, ':') === false) continue;
                list($role_raw, $role_perms_raw) = explode(':', $role_expr, 2);
                $clean_role = preg_replace('/[^a-zA-Z0-9_]/', '', trim($role_raw));
                if (strpos($clean_role, $required_prefix) !== 0) {
                    $clean_role = $required_prefix . $clean_role;
                }
                $roles_to_register[] = $clean_role;

                if (!$this->isPluginActive($slug)) {
                    $stmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
                    $stmt->execute([$clean_role]);
                    if ($stmt->fetch()) {
                        $report['compatible'] = false;
                        $report['checks'][] = [
                            'type' => 'roles',
                            'status' => 'fail',
                            'title' => 'Role Name Conflict',
                            'message' => "Role '{$clean_role}' is already registered in the database by another module."
                        ];
                    }
                }
            }

            if (!empty($roles_to_register)) {
                $report['expected_changes'][] = "Provision " . count($roles_to_register) . " dynamic roles: " . implode(', ', $roles_to_register);
                $report['checks'][] = [
                    'type' => 'roles',
                    'status' => 'pass',
                    'title' => 'Role Namespace Inspection',
                    'message' => "Roles properly prefixed with '{$required_prefix}' namespace."
                ];
            }
        }

        // 6. SQL installation inspection
        $install_sql_file = $pluginDir . '/sql/install.sql';
        if (file_exists($install_sql_file)) {
            $sql_content = file_get_contents($install_sql_file);
            $expected_prefix = 'plug_' . str_replace('-', '_', $slug) . '_';

            // Check for table creation statements
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\']?([a-zA-Z0-9_{}]+)[`\']?/i', $sql_content, $matches)) {
                $tables = $matches[1];
                $resolved_tables = [];
                foreach ($tables as $tb) {
                    $resolved_tb = str_replace('{prefix}', $expected_prefix, $tb);
                    $resolved_tables[] = $resolved_tb;

                    // Verify namespace compliance
                    if (strpos($resolved_tb, $expected_prefix) !== 0) {
                        $report['compatible'] = false;
                        $report['checks'][] = [
                            'type' => 'database',
                            'status' => 'fail',
                            'title' => 'SQL Namespace Violation',
                            'message' => "Table '{$resolved_tb}' does not use required plugin prefix '{$expected_prefix}'."
                        ];
                    }
                }

                if (!empty($resolved_tables)) {
                    $report['expected_changes'][] = "Create/Ensure database tables: " . implode(', ', $resolved_tables);
                    $report['checks'][] = [
                        'type' => 'database',
                        'status' => 'pass',
                        'title' => 'Database Schema Inspection',
                        'message' => "SQL schema file verified with proper prefixing '{$expected_prefix}'."
                    ];
                }
            }
        }

        // 7. Overall verdict check
        if ($report['compatible']) {
            $report['checks'][] = [
                'type' => 'verdict',
                'status' => 'pass',
                'title' => 'Full Compatibility Verified',
                'message' => "Plugin '{$meta['name']}' is fully compatible with this Base Framework."
            ];
        }

        return $report;
    }

    public function activatePlugin($slug)
    {
        if ($this->isPluginActive($slug)) return true;

        $db = get_db_connection();
        $db->beginTransaction();

        try {
            // Find and parse plugin metadata headers
            $pluginsDir = __DIR__ . '/plugins';
            $pluginFile = $pluginsDir . '/' . $slug . '/plugin.php';
            if (!file_exists($pluginFile)) {
                throw new Exception("Plugin file not found: " . $pluginFile);
            }

            $meta = $this->parsePluginHeader($pluginFile);
            $clean_slug = preg_replace('/[^a-zA-Z0-9_]/', '_', $slug);
            $required_prefix = $clean_slug . '_';

            // 1. Dynamically load, validate, prefix, and register permissions to DB
            $provisioned_perms = [];
            if (!empty($meta['permissions'])) {
                $perm_list = array_map('trim', explode(',', $meta['permissions']));

                foreach ($perm_list as $perm_raw) {
                    $clean_perm = preg_replace('/[^a-zA-Z0-9_]/', '', $perm_raw);

                    if (strpos($clean_perm, $required_prefix) !== 0) {
                        $clean_perm = $required_prefix . $clean_perm;
                    }

                    // Enforce permission uniqueness
                    $check_stmt = $db->prepare("SELECT id FROM permissions WHERE permission_name = ?");
                    $check_stmt->execute([$clean_perm]);
                    if ($check_stmt->fetch()) {
                        throw new Exception("Security Conflict: The permission name '{$clean_perm}' is already registered.");
                    }

                    $insert_stmt = $db->prepare("INSERT INTO permissions (permission_name, description) VALUES (?, ?)");
                    $insert_stmt->execute([$clean_perm, "Dynamic permission registered by plugin: " . $meta['name']]);
                    $provisioned_perms[$perm_raw] = $db->lastInsertId();
                    $provisioned_perms[$clean_perm] = $db->lastInsertId();
                }
            }

            // 2. Dynamically load, prefix, and register custom Roles and assign their permissions
            if (!empty($meta['roles'])) {
                // Format example: role_name:perm1,perm2; role_name2:perm3
                $roles_list = array_map('trim', explode(';', $meta['roles']));

                foreach ($roles_list as $role_expr) {
                    if (strpos($role_expr, ':') === false) continue;
                    list($role_raw, $role_perms_raw) = explode(':', $role_expr, 2);

                    $role_raw = trim($role_raw);
                    $clean_role = preg_replace('/[^a-zA-Z0-9_]/', '', $role_raw);
                    if (strpos($clean_role, $required_prefix) !== 0) {
                        $clean_role = $required_prefix . $clean_role;
                    }

                    // Enforce role uniqueness
                    $check_stmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
                    $check_stmt->execute([$clean_role]);
                    if ($check_stmt->fetch()) {
                        throw new Exception("Security Conflict: The role name '{$clean_role}' is already registered.");
                    }

                    // Insert role
                    $insert_stmt = $db->prepare("INSERT INTO roles (role_name, description) VALUES (?, ?)");
                    $insert_stmt->execute([$clean_role, "Dynamic role registered by plugin: " . $meta['name']]);
                    $role_id = $db->lastInsertId();

                    // Assign listed permissions to this role
                    $role_perms = array_map('trim', explode(',', $role_perms_raw));
                    foreach ($role_perms as $rp) {
                        $full_rp_name = preg_replace('/[^a-zA-Z0-9_]/', '', $rp);
                        if (strpos($full_rp_name, $required_prefix) !== 0) {
                            $full_rp_name = $required_prefix . $full_rp_name;
                        }

                        // Retrieve the permission ID
                        $stmt_p = $db->prepare("SELECT id FROM permissions WHERE permission_name = ?");
                        $stmt_p->execute([$full_rp_name]);
                        $p_row = $stmt_p->fetch();
                        if ($p_row) {
                            $stmt_link = $db->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                            $stmt_link->execute([$role_id, $p_row['id']]);
                        }
                    }
                }
            }

            // Save active state to DB
            $stmt = $db->prepare("INSERT IGNORE INTO active_plugins (plugin_slug) VALUES (?)");
            $stmt->execute([$slug]);

            $db->commit();
            $this->activePlugins[] = $slug;

            // Load file BEFORE triggers
            try {
                require_once $pluginFile;
            } catch (Throwable $t) {
                error_log("Failed to include plugin file upon activation: " . $t->getMessage());
            }

            // Trigger activation action hook safely
            $this->doAction("plugin_activate_{$slug}");
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to activate plugin {$slug}: " . $e->getMessage());
            global $err;
            $err = $e->getMessage();
            return false;
        }
    }

    public function deactivatePlugin($slug, $purge_tables = false)
    {
        if (!$this->isPluginActive($slug)) return true;

        $db = get_db_connection();
        $db->beginTransaction();

        try {
            // Find and parse plugin metadata headers
            $pluginsDir = __DIR__ . '/plugins';
            $pluginFile = $pluginsDir . '/' . $slug . '/plugin.php';
            if (file_exists($pluginFile)) {
                $meta = $this->parsePluginHeader($pluginFile);
                $clean_slug = preg_replace('/[^a-zA-Z0-9_]/', '_', $slug);
                $required_prefix = $clean_slug . '_';

                // 1. Drop associated custom Roles & Role-permissions linkage
                if (!empty($meta['roles'])) {
                    $roles_list = array_map('trim', explode(';', $meta['roles']));
                    foreach ($roles_list as $role_expr) {
                        if (strpos($role_expr, ':') === false) continue;
                        list($role_raw, $role_perms_raw) = explode(':', $role_expr, 2);

                        $role_raw = trim($role_raw);
                        $clean_role = preg_replace('/[^a-zA-Z0-9_]/', '', $role_raw);
                        if (strpos($clean_role, $required_prefix) !== 0) {
                            $clean_role = $required_prefix . $clean_role;
                        }

                        $delete_stmt = $db->prepare("DELETE FROM roles WHERE role_name = ?");
                        $delete_stmt->execute([$clean_role]);
                    }
                }

                // 2. Dynamically clean up / remove permissions on deactivation
                if (!empty($meta['permissions'])) {
                    $perm_list = array_map('trim', explode(',', $meta['permissions']));

                    foreach ($perm_list as $perm_raw) {
                        $clean_perm = preg_replace('/[^a-zA-Z0-9_]/', '', $perm_raw);
                        if (strpos($clean_perm, $required_prefix) !== 0) {
                            $clean_perm = $required_prefix . $clean_perm;
                        }

                        // Remove permission safely from permissions table
                        $delete_stmt = $db->prepare("DELETE FROM permissions WHERE permission_name = ?");
                        $delete_stmt->execute([$clean_perm]);
                    }
                }
            }

            // Remove active state
            $stmt = $db->prepare("DELETE FROM active_plugins WHERE plugin_slug = ?");
            $stmt->execute([$slug]);

            $db->commit();

            // Load file
            if (file_exists($pluginFile)) {
                try {
                    require_once $pluginFile;
                } catch (Throwable $t) {
                    error_log("Failed to include plugin file upon deactivation: " . $t->getMessage());
                }
            }

            // Trigger deactivation action hook safely, passing $purge_tables flag
            $this->doAction("plugin_deactivate_{$slug}", $purge_tables);

            if (($key = array_search($slug, $this->activePlugins)) !== false) {
                unset($this->activePlugins[$key]);
            }

            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to deactivate plugin {$slug}: " . $e->getMessage());
            return false;
        }
    }

    public function boot()
    {
        $pluginsDir = __DIR__ . '/plugins';
        foreach ($this->activePlugins as $slug) {
            $pluginFile = $pluginsDir . '/' . $slug . '/plugin.php';
            if (file_exists($pluginFile)) {
                // Wrap each plugin booting individually to ensure faulty plugins don't break loading
                try {
                    require_once $pluginFile;
                } catch (Throwable $t) {
                    $err_msg = "Error booting plugin [{$slug}]: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();
                    $this->pluginErrors[$slug] = $err_msg;
                    error_log($err_msg);
                    if (function_exists('log_action')) {
                        log_action('PLUGIN_BOOT_CRASH', ['slug' => $slug, 'error' => $err_msg]);
                    }
                }
            }
        }

        // Trigger lifecycle hooks after loading plugin files
        $this->doAction('register_routes');
        if (class_exists('Scheduler')) {
            $this->doAction('init_scheduler', Scheduler::getInstance());
        }
    }
}
