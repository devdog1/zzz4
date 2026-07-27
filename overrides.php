<?php
// overrides.php - View, Create, Edit, Delete Overrides
require_once 'header.php';
require_once 'models.php';

$message = '';
$error = '';

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

$departments = get_all_departments();
$all_users = get_all_users();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_override'])) {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $description = $_POST['description'] ?? '';

        // Clean datetime format for mysql
        $start_time = str_replace('T', ' ', $start_time);
        $end_time = str_replace('T', ' ', $end_time);

        if (!can_manage_department($dept_id)) {
            $error = 'Unauthorized: Only the designated Department Manager or Admin can create/edit overrides for this department.';
        } elseif (!$dept_id || !$user_id || empty($start_time) || empty($end_time)) {
            $error = 'All fields except description are required.';
        } elseif (strtotime($end_time) <= strtotime($start_time)) {
            $error = 'End time must be after start time.';
        } else {
            try {
                if ($action === 'new') {
                    create_override($dept_id, $user_id, $start_time, $end_time, $description);
                    $message = 'Manual override created successfully!';
                    $action = 'list';
                } elseif ($action === 'edit' && $id) {
                    update_override($id, $dept_id, $user_id, $start_time, $end_time, $description);
                    $message = 'Manual override updated successfully!';
                    $action = 'list';
                }
            } catch (Exception $e) {
                $error = 'Error saving override: ' . $e->getMessage();
            }
        }
    }
}

// Handle delete
if ($action === 'delete' && $id) {
    $override_data = get_override_by_id($id);
    if ($override_data && !can_manage_department($override_data['department_id'])) {
        $error = 'Unauthorized: Only the designated Department Manager or Admin can delete overrides.';
    } else {
        try {
            delete_override($id);
            $message = 'Manual override deleted successfully!';
        } catch (Exception $e) {
            $error = 'Failed to delete override: ' . $e->getMessage();
        }
    }
    $action = 'list';
}

// Fetch single override details for editing
$override_data = null;
if ($action === 'edit' && $id) {
    $override_data = get_override_by_id($id);
    if (!$override_data) {
        $error = 'Override not found.';
        $action = 'list';
    } elseif (!can_manage_department($override_data['department_id'])) {
        $error = 'Unauthorized: You cannot edit this override.';
        $action = 'list';
        $override_data = null;
    }
}

