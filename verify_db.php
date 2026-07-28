<?php
// verify_db.php - Diagnostic Database Verification Portal
require_once 'header.php';
require_once 'models.php';

// Access Control: Only Global Administrators can access verify_db.php
if (!has_permission('manage_departments')) {
    echo "<div class='alert alert-danger p-4'><i class='fa-solid fa-circle-exclamation me-2'></i>Access Denied: Only Global Administrators are authorized to access the Database Diagnostic Portal.</div>";
    require_once 'footer.php';
    exit;
}

$db_verified = true;
$diagnostics = [];

// Expected table structure definition
$expected_schema = [
    'users' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'zabbix_userid' => ['type' => 'bigint', 'key' => 'UNI'],
        'username' => ['type' => 'varchar', 'key' => 'UNI'],
        'name' => ['type' => 'varchar'],
        'surname' => ['type' => 'varchar'],
        'email' => ['type' => 'varchar'],
        'azure_oid' => ['type' => 'varchar', 'key' => 'UNI'],
        'display_name' => ['type' => 'varchar'],
        'auto_provisioned' => ['type' => 'tinyint'],
    ],
    'departments' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'name' => ['type' => 'varchar', 'key' => 'UNI'],
        'manager_user_id' => ['type' => 'int'],
        'noc_mode' => ['type' => 'tinyint'],
    ],
    'department_users' => [
        'department_id' => ['type' => 'int', 'key' => 'PRI'],
        'user_id' => ['type' => 'int', 'key' => 'PRI'],
    ],
    'schedule_slots' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'department_id' => ['type' => 'int'],
        'user_id' => ['type' => 'int'],
        'start_time' => ['type' => 'datetime'],
        'end_time' => ['type' => 'datetime'],
    ],
    'overrides' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'department_id' => ['type' => 'int'],
        'user_id' => ['type' => 'int'],
        'start_time' => ['type' => 'datetime'],
        'end_time' => ['type' => 'datetime'],
        'description' => ['type' => 'varchar'],
    ],
    'roles' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'role_name' => ['type' => 'varchar', 'key' => 'UNI'],
    ],
    'permissions' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'permission_name' => ['type' => 'varchar', 'key' => 'UNI'],
    ],
    'user_roles' => [
        'user_id' => ['type' => 'int', 'key' => 'PRI'],
        'role_id' => ['type' => 'int', 'key' => 'PRI'],
    ],
    'default_roles' => [
        'role_id' => ['type' => 'int', 'key' => 'PRI'],
    ],
    'azure_group_roles' => [
        'azure_group_name' => ['type' => 'varchar', 'key' => 'PRI'],
        'role_id' => ['type' => 'int', 'key' => 'PRI'],
    ],
    'role_permissions' => [
        'role_id' => ['type' => 'int', 'key' => 'PRI'],
        'permission_id' => ['type' => 'int', 'key' => 'PRI'],
    ],
    'user_permissions' => [
        'user_id' => ['type' => 'int', 'key' => 'PRI'],
        'permission_id' => ['type' => 'int', 'key' => 'PRI'],
    ],
    'denied_permissions' => [
        'user_id' => ['type' => 'int', 'key' => 'PRI'],
        'permission_id' => ['type' => 'int', 'key' => 'PRI'],
    ],
    'trade_requests' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'department_id' => ['type' => 'int'],
        'proposing_user_id' => ['type' => 'int'],
        'accepting_user_id' => ['type' => 'int'],
        'offered_slot_id' => ['type' => 'int'],
        'counter_slot_id' => ['type' => 'int'],
        'status' => ['type' => 'varchar'],
    ],
    'audit_logs' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'user_id' => ['type' => 'int'],
        'username' => ['type' => 'varchar'],
        'action' => ['type' => 'varchar'],
        'details' => ['type' => 'text'],
        'ip_address' => ['type' => 'varchar'],
    ],
    'settings' => [
        'setting_key' => ['type' => 'varchar', 'key' => 'PRI'],
        'setting_value' => ['type' => 'varchar'],
    ],
    'noc_business_hours' => [
        'day_of_week' => ['type' => 'int', 'key' => 'PRI'],
        'start_time' => ['type' => 'time'],
        'end_time' => ['type' => 'time'],
    ],
    'department_zabbix_groups' => [
        'department_id' => ['type' => 'int', 'key' => 'PRI'],
        'zabbix_usrgrp_id' => ['type' => 'bigint', 'key' => 'PRI'],
        'last_oncall_userid' => ['type' => 'bigint'],
    ],
    'commportal_accounts' => [
        'id' => ['type' => 'int', 'key' => 'PRI'],
        'department_id' => ['type' => 'int'],
        'phone_number' => ['type' => 'varchar'],
        'password' => ['type' => 'varchar'],
        'ext' => ['type' => 'varchar'],
        'last_forwarded_phone' => ['type' => 'varchar'],
    ]
];

