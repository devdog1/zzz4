<?php
// admin-azure-groups.php - Azure AD Directory Groups Sub-Page
require_once __DIR__ . '/functions.php';

// Enforce admin permission
if (!has_permission('manage_settings')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. You do not have permission to access Azure Directory settings.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$db = get_db_connection();
$msg = '';
$err = '';

// Handle mapping action directly from this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_azure_group_role') {
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
    }
}

// Fetch all Azure AD Groups
$azure_sso = get_auth()->getSSO();
$azure_groups = $azure_sso->getAllGroups();

// Fetch roles for mapping select box
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

// Fetch existing group mappings indexed by group name/ID
$mappings_raw = $db->query("
    SELECT agr.*, r.role_name
    FROM azure_group_roles agr
    JOIN roles r ON r.id = agr.role_id
")->fetchAll();

$mapped_groups = [];
foreach ($mappings_raw as $m) {
    $mapped_groups[$m['azure_group_name']][] = $m['role_name'];
}

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-brands fa-microsoft text-info me-2"></i>Azure AD Directory Groups</h1>
        <p class="text-muted">Directory listing of all Azure Active Directory groups along with their Object IDs. Easily map groups directly to local RBAC roles.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <a href="admin-users.php" class="btn btn-sm btn-outline-primary">
            <i class="fa-solid fa-users-gear me-1"></i>Back to Users & RBAC
        </a>
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

<div class="row text-start">
    <div class="col-lg-12">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-folder-tree me-2 text-info"></i>Discovered Azure AD Groups Directory</span>
                <span class="badge bg-info"><?= count($azure_groups) ?> Groups</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($azure_groups)): ?>
                    <p class="text-muted p-4 mb-0">No Azure AD groups found or Microsoft Graph API credentials not configured.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Group Name (displayName)</th>
                                    <th>Object ID (id)</th>
                                    <th>Description</th>
                                    <th>Mapped Local Role(s)</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($azure_groups as $grp):
                                    $grp_name = $grp['displayName'] ?? '';
                                    $grp_id = $grp['id'] ?? '';
                                    $grp_desc = $grp['description'] ?? 'No description';

                                    $existing_roles = [];
                                    if (isset($mapped_groups[$grp_name])) {
                                        $existing_roles = array_merge($existing_roles, $mapped_groups[$grp_name]);
                                    }
                                    if (isset($mapped_groups[$grp_id])) {
                                        $existing_roles = array_merge($existing_roles, $mapped_groups[$grp_id]);
                                    }
                                    $existing_roles = array_unique($existing_roles);
                                ?>
                                    <tr>
                                        <td>
                                            <strong class="text-dark"><?= htmlspecialchars($grp_name) ?></strong>
                                        </td>
                                        <td>
                                            <code><?= htmlspecialchars($grp_id) ?></code>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= htmlspecialchars($grp_desc) ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($existing_roles)): ?>
                                                <?php foreach ($existing_roles as $r_name): ?>
                                                    <span class="badge bg-success p-2 me-1">
                                                        <i class="fa-solid fa-tag me-1"></i><?= ucfirst(htmlspecialchars($r_name)) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">Unmapped</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#mapModal_<?= md5($grp_id) ?>">
                                                <i class="fa-solid fa-link me-1"></i>Map to Role
                                            </button>

                                            <!-- Modal to Map this Group to a Local Role -->
                                            <div class="modal fade" id="mapModal_<?= md5($grp_id) ?>" tabindex="-1">
                                                <div class="modal-dialog modal-md">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-info text-dark">
                                                            <h5 class="modal-title h6 fw-bold">Map Group: <?= htmlspecialchars($grp_name) ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="create_azure_group_role">

                                                            <div class="modal-body text-start">
                                                                <div class="mb-3">
                                                                    <label class="form-label small fw-bold">Azure AD Group Identifier</label>
                                                                    <select name="azure_group_name" class="form-select form-select-sm" required>
                                                                        <option value="<?= htmlspecialchars($grp_name) ?>">By Display Name: <?= htmlspecialchars($grp_name) ?></option>
                                                                        <option value="<?= htmlspecialchars($grp_id) ?>">By Object ID: <?= htmlspecialchars($grp_id) ?></option>
                                                                    </select>
                                                                </div>

                                                                <div class="mb-3">
                                                                    <label for="role_id_<?= md5($grp_id) ?>" class="form-label small fw-bold">Target Local Role</label>
                                                                    <select name="role_id" id="role_id_<?= md5($grp_id) ?>" class="form-select form-select-sm" required>
                                                                        <option value="">-- Select Local Role --</option>
                                                                        <?php foreach ($roles as $r): ?>
                                                                            <option value="<?= $r['id'] ?>"><?= ucfirst(htmlspecialchars($r['role_name'])) ?> - <?= htmlspecialchars($r['description']) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                            </div>

                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-sm btn-info text-dark fw-bold">
                                                                    <i class="fa-solid fa-save me-1"></i>Save Mapping
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
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
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
