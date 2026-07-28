<?php
// departments.php - Department management and membership assignment
require_once 'header.php';
require_once 'models.php';

// Only admins can view/manage department creation/deletion
// Managers can only manage membership for their assigned departments
$current_user_id = $_SESSION['user_id'] ?? null;
$my_managed_depts = [];
foreach (get_all_departments() as $dept) {
    if (can_manage_department($dept['id'])) {
        $my_managed_depts[] = $dept;
    }
}

if (!has_permission('manage_departments') && empty($my_managed_depts)) {
    echo "<div class='alert alert-danger p-4'><i class='fa-solid fa-circle-exclamation me-2'></i>Access Denied: You do not have permission to view this page or you are not designated as a manager for any department.</div>";
    require_once 'footer.php';
    exit;
}

$message = '';
$error = '';

// Handle creating department (Admin only)
if (isset($_POST['create_dept'])) {
    if (!has_permission('manage_departments')) {
        $error = 'Unauthorized: Only Administrators can create departments.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $manager_user_id = $_POST['manager_user_id'] ? (int)$_POST['manager_user_id'] : null;

        if ($name === '') {
            $error = 'Department name cannot be empty.';
        } else {
            try {
                create_department($name, $manager_user_id);
                $message = "Department '" . htmlspecialchars($name) . "' created successfully!";
            } catch (Exception $e) {
                $error = "Failed to create department: " . $e->getMessage();
            }
        }
    }
}

// Handle updating Zabbix User Group mappings
if (isset($_POST['update_zabbix_groups'])) {
    $dept_id = (int)$_POST['dept_id'];
    if (!can_manage_department($dept_id)) {
        $error = 'Unauthorized: Only the designated On-Call Manager or Admin can manage Zabbix group mappings.';
    } else {
        $groups_str = $_POST['zabbix_groups'] ?? '';
        // Extract numeric group IDs
        $grp_ids = [];
        if (trim($groups_str) !== '') {
            $parts = explode(',', $groups_str);
            foreach ($parts as $p) {
                $trimmed = trim($p);
                if (is_numeric($trimmed)) {
                    $grp_ids[] = (int)$trimmed;
                }
            }
        }
        try {
            save_department_zabbix_groups($dept_id, $grp_ids);
            $message = "Zabbix User Group mappings updated successfully!";
        } catch (Exception $e) {
            $error = "Failed to update Zabbix User Group mappings: " . $e->getMessage();
        }
    }
}

// Handle updating department manager (Admin only)
if (isset($_POST['update_manager'])) {
    if (!has_permission('manage_departments')) {
        $error = 'Unauthorized: Only Administrators can assign managers.';
    } else {
        $dept_id = (int)$_POST['dept_id'];
        $manager_user_id = $_POST['manager_user_id'] ? (int)$_POST['manager_user_id'] : null;
        try {
            update_department_manager($dept_id, $manager_user_id);
            $message = "Department manager updated successfully!";
        } catch (Exception $e) {
            $error = "Failed to update manager: " . $e->getMessage();
        }
    }
}

