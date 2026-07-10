<?php
// departments.php - Department management and membership assignment
require_once 'header.php';
require_once 'models.php';

$message = '';
$error = '';

// Handle creating department
if (isset($_POST['create_dept'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = 'Department name cannot be empty.';
    } else {
        try {
            create_department($name);
            $message = "Department '" . htmlspecialchars($name) . "' created successfully!";
        } catch (Exception $e) {
            $error = "Failed to create department: " . $e->getMessage();
        }
    }
}

// Handle deleting department
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    try {
        $dept = get_department_by_id($delete_id);
        if ($dept) {
            delete_department($delete_id);
            $message = "Department '" . htmlspecialchars($dept['name']) . "' deleted successfully!";
        }
    } catch (Exception $e) {
        $error = "Failed to delete department: " . $e->getMessage();
    }
}

// Handle updating department members
if (isset($_POST['update_members'])) {
    $dept_id = (int)$_POST['dept_id'];
    $selected_users = $_POST['members'] ?? []; // Array of user IDs
    try {
        save_department_users($dept_id, $selected_users);
        $message = "Department members updated successfully!";
    } catch (Exception $e) {
        $error = "Failed to update members: " . $e->getMessage();
    }
}

$departments = get_all_departments();
$all_users = get_all_users();

// Determine if we are currently editing members of some department
$manage_id = isset($_GET['manage_id']) ? (int)$_GET['manage_id'] : null;
$managed_dept = null;
$managed_user_ids = [];
if ($manage_id) {
    $managed_dept = get_department_by_id($manage_id);
    if ($managed_dept) {
        $dept_users = get_department_users($manage_id);
        $managed_user_ids = array_column($dept_users, 'id');
    }
}
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-sitemap text-primary me-2"></i>Departments</h1>
        <p class="text-muted">Manage company/team departments and allocate synchronized team members to them.</p>
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

<div class="row">
    <!-- Left Column: Department List -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-list me-2 text-secondary"></i>Existing Departments</span>
                <span class="badge bg-secondary"><?= count($departments) ?> departments</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($departments)): ?>
                    <div class="text-center p-5">
                        <div class="mb-3 text-muted" style="font-size: 3rem;"><i class="fa-solid fa-folder-open"></i></div>
                        <h5>No Departments</h5>
                        <p class="text-muted">Fill out the creation form on the right to add your first department!</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Department Name</th>
                                    <th>Team Size</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($departments as $dept): ?>
                                    <?php
                                    $members = get_department_users($dept['id']);
                                    $size = count($members);
                                    $is_current = ($manage_id == $dept['id']);
                                    ?>
                                    <tr class="<?= $is_current ? 'table-primary' : '' ?>">
                                        <td class="fw-bold">#<?= $dept['id'] ?></td>
                                        <td>
                                            <span class="fw-semibold text-dark"><?= htmlspecialchars($dept['name']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary rounded-pill"><?= $size ?> members</span>
                                        </td>
                                        <td class="text-end">
                                            <a href="departments.php?manage_id=<?= $dept['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
                                                <i class="fa-solid fa-users me-1"></i> Members
                                            </a>
                                            <a href="departments.php?delete_id=<?= $dept['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this department? This will delete all its rotation schedules and overrides!');">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Create Department or Manage Members -->
    <div class="col-lg-5">
        <?php if ($managed_dept): ?>
            <!-- Manage Members Form -->
            <div class="card border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-users-gear me-2"></i>Members: <?= htmlspecialchars($managed_dept['name']) ?></span>
                    <a href="departments.php" class="btn btn-close btn-close-white btn-sm"></a>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="dept_id" value="<?= $managed_dept['id'] ?>">

                        <p class="text-muted small mb-3">Select the users that belong to this department. They will be available for rotation configurations.</p>

                        <?php if (empty($all_users)): ?>
                            <div class="text-center py-3">
                                <p class="text-danger small mb-0">No users found in database.</p>
                                <a href="sync.php" class="btn btn-sm btn-outline-danger mt-2">Sync Users First</a>
                            </div>
                        <?php else: ?>
                            <div style="max-height: 300px; overflow-y: auto;" class="border rounded p-3 mb-3 bg-light">
                                <?php foreach ($all_users as $user): ?>
                                    <?php $checked = in_array($user['id'], $managed_user_ids) ? 'checked' : ''; ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="members[]" value="<?= $user['id'] ?>" id="user_<?= $user['id'] ?>" <?= $checked ?>>
                                        <label class="form-check-label" for="user_<?= $user['id'] ?>">
                                            <strong><?= htmlspecialchars($user['name'] . ' ' . $user['surname']) ?></strong>
                                            <span class="text-muted small">(@<?= htmlspecialchars($user['username']) ?>)</span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2">
                            <button type="submit" name="update_members" class="btn btn-success">
                                <i class="fa-solid fa-save me-2"></i>Save Department Members
                            </button>
                            <a href="departments.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Create Department Form -->
            <div class="card">
                <div class="card-header bg-white">
                    <i class="fa-solid fa-folder-plus me-2 text-success"></i>Create Department
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold">Department Name</label>
                            <input type="text" class="form-control" name="name" id="name" placeholder="e.g. SysOps Core Team, DevOps, DBA" required>
                        </div>
                        <button type="submit" name="create_dept" class="btn btn-success w-100">
                            <i class="fa-solid fa-plus me-2"></i>Add Department
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
