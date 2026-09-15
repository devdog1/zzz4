<?php
// index.php - Streamlined Portal Dashboard Home Page
require_once __DIR__ . '/functions.php';

// Redirect to login if user is not logged in
require_login();

// Check if we are executing a custom plugin route
$route = $_GET['route'] ?? null;
if ($route) {
    // Buffer output so JSON/AJAX routes that call exit() can return raw JSON without HTML headers
    ob_start();
    $handled = $pluginManager->handleRoute($route);
    $route_output = ob_get_clean();

    if ($handled) {
        // If route completed normally (did not call exit for raw JSON), wrap in theme templates
        require_once __DIR__ . '/header.php';
        echo $route_output;
        require_once __DIR__ . '/footer.php';
        exit;
    } else {
        require_once __DIR__ . '/header.php';
        echo '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> No plugin found matching route: ' . htmlspecialchars($route) . '</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
}

// Handle POST AJAX action to save dashboard widget preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_widget_preferences') {
    validate_csrf();
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id) {
        $prefs_json = $_POST['preferences'] ?? '[]';
        $prefs = json_decode($prefs_json, true);
        if (is_array($prefs)) {
            foreach ($prefs as $index => $item) {
                $widget_key = trim($item['widget_key'] ?? '');
                $is_visible = !empty($item['is_visible']) ? 1 : 0;
                $width_class = trim($item['width_class'] ?? 'col-12');
                $sort_order = (int)($item['sort_order'] ?? (($index + 1) * 10));

                if (!empty($widget_key)) {
                    save_user_widget_preference($user_id, $widget_key, $is_visible, $width_class, $sort_order);
                }
            }
            log_action('SAVE_WIDGET_PREFERENCES', ['user_id' => $user_id]);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Invalid session or payload']);
    exit;
}

// Handle POST AJAX action to save system default dashboard layout (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_default_widget_preferences') {
    validate_csrf();
    if (has_permission('manage_settings') || has_permission('manage_plugins')) {
        $prefs_json = $_POST['preferences'] ?? '[]';
        $prefs = json_decode($prefs_json, true);
        if (is_array($prefs)) {
            $formatted_defaults = [];
            foreach ($prefs as $index => $item) {
                $widget_key = trim($item['widget_key'] ?? '');
                if (!empty($widget_key)) {
                    $formatted_defaults[$widget_key] = [
                        'widget_key' => $widget_key,
                        'is_visible' => !empty($item['is_visible']) ? 1 : 0,
                        'width_class' => trim($item['width_class'] ?? 'col-12'),
                        'sort_order' => (int)($item['sort_order'] ?? (($index + 1) * 10))
                    ];
                }
            }
            set_setting('default_dashboard_layout', json_encode($formatted_defaults));
            log_action('SAVE_DEFAULT_DASHBOARD_LAYOUT', ['admin_user_id' => $_SESSION['user_id'] ?? null]);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Permission denied or invalid payload']);
    exit;
}

// Handle Reset Preferences action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_widget_preferences') {
    validate_csrf();
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id) {
        $db = get_db_connection();
        $stmt = $db->prepare("DELETE FROM user_widget_preferences WHERE user_id = ?");
        $stmt->execute([$user_id]);
        log_action('RESET_WIDGET_PREFERENCES', ['user_id' => $user_id]);
        redirect('index.php');
    }
}

// Render Core Dashboard Portal Screen
require_once __DIR__ . '/header.php';

$activePluginsList = $pluginManager->getActivePlugins();
$activeCount = count($activePluginsList);
$userId = $_SESSION['user_id'] ?? null;

// Fetch current user details as dynamic widget context
$currentUserContext = [
    'id' => $userId,
    'username' => $_SESSION['user']['email'] ?? '',
    'display_name' => $_SESSION['user']['name'] ?? 'User',
    'roles' => isset($_SESSION['roles']) ? array_keys($_SESSION['roles']) : [],
    'permissions' => isset($_SESSION['permissions']) ? array_keys($_SESSION['permissions']) : []
];

// Fetch saved widget preferences for current user
$userWidgetPrefs = $userId ? get_user_widget_preferences($userId) : [];

// If user has no personal widget preferences saved, fallback to system default layout if set
if (empty($userWidgetPrefs)) {
    $defaultLayoutJson = get_setting('default_dashboard_layout', '{}');
    $userWidgetPrefs = json_decode($defaultLayoutJson, true) ?: [];
}

// Capture HTML rendered by plugins on 'index_dashboard_widgets' hook
ob_start();
$pluginManager->doAction('index_dashboard_widgets', $currentUserContext);
$widgets_raw_html = ob_get_clean();
?>

