<?php
// admin-nav.php - Main Navigation Menu Manager (Rearrange, Hide/Show, Custom Labels)
require_once __DIR__ . '/functions.php';

// Enforce admin permission check
if (!has_permission('manage_settings')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. You do not have permission to manage system settings.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$msg = '';
$err = '';

// Handle menu configuration update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_nav_config') {
        $items = $_POST['items'] ?? [];
        $nav_config = [];

        foreach ($items as $item_id => $cfg) {
            $nav_config[$item_id] = [
                'order' => isset($cfg['order']) ? (int)$cfg['order'] : 10,
                'visible' => isset($cfg['visible']) ? 1 : 0,
                'label' => trim($cfg['label'] ?? '')
            ];
        }

        set_setting('nav_menu_config', json_encode($nav_config));
        log_action('NAV_MENU_CONFIG_SAVE', ['items_count' => count($nav_config)]);
        $msg = "Navigation menu order and visibility settings saved successfully!";
    } elseif ($action === 'reset_nav_config') {
        set_setting('nav_menu_config', '{}');
        log_action('NAV_MENU_CONFIG_RESET', []);
        $msg = "Navigation menu settings reset to default plugin orders.";
    }
}

// Fetch dynamic plugin and core navigation links
$raw_nav_links = [];
$raw_nav_links = $pluginManager->applyFilters('theme_nav_links', $raw_nav_links);

$nav_config_json = get_setting('nav_menu_config', '{}');
$nav_config = json_decode($nav_config_json, true) ?: [];

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-bars-staggered text-primary me-2"></i>Navigation Menu Manager</h1>
        <p class="text-muted">Organize the main navigation bar. Rearrange the positions of active module items, override their labels, or hide specific links.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <form method="POST" class="d-inline-block" onsubmit="return confirm('Reset navigation menu to default plugin orders?');">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="reset_nav_config">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-rotate-left me-1"></i>Reset to Defaults
            </button>
        </form>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show text-start"><i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show text-start"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row text-start">
    <div class="col-lg-12">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-list-ol me-2"></i>Active Top-Level Navigation Nodes</span>
                <span class="badge bg-info"><?= count($raw_nav_links) ?> Items Discovered</span>
            </div>
            <div class="card-body">
                <?php if (empty($raw_nav_links)): ?>
                    <p class="text-muted p-3 mb-0">No dynamic navigation links currently registered by active plugins.</p>
                <?php else: ?>
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="save_nav_config">

                        <div class="table-responsive mb-3">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 100px;">Sort Order</th>
                                        <th style="width: 100px;">Visible</th>
                                        <th>Original Label</th>
                                        <th>Custom Label Override</th>
                                        <th>Sub-Items / Children</th>
                                        <th>Route / Identifier</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $default_order = 10;
                                    foreach ($raw_nav_links as $link):
                                        $item_id = !empty($link['route']) ? $link['route'] : preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($link['label']));
                                        $cfg = $nav_config[$item_id] ?? [];
                                        $order_val = isset($cfg['order']) ? (int)$cfg['order'] : $default_order;
                                        $visible_val = isset($cfg['visible']) ? (int)$cfg['visible'] : 1;
                                        $label_val = $cfg['label'] ?? '';
                                        $default_order += 10;
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="number" name="items[<?= htmlspecialchars($item_id) ?>][order]" class="form-control form-control-sm text-center" value="<?= $order_val ?>" min="1" step="1" required>
                                            </td>
                                            <td>
                                                <div class="form-check form-switch d-flex justify-content-center">
                                                    <input class="form-check-input" type="checkbox" name="items[<?= htmlspecialchars($item_id) ?>][visible]" value="1" <?= $visible_val ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td>
                                                <strong class="text-dark">
                                                    <?php if (!empty($link['icon'])): ?>
                                                        <i class="<?= htmlspecialchars($link['icon']) ?> me-1 text-secondary"></i>
                                                    <?php endif; ?>
                                                    <?= htmlspecialchars($link['label']) ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <input type="text" name="items[<?= htmlspecialchars($item_id) ?>][label]" class="form-control form-control-sm" value="<?= htmlspecialchars($label_val) ?>" placeholder="e.g. <?= htmlspecialchars($link['label']) ?>">
                                            </td>
                                            <td>
                                                <?php if (!empty($link['children'])): ?>
                                                    <span class="badge bg-secondary"><?= count($link['children']) ?> sub-links</span>
                                                    <small class="text-muted d-block">
                                                        <?= htmlspecialchars(implode(', ', array_column($link['children'], 'label'))) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted small">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?= htmlspecialchars($item_id) ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Save Navigation Menu Settings
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
