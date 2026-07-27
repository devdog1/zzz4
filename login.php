<?php
// login.php - Login selection (Azure AD)
require_once 'models.php';
require_once 'Auth.php';

$config = require 'config.php';
$auth = new Auth($config);

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// Handle real Azure AD login redirect
if (isset($_POST['azure_login'])) {
    try {
        $auth->login();
    } catch (Exception $e) {
        $error = "Azure Login failed to initiate: " . $e->getMessage();
    }
}
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
