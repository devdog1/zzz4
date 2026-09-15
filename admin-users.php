<?php
// admin-users.php - Admin User Directory, Azure AD Group Role Mappings, and Permissions Management Interface
require_once __DIR__ . '/functions.php';

// Ensure user has admin rights
if (!has_permission('manage_settings')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. You do not have permission to manage users.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$db = get_db_connection();
$msg = '';
$err = '';

// Handle actions (Grant Role, Revoke Role, Grant Permission, Deny Permission, Create User, Azure AD Group Mappings)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $display_name = trim($_POST['display_name'] ?? '');

        if (!empty($username) && !empty($email)) {
            try {
                $stmt = $db->prepare("INSERT INTO users (username, email, display_name) VALUES (?, ?, ?)");
                $stmt->execute([$username, $email, $display_name ?: $username]);
                $userId = $db->lastInsertId();

                // Assign standard user role
                $stmtRole = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, 3)");
                $stmtRole->execute([$userId]);

                $msg = "User <strong>" . htmlspecialchars($username) . "</strong> created successfully!";
                log_action('ADMIN_CREATE_USER', ['username' => $username, 'email' => $email]);
            } catch (Exception $e) {
                $err = "Error creating user: " . $e->getMessage();
            }
        } else {
            $err = "Username and Email are required fields.";
        }
    } elseif ($action === 'update_roles_permissions') {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $roles_to_assign = $_POST['roles'] ?? []; // Array of role IDs
        $direct_permissions = $_POST['direct_permissions'] ?? []; // Array of permission IDs
        $denied_permissions = $_POST['denied_permissions'] ?? []; // Array of permission IDs

        if ($target_user_id > 0) {
            $db->beginTransaction();
            try {
                // Update User Roles
                $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ?");
                $stmt->execute([$target_user_id]);

                if (!empty($roles_to_assign)) {
                    $stmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($roles_to_assign as $role_id) {
                        $stmt->execute([$target_user_id, (int)$role_id]);
                    }
                }

                // Update Direct Permissions
                $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
                $stmt->execute([$target_user_id]);

                if (!empty($direct_permissions)) {
                    $stmt = $db->prepare("INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)");
                    foreach ($direct_permissions as $perm_id) {
                        $stmt->execute([$target_user_id, (int)$perm_id]);
                    }
                }

                // Update Denied Permissions
                $stmt = $db->prepare("DELETE FROM denied_permissions WHERE user_id = ?");
                $stmt->execute([$target_user_id]);

                if (!empty($denied_permissions)) {
                    $stmt = $db->prepare("INSERT INTO denied_permissions (user_id, permission_id) VALUES (?, ?)");
                    foreach ($denied_permissions as $perm_id) {
                        $stmt->execute([$target_user_id, (int)$perm_id]);
                    }
                }

                $db->commit();
                $msg = "User privileges updated successfully!";
                log_action('ADMIN_UPDATE_USER_PRIVILEGES', ['user_id' => $target_user_id]);
            } catch (Exception $e) {
                $db->rollBack();
                $err = "Failed to update user privileges: " . $e->getMessage();
            }
        }
    } elseif ($action === 'create_azure_group_role') {
        $group_name = trim($_POST['azure_group_name'] ?? '');
        $role_id = (int)($_POST['role_id'] ?? 0);

        if (!empty($group_name) && $role_id > 0) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO azure_group_roles (azure_group_name, role_id)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)
                ");
                $stmt->execute([$group_name, $role_id]);
                $msg = "Azure AD Group <strong>" . htmlspecialchars($group_name) . "</strong> mapped to local role successfully!";
                log_action('ADMIN_CREATE_AZURE_GROUP_ROLE', ['group' => $group_name, 'role_id' => $role_id]);
            } catch (Exception $e) {
                $err = "Failed to map Azure AD Group: " . $e->getMessage();
            }
        } else {
            $err = "Azure AD Group Name and Local Role selection are required.";
        }
    } elseif ($action === 'delete_azure_group_role') {
        $group_name = trim($_POST['azure_group_name'] ?? '');
        $role_id = (int)($_POST['role_id'] ?? 0);

        if (!empty($group_name) && $role_id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM azure_group_roles WHERE azure_group_name = ? AND role_id = ?");
                $stmt->execute([$group_name, $role_id]);
                $msg = "Mapping for Azure AD Group <strong>" . htmlspecialchars($group_name) . "</strong> removed successfully!";
                log_action('ADMIN_DELETE_AZURE_GROUP_ROLE', ['group' => $group_name, 'role_id' => $role_id]);
            } catch (Exception $e) {
                $err = "Failed to remove mapping: " . $e->getMessage();
            }
        }
    }
}