<div class="row mb-4 align-items-center text-start">
    <div class="col-md-7">
        <h1 class="h2"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Core Dashboard</h1>
        <p class="text-muted mb-0">Welcome to your Portal Homepage. Customize and reorder widgets to fit your workflow.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end align-items-center gap-2">
        <button type="button" id="toggleCustomizeBtn" class="btn btn-sm btn-outline-primary" onclick="toggleWidgetCustomizeMode()">
            <i class="fa-solid fa-sliders me-1"></i><span id="customizeBtnText">Customize Dashboard</span>
        </button>
        <span class="badge bg-secondary p-2"><i class="fa-solid fa-clock me-1"></i> <?= date('Y-m-d H:i:s') ?></span>
    </div>
</div>

<!-- Customization Control Toolbar (Hidden by default) -->
<div id="customizeToolbar" class="card shadow-sm border-primary mb-4 text-start d-none">
    <div class="card-body bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h6 class="fw-bold mb-1 text-primary"><i class="fa-solid fa-sliders me-2"></i>Dashboard Layout Customizer</h6>
            <small class="text-muted">Use controls on each widget to change width, reorder position, or hide/show cards. New widgets default to showing.</small>
        </div>
        <div class="d-flex gap-2">
            <form method="POST" class="d-inline" onsubmit="return confirm('Reset dashboard layout to system default?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="reset_widget_preferences">
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="fa-solid fa-rotate-left me-1"></i>Reset Defaults
                </button>
            </form>
            <?php if (has_permission('manage_settings') || has_permission('manage_plugins')): ?>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="saveWidgetPreferences('save_default_widget_preferences')">
                    <i class="fa-solid fa-sliders me-1"></i>Save as System Default
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-success" onclick="saveWidgetPreferences('save_widget_preferences')">
                <i class="fa-solid fa-floppy-disk me-1"></i>Save My Layout
            </button>
        </div>
    </div>
</div>

