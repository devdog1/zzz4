<?php
// admin-roles.php - RBAC Roles and Role Permissions Management Interface
require_once __DIR__ . '/functions.php';

// Ensure user has admin rights
if (!has_permission('manage_settings')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. You do not have permission to manage roles.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$db = get_db_connection();
$msg = '';
$err = '';

// Handle actions (Create Role, Edit Role, Toggle Active Status, Manage Role Permissions, Delete Custom Role)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_role') {
        $role_name = strtolower(trim($_POST['role_name'] ?? ''));
        $role_name = preg_replace('/[^a-z0-9_]/', '_', $role_name);
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!empty($role_name)) {
            try {
                $stmt = $db->prepare("INSERT INTO roles (role_name, description, is_active) VALUES (?, ?, ?)");
                $stmt->execute([$role_name, $description, $is_active]);
                $role_id = $db->lastInsertId();

                // Assign initial permissions if selected
                $selected_perms = $_POST['permissions'] ?? [];
                if (!empty($selected_perms) && is_array($selected_perms)) {
                    $stmtPerm = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                    foreach ($selected_perms as $perm_id) {
                        $stmtPerm->execute([$role_id, (int)$perm_id]);
                    }
                }

                $msg = "Role <strong>" . htmlspecialchars($role_name) . "</strong> created successfully!";
                log_action('ADMIN_CREATE_ROLE', ['role_name' => $role_name, 'is_active' => $is_active]);
            } catch (Exception $e) {
                $err = "Error creating role: " . $e->getMessage();
            }
        } else {
            $err = "Role identifier name is required.";
        }
    } elseif ($action === 'update_role') {
        $role_id = (int)($_POST['role_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $selected_perms = $_POST['permissions'] ?? []; // Array of permission IDs

        if ($role_id > 0) {
            $db->beginTransaction();
            try {
                // Update basic role details (prevent disabling admin role)
                $stmtRole = $db->prepare("SELECT role_name FROM roles WHERE id = ?");
                $stmtRole->execute([$role_id]);
                $targetRole = $stmtRole->fetch();

                if ($targetRole && $targetRole['role_name'] === 'admin' && $is_active === 0) {
                    $is_active = 1; // Prevent disabling superuser admin role
                }

                $stmt = $db->prepare("UPDATE roles SET description = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$description, $is_active, $role_id]);

                // Update Role Permissions
                $stmtDel = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $stmtDel->execute([$role_id]);

                if (!empty($selected_perms) && is_array($selected_perms)) {
                    $stmtIns = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                    foreach ($selected_perms as $perm_id) {
                        $stmtIns->execute([$role_id, (int)$perm_id]);
                    }
                }

                $db->commit();
                $msg = "Role configuration updated successfully!";
                log_action('ADMIN_UPDATE_ROLE', ['role_id' => $role_id, 'is_active' => $is_active]);
            } catch (Exception $e) {
                $db->rollBack();
                $err = "Failed to update role: " . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle_role_status') {
        $role_id = (int)($_POST['role_id'] ?? 0);
        $new_status = (int)($_POST['target_status'] ?? 1);

        if ($role_id > 0) {
            $stmtRole = $db->prepare("SELECT role_name FROM roles WHERE id = ?");
            $stmtRole->execute([$role_id]);
            $targetRole = $stmtRole->fetch();

            if ($targetRole && $targetRole['role_name'] === 'admin' && $new_status === 0) {
                $err = "The superuser <strong>admin</strong> role cannot be disabled.";
            } else {
                try {
                    $stmt = $db->prepare("UPDATE roles SET is_active = ? WHERE id = ?");
                    $stmt->execute([$new_status, $role_id]);
                    $status_label = $new_status ? 'enabled' : 'disabled';
                    $msg = "Role status changed to <strong>{$status_label}</strong>.";
                    log_action('ADMIN_TOGGLE_ROLE_STATUS', ['role_id' => $role_id, 'is_active' => $new_status]);
                } catch (Exception $e) {
                    $err = "Error updating status: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_role') {
        $role_id = (int)($_POST['role_id'] ?? 0);

        if ($role_id > 0) {
            $stmtRole = $db->prepare("SELECT role_name FROM roles WHERE id = ?");
            $stmtRole->execute([$role_id]);
            $targetRole = $stmtRole->fetch();

            if ($targetRole && in_array($targetRole['role_name'], ['admin', 'manager', 'user'])) {
                $err = "System default roles (<code>admin</code>, <code>manager</code>, <code>user</code>) cannot be deleted.";
            } else {
                try {
                    $stmt = $db->prepare("DELETE FROM roles WHERE id = ?");
                    $stmt->execute([$role_id]);
                    $msg = "Custom role deleted successfully.";
                    log_action('ADMIN_DELETE_ROLE', ['role_id' => $role_id]);
                } catch (Exception $e) {
                    $err = "Error deleting role: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch lists
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$permissions = $db->query("SELECT * FROM permissions ORDER BY permission_name ASC")->fetchAll();

// Count assigned users per role
$user_counts = [];
$counts_res = $db->query("SELECT role_id, COUNT(*) as cnt FROM user_roles GROUP BY role_id")->fetchAll();
foreach ($counts_res as $cr) {
    $user_counts[$cr['role_id']] = $cr['cnt'];
}

// Count Azure AD group mappings per role
$azure_counts = [];
$azure_res = $db->query("SELECT role_id, COUNT(*) as cnt FROM azure_group_roles GROUP BY role_id")->fetchAll();
foreach ($azure_res as $ar) {
    $azure_counts[$ar['role_id']] = $ar['cnt'];
}

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-user-shield text-primary me-2"></i>RBAC Roles & Permissions Manager</h1>
        <p class="text-muted">Create custom system roles, enable/disable existing roles, and configure assigned permissions.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createRoleModal">
            <i class="fa-solid fa-plus-circle me-1"></i>Create New Role
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show text-start" role="alert">
        <i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show text-start" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $err ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row text-start mb-4">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-shield-halved me-2 text-warning"></i>Configured Roles & Assigned Capabilities</span>
                <span class="badge bg-secondary"><?= count($roles) ?> Roles Total</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Role Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Assigned Permissions</th>
                                <th>Members</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $r):
                                $is_active = isset($r['is_active']) ? (int)$r['is_active'] : 1;

                                // Fetch assigned permissions for this role
                                $stmt = $db->prepare("
                                    SELECT p.id, p.permission_name
                                    FROM role_permissions rp
                                    JOIN permissions p ON p.id = rp.permission_id
                                    WHERE rp.role_id = ?
                                    ORDER BY p.permission_name ASC
                                ");
                                $stmt->execute([$r['id']]);
                                $role_perms = $stmt->fetchAll();
                                $role_perm_ids = array_column($role_perms, 'id');

                                $assigned_users = $user_counts[$r['id']] ?? 0;
                                $assigned_azure = $azure_counts[$r['id']] ?? 0;
                            ?>
                                <tr>
                                    <td>
                                        <h6 class="mb-0 fw-bold text-dark">
                                            <i class="fa-solid fa-tag me-1 text-primary"></i>
                                            <?= ucfirst(htmlspecialchars($r['role_name'])) ?>
                                        </h6>
                                        <small class="text-muted">Slug: <code><?= htmlspecialchars($r['role_name']) ?></code></small>
                                    </td>
                                    <td>
                                        <small class="text-secondary"><?= htmlspecialchars($r['description'] ?: 'No description provided.') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($is_active): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i>Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-circle-xmark me-1"></i>Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['role_name'] === 'admin'): ?>
                                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-crown me-1"></i>Superuser (All Rights)</span>
                                        <?php elseif (empty($role_perms)): ?>
                                            <span class="text-muted small">No permissions assigned</span>
                                        <?php else: ?>
                                            <div class="d-flex flex-wrap gap-1" style="max-width: 350px;">
                                                <?php foreach (array_slice($role_perms, 0, 4) as $rp): ?>
                                                    <span class="badge bg-light text-dark border"><code><?= htmlspecialchars($rp['permission_name']) ?></code></span>
                                                <?php endforeach; ?>
                                                <?php if (count($role_perms) > 4): ?>
                                                    <span class="badge bg-secondary">+<?= count($role_perms) - 4 ?> more</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info text-dark" title="Direct local users"><i class="fa-solid fa-user me-1"></i><?= $assigned_users ?></span>
                                        <?php if ($assigned_azure > 0): ?>
                                            <span class="badge bg-primary ms-1" title="Mapped Azure AD groups"><i class="fa-brands fa-microsoft me-1"></i><?= $assigned_azure ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <!-- Edit Role & Manage Permissions Button -->
                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editRoleModal<?= $r['id'] ?>">
                                                <i class="fa-solid fa-sliders me-1"></i>Configure
                                            </button>

                                            <!-- Toggle Enable / Disable -->
                                            <?php if ($r['role_name'] !== 'admin'): ?>
                                                <form method="POST" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="toggle_role_status">
                                                    <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
                                                    <input type="hidden" name="target_status" value="<?= $is_active ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-outline-<?= $is_active ? 'warning' : 'success' ?>" title="<?= $is_active ? 'Disable Role' : 'Enable Role' ?>">
                                                        <i class="fa-solid fa-power-off"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Delete Custom Role Button -->
                                            <?php if (!in_array($r['role_name'], ['admin', 'manager', 'user'])): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete custom role <?= htmlspecialchars($r['role_name']) ?>?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_role">
                                                    <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger" title="Delete Role">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Modal for editing role details and permissions -->
                                <div class="modal fade" id="editRoleModal<?= $r['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-xl">
                                        <div class="modal-content">
                                            <div class="modal-header bg-dark text-white">
                                                <h5 class="modal-title"><i class="fa-solid fa-sliders me-2 text-warning"></i>Configure Role: <?= ucfirst(htmlspecialchars($r['role_name'])) ?></h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="update_role">
                                                <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
                                                <div class="modal-body text-start">
                                                    <div class="row g-3 mb-4">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small">Role Name (Identifier)</label>
                                                            <input type="text" class="form-control" value="<?= htmlspecialchars($r['role_name']) ?>" disabled>
                                                            <div class="form-text">System role identifier key (read-only).</div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small">Description</label>
                                                            <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($r['description']) ?>" placeholder="Describe role purpose...">
                                                        </div>
                                                    </div>

                                                    <div class="form-check form-switch mb-4 p-3 bg-light rounded border">
                                                        <input class="form-check-input ms-0 me-2" type="checkbox" name="is_active" id="active_switch_<?= $r['id'] ?>" <?= $is_active ? 'checked' : '' ?> <?= $r['role_name'] === 'admin' ? 'disabled' : '' ?>>
                                                        <label class="form-check-label fw-bold" for="active_switch_<?= $r['id'] ?>">
                                                            Enable Role
                                                        </label>
                                                        <div class="text-muted small mt-1">When disabled, users assigned this role will not inherit its permissions or active role status during access checks.</div>
                                                    </div>

                                                    <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fa-solid fa-key me-1 text-primary"></i>Assigned Permissions</h6>

                                                    <?php if ($r['role_name'] === 'admin'): ?>
                                                        <div class="alert alert-warning mb-0">
                                                            <i class="fa-solid fa-crown me-2"></i> The <strong>admin</strong> role automatically possesses full superuser rights over all permissions in the platform. Explicit assignment is optional.
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="row g-2">
                                                            <?php foreach ($permissions as $p): ?>
                                                                <div class="col-12 col-md-6 col-lg-4">
                                                                    <div class="form-check p-2 border rounded bg-light h-100 d-flex align-items-center gap-2">
                                                                        <input class="form-check-input flex-shrink-0 mt-0 ms-0 me-1" type="checkbox" name="permissions[]" value="<?= $p['id'] ?>" id="rperm_<?= $r['id'] ?>_<?= $p['id'] ?>" <?= in_array($p['id'], $role_perm_ids) ? 'checked' : '' ?>>
                                                                        <label class="form-check-label text-break w-100 mb-0" for="rperm_<?= $r['id'] ?>_<?= $p['id'] ?>">
                                                                            <code class="text-break text-wrap d-inline-block" style="word-break: break-all; overflow-wrap: anywhere; max-width: 100%;"><?= htmlspecialchars($p['permission_name']) ?></code>
                                                                            <?php if (!empty($p['description'])): ?>
                                                                                <div class="text-muted small text-break" style="word-break: break-word; overflow-wrap: anywhere;"><?= htmlspecialchars($p['description']) ?></div>
                                                                            <?php endif; ?>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>Save Role Settings</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Create New Role -->
<div class="modal fade" id="createRoleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fa-solid fa-plus-circle me-2"></i>Create Custom Role</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_role">
                <div class="modal-body text-start">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Role Identifier (Slug)</label>
                        <input type="text" name="role_name" class="form-control" placeholder="e.g. auditor or support_tier2" required>
                        <div class="form-text">Short alphanumeric string used in code checks (`has_role('auditor')`).</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g. Tier 2 Support Engineers with escalation rights">
                    </div>

                    <div class="form-check form-switch mb-4 p-3 bg-light rounded border">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="is_active" id="create_active_switch" checked>
                        <label class="form-check-label fw-bold" for="create_active_switch">
                            Enable Role Immediately
                        </label>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fa-solid fa-key me-1 text-primary"></i>Initial Permissions</h6>
                    <div class="row g-2" style="max-height: 250px; overflow-y: auto;">
                        <?php foreach ($permissions as $p): ?>
                            <div class="col-12 col-md-6">
                                <div class="form-check p-2 border rounded bg-light d-flex align-items-center gap-2">
                                    <input class="form-check-input flex-shrink-0 mt-0 ms-0 me-1" type="checkbox" name="permissions[]" value="<?= $p['id'] ?>" id="cperm_<?= $p['id'] ?>">
                                    <label class="form-check-label text-break w-100 mb-0" for="cperm_<?= $p['id'] ?>">
                                        <code class="text-break text-wrap d-inline-block" style="word-break: break-all; overflow-wrap: anywhere; max-width: 100%;"><?= htmlspecialchars($p['permission_name']) ?></code>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus-circle me-1"></i>Create Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
