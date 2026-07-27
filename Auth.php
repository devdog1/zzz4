<?php
require_once 'AzureADSSO.php';

class Auth
{
    private PDO $db;
    private AzureADSSO $sso;

    public function __construct(array $config)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->sso = new AzureADSSO(
            $config['azure']['clientId'],
            $config['azure']['clientSecret'],
            $config['azure']['redirectUri'],
            $config['azure']['tenantId']
        );

        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=utf8mb4",
            $config['db']['local']['dbhost'],
            $config['db']['local']['dbname']
        );

        $this->db = new PDO(
            $dsn,
            $config['db']['local']['dbuser'],
            $config['db']['local']['dbpass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }

    /* =========================================================
     * LOGIN
     * ========================================================= */
    public function login(): void
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth2_state'] = $state;

        header("Location: " . $this->sso->getAuthUrl($state));
        exit;
    }

    /* =========================================================
     * CALLBACK (AUTO PROVISION USER)
     * ========================================================= */
    public function handleCallback(): bool
    {
        if (
            !isset($_GET['code'], $_GET['state']) ||
            $_GET['state'] !== ($_SESSION['oauth2_state'] ?? null)
        ) {
            return false;
        }

        $tokens = $this->sso->getAccessToken($_GET['code']);
        if (!$tokens) return false;

        $userInfo = $this->sso->getUserInfo($tokens['id_token']);
        $groups   = $this->sso->getUserGroups($tokens['access_token']);

        $azureOid = $userInfo['sub'] ?? '';
        $email    = $userInfo['preferred_username'] ?? '';
        $name     = $userInfo['name'] ?? '';

        $userId = $this->syncUser($azureOid, $email, $name);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user'] = [
            'azure_oid' => $azureOid,
            'email'     => $email,
            'name'      => $name,
            'groups'    => $groups
        ];

        $_SESSION['roles'] = $this->getRoles($userId, $groups);

        // DO NOT cache permissions only — always compute via DB
        $_SESSION['permissions'] = $this->getPermissions($userId, $groups);

        return true;
    }

    /* =========================================================
     * USER SYNC (AUTO PROVISION)
     * ========================================================= */
    private function syncUser(string $azureOid, string $email, string $name): int
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE azure_oid = ?");
        $stmt->execute([$azureOid]);
        $user = $stmt->fetch();

        if ($user) {
            $stmt = $this->db->prepare("
                UPDATE users
                SET email = ?, display_name = ?, last_login = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$email, $name, $user['id']]);

            return (int)$user['id'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (azure_oid, username, email, display_name, auto_provisioned, last_login)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");

        $stmt->execute([
            $azureOid,
            $email,
            $email,
            $name
        ]);

        $userId = (int)$this->db->lastInsertId();

        $this->assignDefaultRoles($userId);

        return $userId;
    }

    /* =========================================================
     * DEFAULT ROLES
     * ========================================================= */
    private function assignDefaultRoles(int $userId): void
    {
        $roles = $this->db->query("SELECT role_id FROM default_roles")->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO user_roles (user_id, role_id)
            VALUES (?, ?)
        ");

        foreach ($roles as $roleId) {
            $stmt->execute([$userId, $roleId]);
        }
    }

    /* =========================================================
     * ROLES FROM AZURE + DB OVERRIDES
     * ========================================================= */
    private function getRoles(int $userId, array $groups): array
    {
        $roles = [];

        if (!empty($groups)) {
            $in = implode(',', array_fill(0, count($groups), '?'));

            $stmt = $this->db->prepare("
                SELECT DISTINCT r.role_name
                FROM azure_group_roles agr
                JOIN roles r ON r.id = agr.role_id
                WHERE agr.azure_group_name IN ($in)
            ");

            $stmt->execute($groups);

            foreach ($stmt->fetchAll() as $row) {
                $roles[$row['role_name']] = true;
            }
        }

        // user overrides
        $stmt = $this->db->prepare("
            SELECT r.role_name
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
        ");

        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            $roles[$row['role_name']] = true;
        }

        return $roles;
    }

    /* =========================================================
     * FULL PERMISSION ENGINE (ROLE + USER + DENY)
     * ========================================================= */
    public function getPermissions(int $userId, array $groups): array
    {
        $permissions = [];

        /* -----------------------------
         * ROLE-BASED PERMISSIONS
         * ----------------------------- */
        $sql = "
            SELECT p.permission_name
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            JOIN roles r ON r.id = rp.role_id
            JOIN user_roles ur ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            $permissions[$row['permission_name']] = true;
        }

        /* -----------------------------
         * AZURE GROUP ROLE PERMISSIONS
         * ----------------------------- */
        if (!empty($groups)) {
            $in = implode(',', array_fill(0, count($groups), '?'));

            $sql = "
                SELECT p.permission_name
                FROM permissions p
                JOIN role_permissions rp ON rp.permission_id = p.id
                JOIN azure_group_roles agr ON agr.role_id = rp.role_id
                WHERE agr.azure_group_name IN ($in)
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($groups);

            foreach ($stmt->fetchAll() as $row) {
                $permissions[$row['permission_name']] = true;
            }
        }

        /* -----------------------------
         * USER DIRECT PERMISSIONS
         * ----------------------------- */
        $stmt = $this->db->prepare("
            SELECT p.permission_name
            FROM user_permissions up
            JOIN permissions p ON p.id = up.permission_id
            WHERE up.user_id = ?
        ");

        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            $permissions[$row['permission_name']] = true;
        }

        /* -----------------------------
         * DENIED PERMISSIONS (HIGHEST PRIORITY)
         * ----------------------------- */
        $stmt = $this->db->prepare("
            SELECT p.permission_name
            FROM denied_permissions dp
            JOIN permissions p ON p.id = dp.permission_id
            WHERE dp.user_id = ?
        ");

        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            unset($permissions[$row['permission_name']]);
        }

        return $permissions;
    }

    /* =========================================================
     * CHECKS
     * ========================================================= */
    public function hasPermission(string $permission): bool
    {
        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) return false;

        $groups = $_SESSION['user']['groups'] ?? [];

        $permissions = $this->getPermissions($userId, $groups);

        return isset($permissions[$permission]);
    }

    public function hasRole(string $role): bool
    {
        return isset($_SESSION['roles'][$role]);
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function requireLogin(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit;
        }
    }

    /* =========================================================
     * LOGOUT
     * ========================================================= */
    public function logout(): void
    {
        $_SESSION = [];

        session_destroy();
    }

    /* =========================================================
     * ================= ADMIN FUNCTIONS =======================
     * ========================================================= */

    public function grantRole(int $userId, string $roleName): void
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO user_roles (user_id, role_id)
            SELECT ?, id FROM roles WHERE role_name = ?
        ");
        $stmt->execute([$userId, $roleName]);
    }

    public function revokeRole(int $userId, string $roleName): void
    {
        $stmt = $this->db->prepare("
            DELETE ur FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.role_name = ?
        ");
        $stmt->execute([$userId, $roleName]);
    }

    public function grantPermission(int $userId, string $permission): void
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO user_permissions (user_id, permission_id)
            SELECT ?, id FROM permissions WHERE permission_name = ?
        ");
        $stmt->execute([$userId, $permission]);
    }

    public function denyPermission(int $userId, string $permission): void
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO denied_permissions (user_id, permission_id)
            SELECT ?, id FROM permissions WHERE permission_name = ?
        ");
        $stmt->execute([$userId, $permission]);
    }

    public function removeDeniedPermission(int $userId, string $permission): void
    {
        $stmt = $this->db->prepare("
            DELETE dp FROM denied_permissions dp
            JOIN permissions p ON p.id = dp.permission_id
            WHERE dp.user_id = ? AND p.permission_name = ?
        ");
        $stmt->execute([$userId, $permission]);
    }

    public function getUserPermissions(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.permission_name
            FROM user_permissions up
            JOIN permissions p ON p.id = up.permission_id
            WHERE up.user_id = ?
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getUserRoles(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.role_name
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