// Handle deleting department (Admin only)
if (isset($_GET['delete_id'])) {
    if (!has_permission('manage_departments')) {
        $error = 'Unauthorized: Only Administrators can delete departments.';
    } else {
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
}

// Handle updating department members (Admin or Group Manager)
if (isset($_POST['update_members'])) {
    $dept_id = (int)$_POST['dept_id'];
    if (!can_manage_department($dept_id)) {
        $error = 'Unauthorized: Only the designated Department Manager or Admin can manage membership.';
    } else {
        $selected_users = $_POST['members'] ?? []; // Array of user IDs
        try {
            save_department_users($dept_id, $selected_users);
            $message = "Department members updated successfully!";
        } catch (Exception $e) {
            $error = "Failed to update members: " . $e->getMessage();
        }
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
        if (!can_manage_department($manage_id)) {
            $error = 'Unauthorized: You are not the manager of this department.';
            $managed_dept = null;
        } else {
            $dept_users = get_department_users($manage_id);
            $managed_user_ids = array_column($dept_users, 'id');
        }
    }
}
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-sitemap text-primary me-2"></i>Departments</h1>
        <p class="text-muted">Manage departments, designate group managers, and allocate team members to their groups.</p>
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
                        <p class="text-muted">An Administrator can create the first department using the form on the right!</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Department Name</th>
                                    <th>Group Manager</th>
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
                                    $manager_text = $dept['manager_name']
                                        ? htmlspecialchars($dept['manager_name'] . ' ' . $dept['manager_surname'] . ' (@' . $dept['manager_username'] . ')')
                                        : '<span class="text-danger fw-semibold small"><i class="fa-solid fa-triangle-exclamation me-1"></i>No Manager Assigned</span>';

                                    $is_mgr_or_admin = can_manage_department($dept['id']);
                                    ?>
                                    <tr class="<?= $is_current ? 'table-primary' : '' ?>">
                                        <td class="fw-bold">#<?= $dept['id'] ?></td>
                                        <td>
                                            <span class="fw-semibold text-dark"><?= htmlspecialchars($dept['name']) ?></span>
                                        </td>
                                        <td>
                                            <?= $manager_text ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary rounded-pill"><?= $size ?> members</span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($is_mgr_or_admin): ?>
                                                <a href="departments.php?manage_id=<?= $dept['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
                                                    <i class="fa-solid fa-users me-1"></i> Members
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary me-1" disabled title="You are not authorized to manage this group">
                                                    <i class="fa-solid fa-lock me-1"></i> Locked
                                                </button>
                                            <?php endif; ?>

                                            <?php if (has_permission('manage_departments')): ?>
                                                <a href="departments.php?delete_id=<?= $dept['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this department?');">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a>
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
    </div>

    <!-- Right Column: Create Department or Manage Members -->
    <div class="col-lg-5">
        <?php if ($managed_dept): ?>
            <!-- Manage Members Form (Authorized Department Manager or Admin) -->
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

            <!-- Manager reassignment section (Admin only) -->
            <?php if (has_permission('manage_departments')): ?>
                <div class="card mt-3">
                    <div class="card-header bg-white fw-bold text-dark">
                        <i class="fa-solid fa-user-tie me-2 text-primary"></i>Assign Group Manager
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="dept_id" value="<?= $managed_dept['id'] ?>">
                            <div class="mb-3">
                                <label for="manager_user_id" class="form-label small fw-semibold">Group Manager</label>
                                <select name="manager_user_id" id="manager_user_id" class="form-select form-select-sm">
                                    <option value="">-- No Manager --</option>
                                    <?php foreach ($all_users as $u): ?>
                                        <option value="<?= $u['id'] ?>" <?= ($managed_dept['manager_user_id'] == $u['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u['name'] . ' ' . $u['surname']) ?> (@<?= htmlspecialchars($u['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="update_manager" class="btn btn-sm btn-outline-primary w-100">
                                <i class="fa-solid fa-save me-1"></i>Update Manager
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Map to Zabbix User Groups (Manager or Admin) -->
            <div class="card mt-3">
                <div class="card-header bg-white fw-bold text-dark">
                    <i class="fa-solid fa-network-wired me-2 text-primary"></i>Map Zabbix User Groups
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="dept_id" value="<?= $managed_dept['id'] ?>">
                        <div class="mb-3">
                            <label for="zabbix_groups" class="form-label small fw-semibold">Zabbix User Group IDs (comma-separated)</label>
                            <?php
                            $mapped_groups = get_department_zabbix_groups($managed_dept['id']);
                            $mapped_str = implode(', ', $mapped_groups);
                            ?>
                            <input type="text" name="zabbix_groups" id="zabbix_groups" class="form-control form-control-sm" placeholder="e.g. 7, 12, 15" value="<?= htmlspecialchars($mapped_str) ?>">
                            <div class="form-text small text-muted">Associate this department with one or more Zabbix user group IDs. The active on-call user will be kept in sync inside these groups.</div>
                        </div>
                        <button type="submit" name="update_zabbix_groups" class="btn btn-sm btn-outline-primary w-100">
                            <i class="fa-solid fa-save me-1"></i>Update Zabbix Groups Mapping
                        </button>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- Create Department Form (Admin Only) -->
            <?php if (has_permission('manage_departments')): ?>
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

                            <div class="mb-3">
                                <label for="manager_user_id" class="form-label fw-semibold">Assign On-Call Manager</label>
                                <select name="manager_user_id" id="manager_user_id" class="form-select">
                                    <option value="">-- Select Manager --</option>
                                    <?php foreach ($all_users as $user): ?>
                                        <option value="<?= $user['id'] ?>">
                                            <?= htmlspecialchars($user['name'] . ' ' . $user['surname']) ?> (@<?= htmlspecialchars($user['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" name="create_dept" class="btn btn-success w-100">
                                <i class="fa-solid fa-plus me-2"></i>Add Department
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card text-center p-4">
                    <i class="fa-solid fa-lock text-muted mb-3 fs-1"></i>
                    <h5>Administrator Panel Only</h5>
                    <p class="text-muted small">Only Global Administrators are authorized to create or delete on-call groups.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
