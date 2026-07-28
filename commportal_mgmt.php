<?php
// commportal_mgmt.php - Dedicated CommPortal Telephony Accounts & Mappings Manager
require_once 'header.php';
require_once 'models.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$message = '';
$error = '';

$departments = get_all_departments();

// Determine if user has permission to manage at least one department or is admin
$can_view_page = false;
foreach ($departments as $dept) {
    if (can_manage_department($dept['id'])) {
        $can_view_page = true;
        break;
    }
}

if (!has_permission('manage_departments') && !$can_view_page) {
    echo "<div class='alert alert-danger p-4'><i class='fa-solid fa-circle-exclamation me-2'></i>Access Denied: You do not have permission to manage CommPortal accounts.</div>";
    require_once 'footer.php';
    exit;
}

// Handle Adding a CommPortal Account Mapping
if (isset($_POST['add_account'])) {
    $dept_id = (int)($_POST['department_id'] ?? 0);
    $phone = trim($_POST['phone_number'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $ext = trim($_POST['ext'] ?? '');

    if (!can_manage_department($dept_id)) {
        $error = "Unauthorized: You do not have permission to manage CommPortal accounts for this department.";
    } elseif ($phone === '' || $password === '') {
        $error = "Phone number and password are required.";
    } else {
        try {
            create_department_commportal_account($dept_id, $phone, $password, $ext !== '' ? $ext : null);
            $message = "Successfully added CommPortal account '$phone' and mapped it to the selected group!";
        } catch (Exception $e) {
            $error = "Failed to add CommPortal account: " . $e->getMessage();
        }
    }
}

// Handle Deleting a CommPortal Account Mapping
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];

    $db = get_oncall_db();
    $stmt = $db->prepare("SELECT * FROM commportal_accounts WHERE id = ?");
    $stmt->execute([$delete_id]);
    $account = $stmt->fetch();

    if (!$account) {
        $error = "CommPortal account not found.";
    } elseif (!can_manage_department($account['department_id'])) {
        $error = "Unauthorized: You do not have permission to delete CommPortal accounts for this department.";
    } else {
        try {
            delete_department_commportal_account($delete_id);
            $message = "CommPortal account mapping deleted successfully!";
        } catch (Exception $e) {
            $error = "Failed to delete account mapping: " . $e->getMessage();
        }
    }
}

// Fetch all accounts to display
$db = get_oncall_db();
$stmt = $db->query("
    SELECT cp.*, d.name AS department_name
    FROM commportal_accounts cp
    JOIN departments d ON cp.department_id = d.id
    ORDER BY d.name ASC, cp.phone_number ASC
");
$all_accounts = $stmt->fetchAll();
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-phone text-primary me-2"></i>CommPortal Telephony Manager</h1>
        <p class="text-muted">Manage CommPortal phone lines, extensions, and map them to on-call groups for automatic unconditional call forwarding.</p>
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
    <!-- Left Column: Accounts List -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-list me-2 text-secondary"></i>Mapped CommPortal Phone Lines</span>
                <span class="badge bg-secondary"><?= count($all_accounts) ?> accounts</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($all_accounts)): ?>
                    <div class="text-center p-5">
                        <div class="mb-3 text-muted" style="font-size: 3rem;"><i class="fa-solid fa-phone-slash"></i></div>
                        <h5>No Phone Accounts Configured</h5>
                        <p class="text-muted">Create a mapping on the right to start routing calls automatically!</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>On-Call Group</th>
                                    <th>Phone Number</th>
                                    <th>Extension</th>
                                    <th>Active Forwarding Target</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_accounts as $acc): ?>
                                    <?php
                                    $is_authorized = can_manage_department($acc['department_id']);
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-semibold text-primary"><?= htmlspecialchars($acc['department_name']) ?></span>
                                        </td>
                                        <td>
                                            <code><?= htmlspecialchars($acc['phone_number']) ?></code>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($acc['ext'] ?: 'None') ?>
                                        </td>
                                        <td>
                                            <?php if ($acc['last_forwarded_phone']): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-share me-1"></i><?= htmlspecialchars($acc['last_forwarded_phone']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Not Sync'd yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($is_authorized): ?>
                                                <a href="commportal_mgmt.php?delete_id=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this CommPortal account mapping?');">
                                                    <i class="fa-solid fa-trash-can"></i>
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
    </div>

    <!-- Right Column: Add Form -->
    <div class="col-lg-5">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white fw-bold">
                <i class="fa-solid fa-plus-circle me-2"></i>Map New CommPortal Account
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="department_id" class="form-label fw-semibold small">Mapped On-Call Group</label>
                        <select name="department_id" id="department_id" class="form-select" required>
                            <option value="">-- Choose Department --</option>
                            <?php foreach ($departments as $dept): ?>
                                <?php if (can_manage_department($dept['id'])): ?>
                                    <option value="<?= $dept['id'] ?>">
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="phone_number" class="form-label fw-semibold small">Directory Number (DirectoryNumber)</label>
                        <input type="text" name="phone_number" id="phone_number" class="form-control" placeholder="e.g. +15550100" required>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold small">CommPortal Password</label>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Enter CommPortal password" required>
                    </div>

                    <div class="mb-3">
                        <label for="ext" class="form-label fw-semibold small">Extension (Optional)</label>
                        <input type="text" name="ext" id="ext" class="form-control" placeholder="e.g. 505">
                    </div>

                    <button type="submit" name="add_account" class="btn btn-primary w-100">
                        <i class="fa-solid fa-save me-2"></i>Save & Map Account
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
