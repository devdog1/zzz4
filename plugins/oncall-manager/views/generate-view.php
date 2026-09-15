<?php
// views/generate-view.php - 365-Day Rotation Auto-Generator View

function oncall_render_generate_page() {
    $departments = oncall_get_all_departments();
    $selected_dept = $_GET['department_id'] ?? ($departments[0]['id'] ?? 1);
    $dept_users = oncall_get_department_users($selected_dept);

    $msg = $_GET['msg'] ?? null;
    $err = $_GET['err'] ?? null;
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Shift Rotation Generator</h1>
            <p class="text-muted mb-0">Auto-generate 365 days of weekly rotational shifts based on shift templates</p>
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
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-gear-wide-connected me-2"></i>Department Selection</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="<?php echo url_for('oncall_generate'); ?>">
                        <input type="hidden" name="route" value="oncall_generate">
                        <label for="department_id" class="form-label fw-bold">Select Department:</label>
                        <select name="department_id" id="department_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo ($selected_dept == $dept['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0"><i class="bi bi-magic me-2"></i>Generate Annual Schedule</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo url_for('oncall_generate'); ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="generate_schedule">
                        <input type="hidden" name="department_id" value="<?php echo (int)$selected_dept; ?>">

                        <div class="mb-3">
                            <label for="start_date" class="form-label fw-bold">Rotation Start Monday</label>
                            <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            <div class="form-text">The generator will align the rotation to the Monday of the selected week.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Participating Rotation Users (Order Matters):</label>
                            <?php if (empty($dept_users)): ?>
                                <div class="alert alert-warning">No users assigned to this department. Please add users in Department Management first.</div>
                            <?php else: ?>
                                <div class="row g-2">
                                    <?php foreach ($dept_users as $u): ?>
                                        <div class="col-md-6">
                                            <div class="form-check border rounded p-2 ps-4">
                                                <input class="form-check-input" type="checkbox" name="user_ids[]" value="<?php echo $u['id']; ?>" id="gen_u_<?php echo $u['id']; ?>" checked>
                                                <label class="form-check-label" for="gen_u_<?php echo $u['id']; ?>">
                                                    <?php echo htmlspecialchars($u['display_name']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary" <?php echo empty($dept_users) ? 'disabled' : ''; ?>>
                            <i class="bi bi-play-circle me-1"></i> Generate 365-Day Schedule
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}