<!-- Extensible Dashboard Widget Hook (Per-User Contextual Widgets) -->
<div class="row mb-4" id="dashboardWidgetsContainer">
    <?php
    // Pure PHP Widget Parser (Does NOT require php-xml / DOMDocument extension)
    if (!empty($widgets_raw_html)) {
        $parsed_widgets = [];
        $default_sort = 10;

        // Iterate through all top-level <div ...> blocks outputted by any enabled plugin
        $offset = 0;
        $index = 0;
        $len = strlen($widgets_raw_html);

        while ($offset < $len) {
            if (preg_match('/<div\b[^>]*>/i', $widgets_raw_html, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $tag_str = $matches[0][0];
                $start_index = $matches[0][1];

                // Extract or generate data-widget-key
                $w_key = '';
                if (preg_match('/data-widget-key=["\']([^"\']+)["\']/i', $tag_str, $km)) {
                    $w_key = $km[1];
                } else {
                    $w_key = 'widget_' . ($index + 1);
                }

                // Extract or generate data-widget-title
                $w_title = '';
                if (preg_match('/data-widget-title=["\']([^"\']+)["\']/i', $tag_str, $tm)) {
                    $w_title = $tm[1];
                } else {
                    $w_title = 'Dashboard Widget ' . ($index + 1);
                }

                // Find matching closing </div> by tracking nested <div> tag depth
                $depth = 1;
                $i = $start_index + strlen($tag_str);
                $end_index = $len;

                while ($i < $len) {
                    if (substr($widgets_raw_html, $i, 4) === '<div') {
                        $depth++;
                        $i += 4;
                    } elseif (substr($widgets_raw_html, $i, 6) === '</div>') {
                        $depth--;
                        if ($depth === 0) {
                            $end_index = $i + 6;
                            break;
                        }
                        $i += 6;
                    } else {
                        $i++;
                    }
                }

                $widget_html = substr($widgets_raw_html, $start_index, $end_index - $start_index);

                // Strip hardcoded outer grid col-* classes from plugin root div so it dynamically adopts resized width
                $widget_html = preg_replace_callback('/^<div(\b[^>]*)\bclass=["\']([^"\']*)["\']/i', function($m) {
                    $attrs = $m[1];
                    $classes = $m[2];
                    // Remove col-*, col-sm-*, col-md-*, col-lg-*, col-xl-* hardcoded grid classes
                    $cleaned_classes = trim(preg_replace('/\bcol-(?:12|[1-9]|1[0-1])\b|\bcol-(?:sm|md|lg|xl|xxl)-(?:12|[1-9]|1[0-1])\b/i', '', $classes));
                    // Ensure w-100 is present
                    if (strpos($cleaned_classes, 'w-100') === false) {
                        $cleaned_classes .= ' w-100';
                    }
                    return '<div' . $attrs . ' class="' . trim($cleaned_classes) . '"';
                }, $widget_html, 1);

                // Check saved user preference for this widget
                $pref = $userWidgetPrefs[$w_key] ?? null;

                // NEW widgets NOT present in saved preferences default to visible (1) and col-12
                $is_visible = $pref ? (int)$pref['is_visible'] : 1;
                $width_class = $pref ? $pref['width_class'] : 'col-12';
                $sort_order = $pref ? (int)$pref['sort_order'] : ($default_sort + ($index * 10));

                $parsed_widgets[] = [
                    'key' => $w_key,
                    'title' => $w_title,
                    'is_visible' => $is_visible,
                    'width_class' => $width_class,
                    'sort_order' => $sort_order,
                    'html' => $widget_html
                ];

                $offset = $end_index;
                $index++;
            } else {
                break;
            }
        }

        if (!empty($parsed_widgets)) {
            // Sort parsed widgets by sort_order ASC
            usort($parsed_widgets, function($a, $b) {
                return $a['sort_order'] <=> $b['sort_order'];
            });

            // Render ordered widgets
            foreach ($parsed_widgets as $pw) {
                $display_style = ($pw['is_visible'] === 0) ? 'display: none !important;' : '';
                ?>
                <div class="widget-item <?= htmlspecialchars($pw['width_class']) ?> mb-4"
                     data-widget-key="<?= htmlspecialchars($pw['key']) ?>"
                     data-widget-title="<?= htmlspecialchars($pw['title']) ?>"
                     data-sort-order="<?= $pw['sort_order'] ?>"
                     data-is-visible="<?= $pw['is_visible'] ?>"
                     data-width-class="<?= htmlspecialchars($pw['width_class']) ?>"
                     style="<?= $display_style ?>">

                    <!-- Widget Customization Overlay Bar (Shown in Customize Mode) -->
                    <div class="widget-custombar card mb-2 border-primary bg-dark text-white d-none">
                        <div class="card-body p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary"><i class="fa-solid fa-up-down-left-right me-1"></i><?= htmlspecialchars($pw['title']) ?></span>
                                <span class="badge bg-secondary visibility-badge"><?= $pw['is_visible'] ? 'Visible' : 'Hidden' ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <label class="small me-1 text-light">Width:</label>
                                <select class="form-select form-select-sm width-selector" style="width: 140px;" onchange="updateWidgetWidth(this)">
                                    <option value="col-12" <?= $pw['width_class'] === 'col-12' ? 'selected' : '' ?>>Full Width (12/12)</option>
                                    <option value="col-lg-8" <?= $pw['width_class'] === 'col-lg-8' ? 'selected' : '' ?>>2/3 Width (8/12)</option>
                                    <option value="col-lg-6" <?= $pw['width_class'] === 'col-lg-6' ? 'selected' : '' ?>>1/2 Width (6/12)</option>
                                    <option value="col-lg-4" <?= $pw['width_class'] === 'col-lg-4' ? 'selected' : '' ?>>1/3 Width (4/12)</option>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-light visibility-toggle-btn" onclick="toggleWidgetVisibility(this)" title="Toggle Show/Hide">
                                    <i class="fa-solid <?= $pw['is_visible'] ? 'fa-eye-slash text-warning' : 'fa-eye text-success' ?>"></i>
                                    <span><?= $pw['is_visible'] ? 'Hide' : 'Show' ?></span>
                                </button>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-light" onclick="moveWidgetUp(this)" title="Move Up"><i class="fa-solid fa-arrow-up"></i></button>
                                    <button type="button" class="btn btn-outline-light" onclick="moveWidgetDown(this)" title="Move Down"><i class="fa-solid fa-arrow-down"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="widget-inner-content">
                        <?= $pw['html'] ?>
                    </div>
                </div>
                <?php
            }
        } else {
            // Fallback if no widget-block class wrapper was parsed
            echo $widgets_raw_html;
        }
    }
    ?>
</div>

<?php if (has_permission('manage_plugins')): ?>
    <div class="row text-start">
        <!-- Active Plugins list panel -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-puzzle-piece me-2"></i>Active Feature Modules</div>
                <div class="card-body">
                    <p class="small text-muted mb-3">All portal features are dynamically served by independent feature packages. Active modules are monitored below:</p>
                    <?php if (empty($activePluginsList)): ?>
                        <div class="alert alert-light border small text-center mb-0">No dynamic feature modules are currently enabled on your portal.</div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($activePluginsList as $active_slug): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 bg-light rounded border border-start border-3 border-success d-flex align-items-center justify-content-between">
                                        <div>
                                            <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $active_slug))) ?></h6>
                                            <small class="text-muted font-monospace">slug: <?= htmlspecialchars($active_slug) ?></small>
                                        </div>
                                        <span class="badge bg-success small"><i class="fa-solid fa-circle-check me-1"></i>Running</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Quick Stats -->
        <div class="col-lg-4">
            <!-- Quick Stats -->
            <div class="card mb-4 shadow-sm border-info">
                <div class="card-header bg-info text-dark">
                    <i class="fa-solid fa-chart-pie me-2"></i>System Quick Stats
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>Registered Users</span>
                        <span class="badge bg-secondary"><?= count(get_all_users()) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>Active Modules</span>
                        <span class="badge bg-success"><?= $activeCount ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Database Engine</span>
                        <span class="badge bg-dark">MySQL/PDO</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
