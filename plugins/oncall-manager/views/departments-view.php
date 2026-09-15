<?php
// views/departments-view.php - Department & Member Management View

function oncall_render_departments_page() {
    $departments = oncall_get_all_departments();
    $db = get_db_connection();
    $all_users = $db->query("SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS display_name FROM users ORDER BY display_name ASC")->fetchAll();

    $selected_dept_id = $_GET['id'] ?? null;
    $selected_dept = null;
    $dept_members = [];
    $dept_zabbix_groups = [];

    if ($selected_dept_id) {
        $selected_dept = oncall_get_department_by_id($selected_dept_id);
        if ($selected_dept) {
            $members = oncall_get_department_users($selected_dept_id);
            $dept_members = array_column($members, 'id');
            $dept_zabbix_groups = oncall_get_department_zabbix_groups($selected_dept_id);
        }
    }

    $msg = $_GET['msg'] ?? null;
    $err = $_GET['err'] ?? null;
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Department Management</h1>
            <p class="text-muted mb-0">Configure department roster, department managers, Zabbix Group auto-assignments, and NOC modes</p>
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
        <div class="col-lg-5">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-building-add me-2"></i>Create New Department</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo url_for('oncall_departments'); ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create_department">

                        <div class="mb-3">
                            <label for="name" class="form-label fw-bold">Department Name</label>
                            <input type="text" name="name" id="name" class="form-control" placeholder="e.g. Network Operations" required>
                        </div>

                        <div class="mb-3">
                            <label for="manager_user_id" class="form-label fw-bold">Department Manager</label>
                            <select name="manager_user_id" id="manager_user_id" class="form-select">
                                <option value="">-- Select Manager --</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['display_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i> Add Department</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0"><i class="bi bi-buildings me-2"></i>Department Roster</h5>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (empty($departments)): ?>
                        <div class="list-group-item text-muted text-center py-3">No departments created yet.</div>
                    <?php else: ?>
                        <?php foreach ($departments as $d): ?>
                            <a href="<?php echo url_for('oncall_departments') . '&id=' . $d['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo ($selected_dept_id == $d['id']) ? 'active' : ''; ?>">
                                <div>
                                    <strong><?php echo htmlspecialchars($d['name']); ?></strong>
                                    <small class="d-block text-muted">Manager: <?php echo htmlspecialchars($d['manager_name'] ?: 'None'); ?></small>
                                </div>
                                <div>
                                    <?php if (!empty($d['noc_mode'])): ?>
                                        <span class="badge bg-warning text-dark me-1">NOC</span>
                                    <?php endif; ?>
                                    <i class="bi bi-chevron-right"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <?php if ($selected_dept): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Configure: <?php echo htmlspecialchars($selected_dept['name']); ?></h5>
                        <form method="POST" action="<?php echo url_for('oncall_departments'); ?>" onsubmit="return confirm('Delete this department?');" class="d-inline">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_department">
                            <input type="hidden" name="id" value="<?php echo $selected_dept['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete Department</button>
                        </form>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="<?php echo url_for('oncall_departments'); ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="update_department">
                            <input type="hidden" name="id" value="<?php echo $selected_dept['id']; ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="edit_name" class="form-label fw-bold">Department Name</label>
                                    <input type="text" name="name" id="edit_name" class="form-control" value="<?php echo htmlspecialchars($selected_dept['name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_manager" class="form-label fw-bold">Department Manager</label>
                                    <select name="manager_user_id" id="edit_manager" class="form-select">
                                        <option value="">-- Select Manager --</option>
                                        <?php foreach ($all_users as $u): ?>
                                            <option value="<?php echo $u['id']; ?>" <?php echo ($selected_dept['manager_user_id'] == $u['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($u['display_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="noc_mode" value="1" id="noc_mode" <?php echo !empty($selected_dept['noc_mode']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="noc_mode">Enable NOC Mode Business Hours Overlay</label>
                                <div class="form-text">When active, NOC Business Hours will automatically override regular shift rotation slots during business hours.</div>
                            </div>

                            <div class="mb-3">
                                <label for="zabbix_groups" class="form-label fw-bold">Associated Zabbix User Group IDs (Comma-separated)</label>
                                <input type="text" name="zabbix_usrgrp_ids" id="zabbix_groups" class="form-control" value="<?php echo htmlspecialchars(implode(', ', $dept_zabbix_groups)); ?>" placeholder="e.g. 7, 12, 15">
                                <div class="form-text">When the on-call shift changes, the active on-call user's mapped Zabbix ID will be automatically added to these Zabbix groups via API.</div>
                            </div>

                            <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i> Save Settings</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="bi bi-people me-2"></i>Department Members</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="<?php echo url_for('oncall_departments'); ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="update_members">
                            <input type="hidden" name="department_id" value="<?php echo $selected_dept['id']; ?>">

                            <label class="form-label fw-bold">Select Eligible Users for Shift Rotation:</label>
                            <div class="row g-2 mb-3">
                                <?php foreach ($all_users as $u): ?>
                                    <div class="col-md-6">
                                        <div class="form-check border rounded p-2 ps-4">
                                            <input class="form-check-input" type="checkbox" name="user_ids[]" value="<?php echo $u['id']; ?>" id="user_<?php echo $u['id']; ?>" <?php echo in_array($u['id'], $dept_members) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="user_<?php echo $u['id']; ?>">
                                                <?php echo htmlspecialchars($u['display_name']); ?> (<?php echo htmlspecialchars($u['username']); ?>)
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update Member Roster</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="bi bi-arrow-left-circle display-4 d-block mb-3"></i>
                        <h5>Select a department from the left roster to manage settings and members.</h5>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
