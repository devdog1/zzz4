<?php
// login.php - Login selection (Azure AD & Developer Bypass)
require_once 'models.php';
require_once 'Auth.php';

$config = require 'config.php';
$auth = new Auth($config);

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// Handle Mock Login Submission
if (isset($_POST['mock_login'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $selected_role = $_POST['role'] ?? 'user';

    $user = get_user_by_id($user_id);
    if ($user) {
        $db = get_oncall_db();

        // Ensure the selected role exists and assign it
        $stmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
        $stmt->execute([$selected_role]);
        $role = $stmt->fetch();

        if ($role) {
            // Delete existing roles to prevent collision, and assign selected
            $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $stmt->execute([$user['id']]);

            $stmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            $stmt->execute([$user['id'], $role['id']]);
        }

        // Setup session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = [
            'azure_oid' => $user['azure_oid'] ?: 'mock_oid_' . $user['id'],
            'email'     => $user['email'],
            'name'      => $user['name'] . ' ' . $user['surname'],
            'groups'    => []
        ];

        $_SESSION['roles'] = $auth->getUserRoles($user['id']);
        // Cache roles associative for easy lookup
        $roles_assoc = [];
        foreach ($_SESSION['roles'] as $r) {
            $roles_assoc[$r] = true;
        }
        $_SESSION['roles'] = $roles_assoc;

        $_SESSION['permissions'] = $auth->getPermissions($user['id'], []);

        log_action('MOCK_LOGIN', [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $selected_role
        ]);

        header("Location: index.php");
        exit;
    } else {
        $error = 'Invalid user selected.';
    }
}

// Handle real Azure AD login redirect
if (isset($_POST['azure_login'])) {
    try {
        $auth->login();
    } catch (Exception $e) {
        $error = "Azure Login failed to initiate: " . $e->getMessage();
    }
}

$users = get_all_users();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - On-Call Schedule Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            max-width: 450px;
            width: 100%;
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="card login-card p-4">
    <div class="text-center mb-4">
        <div class="bg-primary text-white rounded-circle d-inline-flex p-3 mb-3">
            <i class="fa-solid fa-clock-rotate-left fa-2x"></i>
        </div>
        <h3 class="fw-bold text-dark">On-Call Schedule Manager</h3>
        <p class="text-muted small">Please sign in to access schedules & trades</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger small">
            <i class="fa-solid fa-circle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Real Azure Login -->
    <form method="POST">
        <button type="submit" name="azure_login" class="btn btn-primary btn-lg w-100 d-flex align-items-center justify-content-center">
            <i class="fa-brands fa-microsoft me-2"></i> Sign in with Microsoft Azure
        </button>
    </form>
</div>

</body>
</html>
