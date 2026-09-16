<?php
// oncall-core-models.php - Core Database Helpers, Settings, and Department Logic
if (file_exists(__DIR__ . '/../../PluginDatabase.php')) {
    require_once __DIR__ . '/../../PluginDatabase.php';
} elseif (file_exists(__DIR__ . '/../../../PluginDatabase.php')) {
    require_once __DIR__ . '/../../../PluginDatabase.php';
}

function oncall_get_pdb() {
    return new PluginDatabase('oncall-manager');
}

/* =========================================================
 * CORE HELPERS
 * ========================================================= */

function oncall_is_department_manager($department_id) {
    $current_user_id = $_SESSION['user_id'] ?? null;
    if (!$current_user_id) return false;

    $dept = oncall_get_department_by_id($department_id);
    return $dept && $dept['manager_user_id'] == $current_user_id;
}

function oncall_can_manage_department($department_id) {
    return has_permission('manage_settings') || oncall_is_department_manager($department_id);
}

/* =========================================================
 * PLUGIN SPECIFIC SETTINGS API
 * ========================================================= */

function oncall_get_setting($key, $default = null) {
    try {
        $pdb = oncall_get_pdb();
        $tb_settings = $pdb->getTableName('settings');
        $stmt = $pdb->query("SELECT setting_value FROM {$tb_settings} WHERE setting_key = ?", [$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function oncall_set_setting($key, $value) {
    try {
        $pdb = oncall_get_pdb();
        $tb_settings = $pdb->getTableName('settings');
        $pdb->query("
            INSERT INTO {$tb_settings} (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
        ", [$key, $value, $value]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* =========================================================
 * DEPARTMENTS
 * ========================================================= */

function oncall_get_all_departments() {
    $pdb = oncall_get_pdb();
    $tb_depts = $pdb->getTableName('departments');

    $sql = "
        SELECT d.*, u.username AS manager_username, COALESCE(NULLIF(u.display_name, ''), u.username) AS manager_name
        FROM {$tb_depts} d
        LEFT JOIN users u ON d.manager_user_id = u.id
        ORDER BY d.name ASC
    ";
    return $pdb->query($sql)->fetchAll();
}

function oncall_get_department_by_id($id) {
    $pdb = oncall_get_pdb();
    $tb_depts = $pdb->getTableName('departments');
    $sql = "SELECT * FROM {$tb_depts} WHERE id = ?";
    return $pdb->query($sql, [$id])->fetch();
}

function oncall_create_department($name, $manager_user_id = null) {
    $pdb = oncall_get_pdb();
    $tb_depts = $pdb->getTableName('departments');
    $sql = "INSERT INTO {$tb_depts} (name, manager_user_id) VALUES (?, ?)";
    $pdb->query($sql, [trim($name), $manager_user_id ?: null]);

    log_action('ONCALL_CREATE_DEPARTMENT', ['name' => $name, 'manager' => $manager_user_id]);
    return true;
}

function oncall_update_department($id, $name, $manager_user_id, $noc_mode = 0) {
    $pdb = oncall_get_pdb();
    $tb_depts = $pdb->getTableName('departments');
    $sql = "UPDATE {$tb_depts} SET name = ?, manager_user_id = ?, noc_mode = ? WHERE id = ?";
    $pdb->query($sql, [trim($name), $manager_user_id ?: null, (int)$noc_mode, $id]);

    log_action('ONCALL_UPDATE_DEPARTMENT', ['id' => $id, 'name' => $name]);
    return true;
}

function oncall_delete_department($id) {
    $pdb = oncall_get_pdb();
    $tb_depts = $pdb->getTableName('departments');
    $pdb->query("DELETE FROM {$tb_depts} WHERE id = ?", [$id]);

    log_action('ONCALL_DELETE_DEPARTMENT', ['id' => $id]);
    return true;
}

function oncall_get_department_users($department_id) {
    $pdb = oncall_get_pdb();
    $tb_du = $pdb->getTableName('department_users');
    $sql = "
        SELECT u.id, u.username, u.email, COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name
        FROM users u
        JOIN {$tb_du} du ON u.id = du.user_id
        WHERE du.department_id = ?
        ORDER BY display_name ASC
    ";
    return $pdb->query($sql, [$department_id])->fetchAll();
}

function oncall_save_department_users($department_id, $user_ids) {
    $pdb = oncall_get_pdb();
    $tb_du = $pdb->getTableName('department_users');

    $pdb->query("DELETE FROM {$tb_du} WHERE department_id = ?", [$department_id]);

    if (!empty($user_ids)) {
        $sql = "INSERT INTO {$tb_du} (department_id, user_id) VALUES (?, ?)";
        foreach ($user_ids as $u_id) {
            $pdb->query($sql, [$department_id, (int)$u_id]);
        }
    }

    log_action('ONCALL_UPDATE_MEMBERS', ['department_id' => $department_id, 'users' => $user_ids]);
    return true;
}

/* =========================================================
 * DEPARTMENT ZABBIX GROUP MAPPINGS
 * ========================================================= */

function oncall_get_department_zabbix_groups($department_id) {
    $pdb = oncall_get_pdb();
    $tb_zg = $pdb->getTableName('department_zabbix_groups');
    $sql = "SELECT zabbix_usrgrp_id FROM {$tb_zg} WHERE department_id = ?";
    return $pdb->query($sql, [$department_id])->fetchAll(PDO::FETCH_COLUMN);
}

function oncall_save_department_zabbix_groups($department_id, $zabbix_usrgrp_ids) {
    $pdb = oncall_get_pdb();
    $tb_zg = $pdb->getTableName('department_zabbix_groups');

    $pdb->query("DELETE FROM {$tb_zg} WHERE department_id = ?", [$department_id]);

    if (!empty($zabbix_usrgrp_ids)) {
        $sql = "INSERT INTO {$tb_zg} (department_id, zabbix_usrgrp_id) VALUES (?, ?)";
        foreach ($zabbix_usrgrp_ids as $grp_id) {
            if (!empty($grp_id)) {
                $pdb->query($sql, [$department_id, (int)$grp_id]);
            }
        }
    }

    log_action('ONCALL_UPDATE_DEPT_ZABBIX_GROUPS', ['department_id' => $department_id, 'groups' => $zabbix_usrgrp_ids]);
    return true;
}

/* =========================================================
 * UNIFIED ROTATION CHANGE SYNC (ZABBIX & COMMPORTAL)
 * ========================================================= */

function oncall_sync_rotation_changes($department_id = null) {
    if (function_exists('oncall_sync_all_departments_zabbix_groups')) {
        oncall_sync_all_departments_zabbix_groups();
    }
    if (function_exists('oncall_sync_commportal_background')) {
        if ($department_id) {
            oncall_sync_department_commportal_forwarding($department_id);
        } else {
            oncall_sync_commportal_background();
        }
    }
}