try {
    $db = get_oncall_db();

    // Fetch existing tables
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($expected_schema as $table_name => $columns) {
        if (!in_array($table_name, $tables)) {
            $db_verified = false;
            $diagnostics[] = [
                'table' => $table_name,
                'status' => 'MISSING',
                'details' => ["Table '$table_name' is missing entirely from the database."]
            ];
            continue;
        }

        // Fetch actual table description
        $desc_stmt = $db->query("DESCRIBE `$table_name`");
        $actual_cols = $desc_stmt->fetchAll();

        $actual_col_map = [];
        foreach ($actual_cols as $col) {
            $actual_col_map[$col['Field']] = $col;
        }

        $table_errors = [];

        foreach ($columns as $col_name => $rules) {
            if (!isset($actual_col_map[$col_name])) {
                $table_errors[] = "Column '$col_name' is missing.";
                continue;
            }

            $actual = $actual_col_map[$col_name];

            // Check data type matches expected prefix
            if (isset($rules['type'])) {
                if (stripos($actual['Type'], $rules['type']) === false) {
                    $table_errors[] = "Column '$col_name' type is wrong. Expected standard '{$rules['type']}', got actual type '{$actual['Type']}'.";
                }
            }

            // Check keys (Primary, Unique)
            if (isset($rules['key'])) {
                if ($rules['key'] === 'PRI' && $actual['Key'] !== 'PRI') {
                    $table_errors[] = "Column '$col_name' is expected to be a PRIMARY KEY, but actual key is '{$actual['Key']}'.";
                } elseif ($rules['key'] === 'UNI' && $actual['Key'] !== 'UNI' && $actual['Key'] !== 'PRI') {
                    $table_errors[] = "Column '$col_name' is expected to be a UNIQUE KEY, but actual key is '{$actual['Key']}'.";
                }
            }
        }

        if (!empty($table_errors)) {
            $db_verified = false;
            $diagnostics[] = [
                'table' => $table_name,
                'status' => 'ERROR',
                'details' => $table_errors
            ];
        } else {
            $diagnostics[] = [
                'table' => $table_name,
                'status' => 'HEALTHY',
                'details' => []
            ];
        }
    }

} catch (Exception $e) {
    $db_verified = false;
    $diagnostics[] = [
        'table' => 'DATABASE_CONNECTION',
        'status' => 'CRITICAL',
        'details' => ["Could not connect or run queries on database: " . $e->getMessage()]
    ];
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-stethoscope text-primary me-2"></i>Database Diagnostic Portal</h1>
        <p class="text-muted">Validate schema integrity, ensure structural alignment with required definitions, and troubleshoot table discrepancies.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <?php if ($db_verified): ?>
            <span class="badge bg-success p-3 fs-6"><i class="fa-solid fa-circle-check me-2"></i>Database Schema Healthy</span>
        <?php else: ?>
            <span class="badge bg-danger p-3 fs-6"><i class="fa-solid fa-triangle-exclamation me-2"></i>Database Schema Mismatch</span>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header bg-white">
                <i class="fa-solid fa-list-check me-2 text-secondary"></i>Structural Diagnostics Report
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 25%;">Table Name</th>
                                <th style="width: 20%;">Diagnostic Status</th>
                                <th style="width: 55%;">Verification Errors & Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($diagnostics as $diag): ?>
                                <?php
                                $status_class = '';
                                $badge_class = '';
                                if ($diag['status'] === 'HEALTHY') {
                                    $status_class = 'table-success-light';
                                    $badge_class = 'bg-success';
                                } elseif ($diag['status'] === 'MISSING') {
                                    $status_class = 'table-danger-light';
                                    $badge_class = 'bg-danger';
                                } elseif ($diag['status'] === 'ERROR') {
                                    $status_class = 'table-warning-light';
                                    $badge_class = 'bg-warning text-dark';
                                } else {
                                    $status_class = 'table-danger-light';
                                    $badge_class = 'bg-dark text-white';
                                }
                                ?>
                                <tr class="<?= $status_class ?>">
                                    <td class="fw-bold">
                                        <code><?= htmlspecialchars($diag['table']) ?></code>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badge_class ?> p-2 fs-7">
                                            <i class="fa-solid <?= $diag['status'] === 'HEALTHY' ? 'fa-check-double' : 'fa-circle-exclamation' ?> me-1"></i>
                                            <?= htmlspecialchars($diag['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (empty($diag['details'])): ?>
                                            <span class="text-success small"><i class="fa-solid fa-check me-1"></i> All columns, data types, and unique keys match system specifications perfectly.</span>
                                        <?php else: ?>
                                            <ul class="text-danger small mb-0 ps-3">
                                                <?php foreach ($diag['details'] as $err): ?>
                                                    <li><?= htmlspecialchars($err) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