let customizeMode = false;

function toggleWidgetCustomizeMode() {
    customizeMode = !customizeMode;
    const toolbar = document.getElementById('customizeToolbar');
    const customBars = document.querySelectorAll('.widget-custombar');
    const items = document.querySelectorAll('.widget-item');
    const btnText = document.getElementById('customizeBtnText');

    if (customizeMode) {
        toolbar.classList.remove('d-none');
        btnText.textContent = 'Exit Customizer Mode';
        customBars.forEach(bar => bar.classList.remove('d-none'));

        // Temporarily make hidden items semi-visible with opacity in customizer mode
        items.forEach(item => {
            if (item.getAttribute('data-is-visible') === '0') {
                item.style.setProperty('display', 'block', 'important');
                item.style.opacity = '0.5';
            }
        });
    } else {
        toolbar.classList.add('d-none');
        btnText.textContent = 'Customize Dashboard';
        customBars.forEach(bar => bar.classList.add('d-none'));

        // Restore hidden status
        items.forEach(item => {
            if (item.getAttribute('data-is-visible') === '0') {
                item.style.setProperty('display', 'none', 'important');
                item.style.opacity = '1';
            } else {
                item.style.opacity = '1';
            }
        });
    }
}

function updateWidgetWidth(selectElem) {
    const item = selectElem.closest('.widget-item');
    const newWidth = selectElem.value;

    // Remove old col classes
    item.classList.remove('col-12', 'col-lg-8', 'col-lg-6', 'col-lg-4');
    item.classList.add(newWidth);
    item.setAttribute('data-width-class', newWidth);
}

function toggleWidgetVisibility(btn) {
    const item = btn.closest('.widget-item');
    const currentVis = item.getAttribute('data-is-visible');
    const badge = item.querySelector('.visibility-badge');
    const icon = btn.querySelector('i');
    const textSpan = btn.querySelector('span');

    if (currentVis === '1') {
        item.setAttribute('data-is-visible', '0');
        badge.textContent = 'Hidden';
        badge.className = 'badge bg-danger visibility-badge';
        icon.className = 'fa-solid fa-eye text-success';
        textSpan.textContent = 'Show';
        if (customizeMode) {
            item.style.opacity = '0.5';
        }
    } else {
        item.setAttribute('data-is-visible', '1');
        badge.textContent = 'Visible';
        badge.className = 'badge bg-secondary visibility-badge';
        icon.className = 'fa-solid fa-eye-slash text-warning';
        textSpan.textContent = 'Hide';
        item.style.opacity = '1';
    }
}

function moveWidgetUp(btn) {
    const item = btn.closest('.widget-item');
    const prev = item.previousElementSibling;
    if (prev && prev.classList.contains('widget-item')) {
        item.parentNode.insertBefore(item, prev);
    }
}

function moveWidgetDown(btn) {
    const item = btn.closest('.widget-item');
    const next = item.nextElementSibling;
    if (next && next.classList.contains('widget-item')) {
        item.parentNode.insertBefore(next, item);
    }
}

function saveWidgetPreferences(actionType = 'save_widget_preferences') {
    const container = document.getElementById('dashboardWidgetsContainer');
    const items = container.querySelectorAll('.widget-item');
    const preferences = [];

    items.forEach((item, index) => {
        preferences.push({
            widget_key: item.getAttribute('data-widget-key'),
            is_visible: parseInt(item.getAttribute('data-is-visible') || '1'),
            width_class: item.getAttribute('data-width-class') || 'col-12',
            sort_order: (index + 1) * 10
        });
    });

    const csrfToken = '<?= get_csrf_token() ?>';

    const formData = new FormData();
    formData.append('action', actionType);
    formData.append('csrf_token', csrfToken);
    formData.append('preferences', JSON.stringify(preferences));

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const msg = (actionType === 'save_default_widget_preferences')
                ? 'System default dashboard layout saved successfully!'
                : 'Your personal dashboard layout saved successfully!';
            alert(msg);
            window.location.reload();
        } else {
            alert('Failed to save preferences: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Error communicating with server: ' + err);
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
