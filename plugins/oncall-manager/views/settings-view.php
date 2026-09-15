<?php
// views/settings-view.php - Global Plugin Settings & NOC Business Hours View

function oncall_render_settings_page() {
    $pdb = oncall_get_pdb();
    $tb_noc = $pdb->getTableName('noc_business_hours');

    $zabbix_url = oncall_get_setting('zabbix_api_url', 'http://127.0.0.1/zabbix/api_jsonrpc.php');
    $zabbix_token = oncall_get_setting('zabbix_api_token', '');
    $zabbix_domain = oncall_get_setting('zabbix_sync_domain', 'example.com');

    $noc_stmt = $pdb->query("SELECT * FROM {$tb_noc} ORDER BY day_of_week ASC");
    $noc_hours = [];
    foreach ($noc_stmt->fetchAll() as $row) {
        $noc_hours[$row['day_of_week']] = $row;
    }

    $days_map = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday'
    ];

    $msg = $_GET['msg'] ?? null;
    $err = $_GET['err'] ?? null;
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">On-Call Manager Settings</h1>
            <p class="text-muted mb-0">Configure Zabbix API integration credentials, user email domain, and default NOC Business Hours</p>
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
        <div class="col-lg-6">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-diagram-3 me-2"></i>Zabbix API Integration Credentials</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo url_for('oncall_settings'); ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="save_zabbix_settings">

                        <div class="mb-3">
                            <label for="zabbix_api_url" class="form-label fw-bold">Zabbix API Endpoint URL</label>
                            <input type="url" name="zabbix_api_url" id="zabbix_api_url" class="form-control" value="<?php echo htmlspecialchars($zabbix_url); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="zabbix_api_token" class="form-label fw-bold">Zabbix API Auth Token</label>
                            <input type="password" name="zabbix_api_token" id="zabbix_api_token" class="form-control" value="<?php echo htmlspecialchars($zabbix_token); ?>" placeholder="Enter Zabbix API bearer token">
                        </div>

                        <div class="mb-3">
                            <label for="zabbix_sync_domain" class="form-label fw-bold">Zabbix User Sync Email Domain</label>
                            <input type="text" name="zabbix_sync_domain" id="zabbix_sync_domain" class="form-control" value="<?php echo htmlspecialchars($zabbix_domain); ?>" placeholder="e.g. example.com">
                            <div class="form-text">Domain appended to Zabbix usernames during sync to form their full email address and local username.</div>
                        </div>

                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Zabbix Settings</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0"><i class="bi bi-clock me-2"></i>NOC Business Hours Overlay Schedule</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo url_for('oncall_settings'); ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="save_noc_hours">

                        <p class="text-muted small">Departments with "NOC Mode" enabled will auto-overlay these hours on top of regular shifts.</p>

                        <?php foreach ($days_map as $dow => $day_name): ?>
                            <?php
                            $start = $noc_hours[$dow]['start_time'] ?? '08:00:00';
                            $end = $noc_hours[$dow]['end_time'] ?? '17:00:00';
                            ?>
                            <div class="row g-2 mb-2 align-items-center">
                                <div class="col-3 fw-bold"><?php echo $day_name; ?></div>
                                <div class="col-4">
                                    <input type="time" name="noc_hours[<?php echo $dow; ?>][start]" class="form-control form-control-sm" value="<?php echo date('H:i', strtotime($start)); ?>">
                                </div>
                                <div class="col-4">
                                    <input type="time" name="noc_hours[<?php echo $dow; ?>][end]" class="form-control form-control-sm" value="<?php echo date('H:i', strtotime($end)); ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <button type="submit" class="btn btn-success mt-3"><i class="bi bi-save me-1"></i> Save NOC Business Hours</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}