// Fetch lists
$users = get_all_users();
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$permissions = $db->query("SELECT * FROM permissions ORDER BY id ASC")->fetchAll();
$azure_group_roles = $db->query("
    SELECT agr.*, r.role_name, r.description AS role_description
    FROM azure_group_roles agr
    JOIN roles r ON r.id = agr.role_id
    ORDER BY agr.azure_group_name ASC
")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-users-gear text-primary me-2"></i>User & Permissions Management</h1>
        <p class="text-muted">Control global RBAC roles, map Azure AD Groups to local roles, assign direct permissions, and manage accounts.</p>
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
    <!-- Users List & Permissions Configuration -->
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="fa-solid fa-list-check me-2"></i>Portal User Directory & Privileges</span>
                <div class="input-group input-group-sm" style="max-width: 280px;">
                    <span class="input-group-text bg-secondary text-white border-secondary"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" id="userSearchInput" class="form-control" placeholder="Search user, email, role..." onkeyup="filterUsersTable()">
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($users)): ?>
                    <p class="text-muted p-4 mb-0">No users found in database.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="userDirectoryTable">
                            <thead class="table-light">
                                <tr>
                                    <th>User Information</th>
                                    <th>Roles</th>
                                    <th class="text-end">Manage Privileges</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user):
                                    // Fetch user roles
                                    $stmt = $db->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
                                    $stmt->execute([$user['id']]);
                                    $user_role_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                                    // Fetch user direct permissions
                                    $stmt = $db->prepare("SELECT permission_id FROM user_permissions WHERE user_id = ?");
                                    $stmt->execute([$user['id']]);
                                    $user_direct_perm_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                                    // Fetch user denied permissions
                                    $stmt = $db->prepare("SELECT permission_id FROM denied_permissions WHERE user_id = ?");
                                    $stmt->execute([$user['id']]);
                                    $user_denied_perm_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                                    // Roles string representation
                                    $user_roles_str = [];
                                    foreach ($roles as $r) {
                                        if (in_array($r['id'], $user_role_ids)) {
                                            $user_roles_str[] = $r['role_name'];
                                        }
                                    }
                                    $roles_badge = !empty($user_roles_str) ? implode(', ', array_map('ucfirst', $user_roles_str)) : 'None';
                                ?>
                                    <tr>
                                        <td>
                                            <h6 class="mb-0 fw-bold"><?= htmlspecialchars($user['display_name'] ?? 'User') ?></h6>
                                            <small class="text-muted"><i class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($user['email']) ?></small>
                                            <br>
                                            <small class="text-muted">Username: <code><?= htmlspecialchars($user['username']) ?></code></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($roles_badge) ?></span>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $user['id'] ?>">
                                                <i class="fa-solid fa-user-shield me-1"></i>Edit RBAC
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Modal for each user editing -->
                                    <div class="modal fade" id="editModal<?= $user['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-xl">
                                            <div class="modal-content">
                                                <div class="modal-header bg-dark text-white">
                                                    <h5 class="modal-title"><i class="fa-solid fa-user-shield me-2 text-primary"></i>Edit Privileges for <?= htmlspecialchars($user['display_name']) ?></h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <?php csrf_field(); ?>
                                                    <div class="modal-body text-start">
                                                        <input type="hidden" name="action" value="update_roles_permissions">
                                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

                                                        <!-- Roles section -->
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fa-solid fa-tags me-1"></i>Assign Roles</h6>
                                                        <div class="row g-3 mb-4">
                                                            <?php foreach ($roles as $r): ?>
                                                                <div class="col-12 col-md-6 col-lg-4">
                                                                    <div class="form-check p-2 border rounded bg-light h-100 d-flex align-items-start gap-2">
                                                                        <input class="form-check-input mt-1 flex-shrink-0 ms-0 me-1" type="checkbox" name="roles[]" value="<?= $r['id'] ?>" id="role_<?= $user['id'] ?>_<?= $r['id'] ?>" <?= in_array($r['id'], $user_role_ids) ? 'checked' : '' ?>>
                                                                        <label class="form-check-label w-100 text-break mb-0" for="role_<?= $user['id'] ?>_<?= $r['id'] ?>">
                                                                            <strong class="d-block text-dark text-break" style="word-break: break-word; overflow-wrap: anywhere;"><?= ucfirst(htmlspecialchars($r['role_name'])) ?></strong>
                                                                            <div class="text-muted small text-break" style="word-break: break-word; overflow-wrap: anywhere;"><?= htmlspecialchars($r['description']) ?></div>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>

                                                        <!-- Direct Permissions Section -->
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fa-solid fa-plus-circle me-1 text-success"></i>Directly Grant Extra Permissions</h6>
                                                        <div class="row g-2 mb-4">
                                                            <?php foreach ($permissions as $p): ?>
                                                                <div class="col-12 col-md-6 col-lg-4">
                                                                    <div class="form-check p-2 border rounded bg-light h-100 d-flex align-items-center gap-2">
                                                                        <input class="form-check-input flex-shrink-0 mt-0 ms-0 me-1" type="checkbox" name="direct_permissions[]" value="<?= $p['id'] ?>" id="perm_g_<?= $user['id'] ?>_<?= $p['id'] ?>" <?= in_array($p['id'], $user_direct_perm_ids) ? 'checked' : '' ?>>
                                                                        <label class="form-check-label text-break w-100 mb-0" for="perm_g_<?= $user['id'] ?>_<?= $p['id'] ?>">
                                                                            <code class="text-break text-wrap d-inline-block" style="word-break: break-all; overflow-wrap: anywhere; max-width: 100%;"><?= htmlspecialchars($p['permission_name']) ?></code>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>

                                                        <!-- Denied Permissions Section -->
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fa-solid fa-minus-circle me-1 text-danger"></i>Explicitly Deny Permissions (Highest Precedence)</h6>
                                                        <div class="row g-2">
                                                            <?php foreach ($permissions as $p): ?>
                                                                <div class="col-12 col-md-6 col-lg-4">
                                                                    <div class="form-check p-2 border rounded bg-light h-100 d-flex align-items-center gap-2">
                                                                        <input class="form-check-input flex-shrink-0 mt-0 ms-0 me-1" type="checkbox" name="denied_permissions[]" value="<?= $p['id'] ?>" id="perm_d_<?= $user['id'] ?>_<?= $p['id'] ?>" <?= in_array($p['id'], $user_denied_perm_ids) ? 'checked' : '' ?>>
                                                                        <label class="form-check-label text-break w-100 mb-0" for="perm_d_<?= $user['id'] ?>_<?= $p['id'] ?>">
                                                                            <code class="text-break text-wrap d-inline-block" style="word-break: break-all; overflow-wrap: anywhere; max-width: 100%;"><?= htmlspecialchars($p['permission_name']) ?></code>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create User Form -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <i class="fa-solid fa-user-plus me-1"></i>Add New Local User
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_user">

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Username / Login ID</label>
                        <input type="text" name="username" class="form-control form-control-sm" placeholder="e.g. jdoe@example.com" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email Address</label>
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="e.g. jdoe@example.com" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Display Name</label>
                        <input type="text" name="display_name" class="form-control form-control-sm" placeholder="e.g. John Doe">
                    </div>

                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="fa-solid fa-circle-plus me-1"></i>Create Account
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Azure AD Group to Local Role Mappings Section -->
<div class="row text-start">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-brands fa-microsoft me-2 text-info"></i>Azure AD Group to Local Role Mappings</span>
                <div>
                    <a href="admin-azure-groups.php" class="btn btn-sm btn-outline-info me-2">
                        <i class="fa-solid fa-folder-tree me-1"></i>View Azure AD Directory
                    </a>
                    <span class="badge bg-info"><?= count($azure_group_roles) ?> Mappings</span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($azure_group_roles)): ?>
                    <p class="text-muted p-4 mb-0">No Azure AD Group to Local Role mappings defined yet. Add a mapping on the right.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Azure AD Group Name / OID</th>
                                    <th>Mapped Local Role</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($azure_group_roles as $agr): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-dark"><code><?= htmlspecialchars($agr['azure_group_name']) ?></code></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-success p-2">
                                                <i class="fa-solid fa-tag me-1"></i>
                                                <?= ucfirst(htmlspecialchars($agr['role_name'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this Azure AD Group mapping?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_azure_group_role">
                                                <input type="hidden" name="azure_group_name" value="<?= htmlspecialchars($agr['azure_group_name']) ?>">
                                                <input type="hidden" name="role_id" value="<?= $agr['role_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fa-solid fa-trash me-1"></i>Remove
                                                </button>
                                            </form>
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

    <!-- Form to Add New Azure AD Group Role Mapping -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-info">
            <div class="card-header bg-info text-dark">
                <i class="fa-solid fa-link me-2"></i>Map Azure AD Group to Role
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_azure_group_role">

                    <div class="mb-3">
                        <label for="azure_group_name" class="form-label small fw-bold">Azure AD Group Name / Object ID</label>
                        <input type="text" name="azure_group_name" id="azure_group_name" class="form-control form-control-sm" placeholder="e.g. Portal-Administrators" required>
                        <div class="form-text">Exact group name or Object ID returned in Azure AD SSO token groups claim.</div>
                    </div>

                    <div class="mb-3">
                        <label for="role_id" class="form-label small fw-bold">Target Local Role</label>
                        <select name="role_id" id="role_id" class="form-select form-select-sm" required>
                            <option value="">-- Select Local Role --</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= ucfirst(htmlspecialchars($r['role_name'])) ?> - <?= htmlspecialchars($r['description']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Users belonging to this Azure AD group will automatically be assigned this local role upon SSO login.</div>
                    </div>

                    <button type="submit" class="btn btn-sm btn-info w-100 text-dark fw-bold">
                        <i class="fa-solid fa-link me-1"></i>Save Group Mapping
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function filterUsersTable() {
    const input = document.getElementById('userSearchInput');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('userDirectoryTable');
    if (!table) return;

    const rows = table.getElementsByTagName('tr');

    // Skip row 0 (thead)
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        if (!row.getElementsByTagName('td').length) continue;

        const text = row.textContent || row.innerText;
        if (text.toLowerCase().indexOf(filter) > -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
