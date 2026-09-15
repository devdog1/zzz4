<?php
// views/telephony-view.php - CommPortal Telephony & Call Forwarding View

function oncall_render_telephony_page() {
    $pdb = oncall_get_pdb();
    $tb_accounts = $pdb->getTableName('commportal_accounts');
    $accounts = $pdb->query("SELECT * FROM {$tb_accounts}")->fetchAll();

    $msg = $_GET['msg'] ?? null;
    $err = $_GET['err'] ?? null;
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">CommPortal Telephony Forwarding</h1>
            <p class="text-muted mb-0">Manage Metaswitch CommPortal lines and on-call forwarding mappings</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($err); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-telephone-plus me-2"></i>Register Line</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo url_for('oncall_telephony'); ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="save_account">

                        <div class="mb-3">
                            <label for="account_number" class="form-label fw-bold">Line Phone Number</label>
                            <input type="text" name="account_number" id="account_number" class="form-control" placeholder="e.g. +1-800-555-0199" required>
                        </div>

                        <div class="mb-3">
                            <label for="commportal_pass" class="form-label fw-bold">CommPortal API Secret</label>
                            <input type="password" name="commportal_pass" id="commportal_pass" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label for="forwarding_number" class="form-label fw-bold">Default Forward Number</label>
                            <input type="text" name="forwarding_number" id="forwarding_number" class="form-control" placeholder="e.g. +1-555-0100">
                        </div>

                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i> Register Line</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0"><i class="bi bi-telephone-outbound me-2"></i>Monitored Lines</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Line Number</th>
                                    <th>Current Forwarding Target</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($accounts)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No CommPortal accounts configured.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($accounts as $acc): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($acc['account_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($acc['forwarding_number'] ?: 'Unassigned'); ?></td>
                                            <td><span class="badge bg-success">Active</span></td>
                                            <td class="text-end">
                                                <form method="POST" action="<?php echo url_for('oncall_telephony'); ?>" onsubmit="return confirm('Delete this account line?');" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_account">
                                                    <input type="hidden" name="id" value="<?php echo $acc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
