<?php
// logs.php - Audit Logs & Verbose Action Trail (Admin Only)
require_once 'header.php';
require_once 'models.php';

// Access Control: Only Global Administrators can view audit logs
if (!has_permission('manage_departments')) {
    echo "<div class='alert alert-danger p-4'><i class='fa-solid fa-circle-exclamation me-2'></i>Access Denied: Only Global Administrators are authorized to view the system audit trail.</div>";
    require_once 'footer.php';
    exit;
}

$logs = get_audit_logs();
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-receipt text-primary me-2"></i>System Audit Trail</h1>
        <p class="text-muted">Verbose database logging of user, group manager, and global administrator actions.</p>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-receipt me-2 text-secondary"></i>Audit History</span>
        <span class="badge bg-secondary"><?= count($logs) ?> logs recorded</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <p class="text-muted small text-center my-5">No audit logs recorded yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-top mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 15%;">Timestamp</th>
                            <th style="width: 15%;">Actor</th>
                            <th style="width: 20%;">Action</th>
                            <th style="width: 10%;">IP Address</th>
                            <th style="width: 40%;">Verbose Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <code><?= htmlspecialchars($log['created_at']) ?></code>
                                </td>
                                <td>
                                    <?php if ($log['user_id']): ?>
                                        <strong><?= htmlspecialchars($log['name'] . ' ' . $log['surname']) ?></strong><br>
                                        <small class="text-muted">(@<?= htmlspecialchars($log['username']) ?>)</small>
                                    <?php else: ?>
                                        <span class="text-muted italic">System / Guest</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-dark font-monospace"><?= htmlspecialchars($log['action']) ?></span>
                                </td>
                                <td>
                                    <code><?= htmlspecialchars($log['ip_address']) ?></code>
                                </td>
                                <td>
                                    <pre class="bg-light p-2 rounded mb-0 text-dark" style="font-size: 0.75rem; max-height: 150px; overflow-y: auto;"><?= htmlspecialchars($log['details']) ?></pre>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
