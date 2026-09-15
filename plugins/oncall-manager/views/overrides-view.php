<?php
// views/overrides-view.php - Manual Overrides Management View

function oncall_render_overrides_page() {
    $departments = oncall_get_all_departments();
    $db = get_db_connection();
    $all_users = $db->query("SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS display_name FROM users ORDER BY display_name ASC")->fetchAll();
    $overrides = oncall_get_overrides();

    $msg = $_GET['msg'] ?? null;
    $err = $_GET['err'] ?? null;
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Schedule Overrides</h1>
            <p class="text-muted mb-0">Inject manual overrides into rotation schedules with highest priority precedence</p>
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
                    <h5 class="card-title mb-0"><i class="bi bi-calendar-plus me-2"></i>Add New Override</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo url_for('oncall_overrides'); ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create_override">

                        <div class="mb-3">
                            <label for="department_id" class="form-label fw-bold">Department</label>
                            <select name="department_id" id="department_id" class="form-select" required>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="user_id" class="form-label fw-bold">On-Call Person</label>
                            <select name="user_id" id="user_id" class="form-select" required>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['display_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="start_time" class="form-label fw-bold">Start Time</label>
                            <input type="datetime-local" name="start_time" id="start_time" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label for="end_time" class="form-label fw-bold">End Time</label>
                            <input type="datetime-local" name="end_time" id="end_time" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label fw-bold">Reason / Note</label>
                            <input type="text" name="description" id="description" class="form-control" placeholder="e.g. Sick Leave Replacement">
                        </div>

                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i> Save Override</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Existing Schedule Overrides</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Department</th>
                                    <th>User</th>
                                    <th>Start Time</th>
                                    <th>End Time</th>
                                    <th>Note</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($overrides)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">No schedule overrides currently registered.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($overrides as $ov): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($ov['department_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($ov['display_name']); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($ov['start_time'])); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($ov['end_time'])); ?></td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($ov['description'] ?: 'N/A'); ?></small></td>
                                            <td class="text-end">
                                                <form method="POST" action="<?php echo url_for('oncall_overrides'); ?>" onsubmit="return confirm('Delete this override?');" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_override">
                                                    <input type="hidden" name="id" value="<?php echo $ov['id']; ?>">
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