// Fetch all overrides
$overrides = get_overrides();
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-circle-exclamation text-primary me-2"></i>Schedule Overrides</h1>
        <p class="text-muted">Create manual overrides for any date and time range to handle swaps, leaves, or sickness cover.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <?php if ($action === 'list'): ?>
            <a href="overrides.php?action=new" class="btn btn-primary">
                <i class="fa-solid fa-plus me-2"></i>New Override
            </a>
        <?php else: ?>
            <a href="overrides.php" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-2"></i>Back to List
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
    <!-- LIST VIEW -->
    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-list me-2 text-secondary"></i>All Active & Scheduled Overrides</span>
            <span class="badge bg-secondary"><?= count($overrides) ?> total overrides</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($overrides)): ?>
                <div class="text-center p-5">
                    <div class="mb-3 text-muted" style="font-size: 3rem;"><i class="fa-solid fa-circle-check"></i></div>
                    <h5>No Overrides Configured</h5>
                    <p class="text-muted">Currently, all on-call rotations are running purely on their default weekly schedules.</p>
                    <a href="overrides.php?action=new" class="btn btn-outline-primary btn-sm mt-2">Create First Override</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Department</th>
                                <th>Covering Person</th>
                                <th>Starts</th>
                                <th>Ends</th>
                                <th>Reason/Description</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overrides as $ov): ?>
                                <?php
                                $ov_start = strtotime($ov['start_time']);
                                $ov_end = strtotime($ov['end_time']);
                                $now = time();
                                $status_badge = '';
                                if ($now >= $ov_start && $now <= $ov_end) {
                                    $status_badge = '<span class="badge bg-warning text-dark small ms-2">ACTIVE NOW</span>';
                                } elseif ($now > $ov_end) {
                                    $status_badge = '<span class="badge bg-light text-muted small ms-2">EXPIRED</span>';
                                } else {
                                    $status_badge = '<span class="badge bg-info text-dark small ms-2">UPCOMING</span>';
                                }
                                $can_edit = can_manage_department($ov['department_id']);
                                ?>
                                <tr>
                                    <td class="fw-bold">#<?= $ov['id'] ?></td>
                                    <td>
                                        <span class="fw-semibold text-primary"><?= htmlspecialchars($ov['department_name']) ?></span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($ov['name'] . ' ' . $ov['surname']) ?></strong>
                                        <span class="text-muted small">(@<?= htmlspecialchars($ov['username']) ?>)</span>
                                    </td>
                                    <td>
                                        <code><?= date('Y-m-d H:i', $ov_start) ?></code>
                                    </td>
                                    <td>
                                        <code><?= date('Y-m-d H:i', $ov_end) ?></code>
                                        <?= $status_badge ?>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= htmlspecialchars($ov['description'] ?: 'None provided') ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($can_edit): ?>
                                            <a href="overrides.php?action=edit&id=<?= $ov['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
                                                <i class="fa-solid fa-edit"></i>
                                            </a>
                                            <a href="overrides.php?action=delete&id=<?= $ov['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this override?');">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted"><i class="fa-solid fa-lock"></i> Locked</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($action === 'new' || $action === 'edit'): ?>
    <!-- FORM VIEW -->
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <i class="fa-solid <?= $action === 'new' ? 'fa-plus' : 'fa-edit' ?> me-2"></i>
                    <?= $action === 'new' ? 'Create New Manual Override' : 'Edit Override #' . $id ?>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="department_id" class="form-label fw-semibold">Target Department</label>
                            <select name="department_id" id="department_id" class="form-select" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <?php
                                    $selected = '';
                                    if ($override_data && $override_data['department_id'] == $dept['id']) {
                                        $selected = 'selected';
                                    }
                                    ?>
                                    <option value="<?= $dept['id'] ?>" <?= $selected ?>>
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="user_id" class="form-label fw-semibold">Covering User</label>
                            <select name="user_id" id="user_id" class="form-select" required>
                                <option value="">-- Select Covering User --</option>
                                <?php foreach ($all_users as $user): ?>
                                    <?php
                                    $selected = '';
                                    if ($override_data && $override_data['user_id'] == $user['id']) {
                                        $selected = 'selected';
                                    }
                                    ?>
                                    <option value="<?= $user['id'] ?>" <?= $selected ?>>
                                        <?= htmlspecialchars($user['name'] . ' ' . $user['surname']) ?> (@<?= htmlspecialchars($user['username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="start_time" class="form-label fw-semibold">Override Start Time</label>
                                <?php
                                $start_val = '';
                                if ($override_data) {
                                    $start_val = date('Y-m-d\TH:i', strtotime($override_data['start_time']));
                                } else {
                                    $start_val = date('Y-m-d\T17:00');
                                }
                                ?>
                                <input type="datetime-local" class="form-control" name="start_time" id="start_time" value="<?= $start_val ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="end_time" class="form-label fw-semibold">Override End Time</label>
                                <?php
                                $end_val = '';
                                if ($override_data) {
                                    $end_val = date('Y-m-d\TH:i', strtotime($override_data['end_time']));
                                } else {
                                    $end_val = date('Y-m-d\T17:00', strtotime('+1 day'));
                                }
                                ?>
                                <input type="datetime-local" class="form-control" name="end_time" id="end_time" value="<?= $end_val ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label fw-semibold">Reason / Description</label>
                            <input type="text" class="form-control" name="description" id="description"
                                   placeholder="e.g. Sickness cover, holiday swap, etc."
                                   value="<?= $override_data ? htmlspecialchars($override_data['description']) : '' ?>">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="save_override" class="btn btn-success">
                                <i class="fa-solid fa-save me-2"></i>Save Override
                            </button>
                            <a href="overrides.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
