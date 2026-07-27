<?php
// sync.php - Zabbix user sync & user list
require_once 'header.php';
require_once 'models.php';

// Access Control: Only Global Administrators can access sync.php
if (!has_permission('manage_departments')) {
    echo "<div class='alert alert-danger p-4'><i class='fa-solid fa-circle-exclamation me-2'></i>Access Denied: Only Global Administrators can access the Zabbix synchronization portal.</div>";
    require_once 'footer.php';
    exit;
}

$message = '';
$error = '';

if (isset($_POST['sync'])) {
    try {
        $count = sync_zabbix_users();
        $message = "Successfully synchronized with Zabbix database. Synchronized total of $count users!";
    } catch (Exception $e) {
        $error = "Synchronization failed: " . $e->getMessage();
    }
}

$users = get_all_users();
$departments = get_all_departments();
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-users-gear text-primary me-2"></i>Sync & Users</h1>
        <p class="text-muted">Import and update user profiles directly from the Zabbix monitoring database.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <form method="POST">
            <button type="submit" name="sync" class="btn btn-primary">
                <i class="fa-solid fa-arrows-rotate me-2"></i>Sync Users from Zabbix
            </button>
        </form>
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

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-users me-2 text-secondary"></i>Users Database</span>
        <span class="badge bg-secondary"><?= count($users) ?> registered users</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($users)): ?>
            <div class="text-center p-5">
                <div class="mb-3 text-muted" style="font-size: 3rem;"><i class="fa-solid fa-user-slash"></i></div>
                <h5>No users found in system</h5>
                <p class="text-muted">Click the "Sync Users from Zabbix" button above to pull user accounts from the Zabbix MySQL database.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User ID</th>
                            <th>Zabbix ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email Address</th>
                            <th>Associated Departments</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $db = get_oncall_db();
                            $stmt = $db->prepare("
                                SELECT d.name
                                FROM departments d
                                JOIN department_users du ON d.id = du.department_id
                                WHERE du.user_id = ?
                            ");
                            $stmt->execute([$user['id']]);
                            $user_depts = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            ?>
                            <tr>
                                <td class="fw-bold">#<?= $user['id'] ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fa-solid fa-server me-1 small"></i><?= htmlspecialchars($user['zabbix_userid']) ?>
                                    </span>
                                </td>
                                <td>
                                    <code>@<?= htmlspecialchars($user['username']) ?></code>
                                </td>
                                <td class="fw-semibold">
                                    <?= htmlspecialchars($user['name'] . ' ' . $user['surname']) ?>
                                </td>
                                <td>
                                    <a href="mailto:<?= htmlspecialchars($user['email']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($user['email']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if (empty($user_depts)): ?>
                                        <span class="text-muted small">None &bull; <a href="departments.php" class="text-decoration-none small">Assign</a></span>
                                    <?php else: ?>
                                        <?php foreach ($user_depts as $dept_name): ?>
                                            <span class="badge bg-info text-dark me-1"><?= htmlspecialchars($dept_name) ?></span>
                                        <?php endforeach; ?>
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

<?php require_once 'footer.php'; ?>
