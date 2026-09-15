<?php
// dashboard-view.php - Main dashboard view for Sample Manager plugin

if (!has_permission('sample_manager_view_sample_stats')) {
    echo '<div class="alert alert-danger">Access Denied. You need the dynamic permission "sample_manager_view_sample_stats" to access this screen.</div>';
    return;
}

// Handle settings submission inside module with secure CSRF verification
$msg = '';
if (isset($_POST['save_sample_settings'])) {
    validate_csrf();

    if (!has_permission('sample_manager_manage_sample_settings')) {
        echo '<div class="alert alert-danger">Action Denied: You do not have permission sample_manager_manage_sample_settings to write configuration.</div>';
        return;
    }

    $sample_token = trim($_POST['sample_api_token'] ?? '');
    set_setting('sample_manager_api_token', $sample_token);
    log_action('SAMPLE_MANAGER_SETTINGS_SAVE', ['sample_api_token' => '***']);
    $msg = "Sample Settings saved successfully (CSRF Verified)!";
}

$currentToken = get_setting('sample_manager_api_token', 'default_mock_api_token');
?>
<div class="row">
    <div class="col-md-12">
        <h2 class="mb-4"><i class="fa-solid fa-wand-magic-sparkles text-info me-2"></i>Sample Manager Module</h2>
        <p class="text-muted">This module demonstrates custom route execution, database integration using <code>get_setting</code>, <code>set_setting</code>, and custom styling injection.</p>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i> <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="row text-start">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="fa-solid fa-gear me-1"></i>Module Configuration & Settings
            </div>
            <div class="card-body">
                <form method="POST">
                    <!-- Core security anti-forgery field token -->
                    <?php csrf_field(); ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Sample API Secret Token</label>
                        <input type="text" name="sample_api_token" class="form-control" value="<?= htmlspecialchars($currentToken) ?>" required>
                        <div class="form-text">Configure arbitrary setting options stored securely in the core <code>settings</code> table.</div>
                    </div>
                    <button type="submit" name="save_sample_settings" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save Configuration (CSRF Protected)
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm border-info">
            <div class="card-header bg-info text-dark">
                <i class="fa-solid fa-circle-info me-1"></i>Developer Instructions
            </div>
            <div class="card-body">
                <h6>Standardized Module Layout:</h6>
                <ol class="small text-muted mb-0">
                    <li><code>plugin.php</code>: Entry point orchestrator.</li>
                    <li><code>sql/</code>: Database migrations (<code>install.sql</code>, <code>uninstall.sql</code>).</li>
                    <li><code>views/</code>: Standalone view templates for each route.</li>
                    <li><code>tasks/</code>: Individual background task scripts.</li>
                    <li><code>models/</code>: Data models and query wrappers.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
