<?php
// admin-logs.php - Standalone Action Audit Trail with Sorting and Filters
require_once __DIR__ . '/functions.php';

// Enforce admin permission
if (!has_permission('manage_settings')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$db = get_db_connection();

// Read filter parameters
$filter_action = trim($_GET['action_filter'] ?? '');
$filter_username = trim($_GET['user_filter'] ?? '');
$sort_order = ($_GET['sort_order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// Build dynamic query
$sql = "
    SELECT al.*, u.display_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
";

$conditions = [];
$params = [];

if ($filter_action !== '') {
    $conditions[] = "al.action LIKE ?";
    $params[] = '%' . $filter_action . '%';
}

if ($filter_username !== '') {
    $conditions[] = "(al.username LIKE ? OR u.display_name LIKE ?)";
    $params[] = '%' . $filter_username . '%';
    $params[] = '%' . $filter_username . '%';
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_sql = $sql . " ORDER BY al.timestamp {$sort_order}";
    try {
        $stmt = $db->prepare($export_sql);
        $stmt->execute($params);
        $export_logs = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Timestamp', 'Action', 'User ID', 'Username', 'Display Name', 'IP Address', 'Details']);

        foreach ($export_logs as $row) {
            fputcsv($output, [
                $row['id'],
                $row['timestamp'],
                $row['action'],
                $row['user_id'],
                $row['username'],
                $row['display_name'],
                $row['ip_address'],
                $row['details']
            ]);
        }
        fclose($output);
        exit;
    } catch (Exception $e) {
        // Fallback if export fails
    }
}

$sql .= " ORDER BY al.timestamp {$sort_order} LIMIT 100";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (Exception $e) {
    $logs = [];
    $err = $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-receipt text-primary me-2"></i>Action Audit Trail Logs</h1>
        <p class="text-muted">Inspect, filter, and audit critical user and plugin configuration transactions on the portal.</p>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card shadow-sm mb-4 text-start">
    <div class="card-header bg-light">
        <i class="fa-solid fa-filter me-1 text-secondary"></i>Search & Filter Parameters
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Action Name</label>
                <input type="text" name="action_filter" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_action) ?>" placeholder="e.g. SET_SETTING">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">User / Display Name</label>
                <input type="text" name="user_filter" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_username) ?>" placeholder="e.g. admin">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Sort Order</label>
                <select name="sort_order" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="desc" <?= $sort_order === 'DESC' ? 'selected' : '' ?>>Newest First (Descending)</option>
                    <option value="asc" <?= $sort_order === 'ASC' ? 'selected' : '' ?>>Oldest First (Ascending)</option>
                </select>
            </div>
            <div class="col-12 text-end">
                <?php
                $export_query = $_GET;
                $export_query['export'] = 'csv';
                $export_url = 'admin-logs.php?' . http_build_query($export_query);
                ?>
                <a href="admin-logs.php" class="btn btn-sm btn-outline-secondary me-2"><i class="fa-solid fa-arrows-rotate me-1"></i>Reset Filter</a>
                <a href="<?= htmlspecialchars($export_url) ?>" class="btn btn-sm btn-outline-success me-2"><i class="fa-solid fa-file-csv me-1"></i>Export to CSV</a>
                <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-magnifying-glass me-1"></i>Apply Filters</button>
            </div>
        </form>
    </div>
</div>

<!-- Logs list -->
<div class="card shadow-sm text-start">
    <div class="card-header bg-dark text-white">
        <i class="fa-solid fa-list-check me-1"></i>Execution Log Directory
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <p class="text-muted p-4 mb-0">No historical action audit logs match the specified search parameters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Log Timestamp</th>
                            <th>Action Taken</th>
                            <th>Initiator Details</th>
                            <th>IP Address</th>
                            <th>Action Metadata</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($l['timestamp']) ?></code></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($l['action']) ?></span></td>
                                <td>
                                    <strong><?= htmlspecialchars($l['display_name'] ?? 'System') ?></strong>
                                    <?php if ($l['username']): ?>
                                        <br><small class="text-muted">ID: <code><?= htmlspecialchars($l['username']) ?></code></small>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($l['ip_address']) ?></code></td>
                                <td>
                                    <pre class="mb-0 text-xs text-dark bg-light p-1 rounded font-monospace" style="max-height: 80px; overflow-y: auto; max-width: 400px; white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($l['details']) ?></pre>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
