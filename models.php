<?php
// models.php - Database Operations and Business Logic
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- VERBOSE AUDIT LOGGING ---

function log_action($action, $details) {
    try {
        $db = get_oncall_db();
        $user_id = $_SESSION['user_id'] ?? null;
        $username = null;
        if ($user_id) {
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $res = $stmt->fetch();
            $username = $res ? $res['username'] : null;
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (is_array($details) || is_object($details)) {
            $details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, username, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $username, $action, $details, $ip_address]);
    } catch (Exception $e) {
        // Prevent logging errors from crashing the main application flow
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

function get_audit_logs($limit = 200) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        SELECT al.*, u.name, u.surname
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}


// --- AUTH & PERMISSION CHECKS ---

function has_permission($permission) {
    global $auth;
    if (!isset($auth)) {
        require_once 'Auth.php';
        $config = require 'config.php';
        $auth = new Auth($config);
    }
    return $auth->hasPermission($permission);
}

function is_department_manager($department_id) {
    $current_user_id = $_SESSION['user_id'] ?? null;
    if (!$current_user_id) return false;

    $dept = get_department_by_id($department_id);
    return $dept && $dept['manager_user_id'] == $current_user_id;
}

function can_manage_department($department_id) {
    return has_permission('manage_departments') || is_department_manager($department_id);
}

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}


function get_setting($key, $default = null) {
    try {
        $db = get_oncall_db();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}


// --- ZABBIX USER SYNC ---

function sync_zabbix_users() {
    $zabbix_db = get_zabbix_db();
    $oncall_db = get_oncall_db();

    // Fetch users from Zabbix
    $stmt = $zabbix_db->query("SELECT userid, username, name, surname FROM users");
    $zabbix_users = $stmt->fetchAll();

    $synced_count = 0;
    $domain = get_setting('zabbix_default_domain', 'example.com');

    foreach ($zabbix_users as $z_user) {
        $check_stmt = $oncall_db->prepare("SELECT id FROM users WHERE zabbix_userid = ?");
        $check_stmt->execute([$z_user['userid']]);
        $existing = $check_stmt->fetch();

        $email = $z_user['username'] . '@' . $domain;

        if ($existing) {
            $update_stmt = $oncall_db->prepare("
                UPDATE users
                SET username = ?, name = ?, surname = ?, email = ?
                WHERE zabbix_userid = ?
            ");
            $update_stmt->execute([
                $email, // Use full email for username to align with Azure AD
                $z_user['name'],
                $z_user['surname'],
                $email,
                $z_user['userid']
            ]);
        } else {
            $insert_stmt = $oncall_db->prepare("
                INSERT INTO users (zabbix_userid, username, name, surname, email)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $z_user['userid'],
                $email, // Use full email for username to align with Azure AD
                $z_user['name'],
                $z_user['surname'],
                $email
            ]);
            $synced_count++;
        }
    }

    log_action('SYNC_USERS', [
        'source' => 'zabbix_mysql_db',
        'total_fetched_users' => count($zabbix_users),
        'new_users_imported' => $synced_count
    ]);

    return count($zabbix_users);
}

function get_all_users() {
    $db = get_oncall_db();
    $stmt = $db->query("SELECT * FROM users ORDER BY name ASC, surname ASC");
    return $stmt->fetchAll();
}

function get_user_by_id($id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}


// --- DEPARTMENTS ---

function get_all_departments() {
    $db = get_oncall_db();
    $stmt = $db->query("
        SELECT d.*, u.username AS manager_username, u.name AS manager_name, u.surname AS manager_surname
        FROM departments d
        LEFT JOIN users u ON d.manager_user_id = u.id
        ORDER BY d.name ASC
    ");
    return $stmt->fetchAll();
}

function get_department_by_id($id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function create_department($name, $manager_user_id = null) {
    $db = get_oncall_db();
    $stmt = $db->prepare("INSERT INTO departments (name, manager_user_id) VALUES (?, ?)");
    $res = $stmt->execute([trim($name), $manager_user_id ?: null]);
    $dept_id = $db->lastInsertId();

    log_action('CREATE_DEPARTMENT', [
        'department_id' => $dept_id,
        'department_name' => $name,
        'manager_user_id' => $manager_user_id
    ]);

    return $res;
}

function update_department_manager($department_id, $manager_user_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("UPDATE departments SET manager_user_id = ? WHERE id = ?");
    $res = $stmt->execute([$manager_user_id ?: null, $department_id]);

    log_action('UPDATE_DEPARTMENT_MANAGER', [
        'department_id' => $department_id,
        'manager_user_id' => $manager_user_id
    ]);

    return $res;
}

function delete_department($id) {
    $dept = get_department_by_id($id);
    $db = get_oncall_db();
    $stmt = $db->prepare("DELETE FROM departments WHERE id = ?");
    $res = $stmt->execute([$id]);

    log_action('DELETE_DEPARTMENT', [
        'department_id' => $id,
        'department_name' => $dept ? $dept['name'] : 'Unknown'
    ]);

    return $res;
}

function get_department_users($department_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        SELECT u.*
        FROM users u
        JOIN department_users du ON u.id = du.user_id
        WHERE du.department_id = ?
        ORDER BY u.name ASC, u.surname ASC
    ");
    $stmt->execute([$department_id]);
    return $stmt->fetchAll();
}

function save_department_users($department_id, $user_ids) {
    $db = get_oncall_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("DELETE FROM department_users WHERE department_id = ?");
        $stmt->execute([$department_id]);

        if (!empty($user_ids)) {
            $stmt = $db->prepare("INSERT INTO department_users (department_id, user_id) VALUES (?, ?)");
            foreach ($user_ids as $u_id) {
                $stmt->execute([$department_id, $u_id]);
            }
        }
        $db->commit();

        log_action('UPDATE_DEPARTMENT_MEMBERS', [
            'department_id' => $department_id,
            'user_count' => count($user_ids),
            'user_ids' => $user_ids
        ]);

        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}


// --- SCHEDULE GENERATOR ---

function generate_365_day_schedule($department_id, $user_ids, $start_date_str) {
    $db = get_oncall_db();

    if (empty($user_ids)) {
        throw new Exception("No users selected for the rotation.");
    }

    $startDateTime = new DateTime($start_date_str);
    $startDateTime->setTime(17, 0, 0);
    if ($startDateTime->format('N') != 1) {
        $startDateTime->modify('last Monday');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("DELETE FROM schedule_slots WHERE department_id = ?");
        $stmt->execute([$department_id]);

        $stmt = $db->prepare("
            INSERT INTO schedule_slots (department_id, user_id, start_time, end_time)
            VALUES (?, ?, ?, ?)
        ");

        $num_users = count($user_ids);
        for ($week = 0; $week < 52; $week++) {
            $shiftStart = clone $startDateTime;
            $shiftStart->modify("+$week weeks");

            $shiftEnd = clone $shiftStart;
            $shiftEnd->modify("+1 week");

            $user_id = $user_ids[$week % $num_users];

            $stmt->execute([
                $department_id,
                $user_id,
                $shiftStart->format('Y-m-d H:i:s'),
                $shiftEnd->format('Y-m-d H:i:s')
            ]);
        }

        $db->commit();

        log_action('GENERATE_ROTATION_SCHEDULE', [
            'department_id' => $department_id,
            'start_date' => $startDateTime->format('Y-m-d H:i:s'),
            'weeks_generated' => 52,
            'rotation_order' => $user_ids
        ]);

        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}


// --- OVERRIDES ---

function get_overrides($department_id = null, $user_id = null) {
    $db = get_oncall_db();
    $sql = "
        SELECT o.*, d.name AS department_name, u.username, u.name, u.surname
        FROM overrides o
        JOIN departments d ON o.department_id = d.id
        JOIN users u ON o.user_id = u.id
    ";
    $conditions = [];
    $params = [];

    if ($department_id) {
        $conditions[] = "o.department_id = ?";
        $params[] = $department_id;
    }
    if ($user_id) {
        $conditions[] = "o.user_id = ?";
        $params[] = $user_id;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY o.start_time ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_override_by_id($id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("SELECT * FROM overrides WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function create_override($department_id, $user_id, $start_time, $end_time, $description) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        INSERT INTO overrides (department_id, user_id, start_time, end_time, description)
        VALUES (?, ?, ?, ?, ?)
    ");
    $res = $stmt->execute([
        $department_id,
        $user_id,
        $start_time,
        $end_time,
        trim($description)
    ]);

    log_action('CREATE_OVERRIDE', [
        'department_id' => $department_id,
        'user_id' => $user_id,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'description' => $description
    ]);

    return $res;
}

function update_override($id, $department_id, $user_id, $start_time, $end_time, $description) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        UPDATE overrides
        SET department_id = ?, user_id = ?, start_time = ?, end_time = ?, description = ?
        WHERE id = ?
    ");
    $res = $stmt->execute([
        $department_id,
        $user_id,
        $start_time,
        $end_time,
        trim($description),
        $id
    ]);

    log_action('UPDATE_OVERRIDE', [
        'override_id' => $id,
        'department_id' => $department_id,
        'user_id' => $user_id,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'description' => $description
    ]);

    return $res;
}

function delete_override($id) {
    $ov = get_override_by_id($id);
    $db = get_oncall_db();
    $stmt = $db->prepare("DELETE FROM overrides WHERE id = ?");
    $res = $stmt->execute([$id]);

    log_action('DELETE_OVERRIDE', [
        'override_id' => $id,
        'department_id' => $ov ? $ov['department_id'] : null,
        'overridden_user_id' => $ov ? $ov['user_id'] : null
    ]);

    return $res;
}


// --- SCHEDULE CALCULATIONS & PRECEDENCE LOGIC ---

function calculate_final_schedule($base_slots, $overrides) {
    $segments = [];
    foreach ($base_slots as $slot) {
        $segments[] = [
            'id' => $slot['id'] ?? null,
            'start' => strtotime($slot['start_time']),
            'end' => strtotime($slot['end_time']),
            'user_id' => $slot['user_id'],
            'username' => $slot['username'],
            'name' => $slot['name'],
            'surname' => $slot['surname'],
            'is_override' => false,
            'description' => 'Base Schedule'
        ];
    }

    usort($overrides, function($a, $b) {
        return $a['id'] <=> $b['id'];
    });

    foreach ($overrides as $override) {
        $o_start = strtotime($override['start_time']);
        $o_end = strtotime($override['end_time']);
        $new_segments = [];

        foreach ($segments as $seg) {
            $s_start = $seg['start'];
            $s_end = $seg['end'];

            if ($s_end <= $o_start || $s_start >= $o_end) {
                $new_segments[] = $seg;
            } else {
                if ($s_start < $o_start) {
                    $left = $seg;
                    $left['end'] = $o_start;
                    $new_segments[] = $left;
                }
                if ($s_end > $o_end) {
                    $right = $seg;
                    $right['start'] = $o_end;
                    $new_segments[] = $right;
                }
            }
        }

        $new_segments[] = [
            'id' => null,
            'start' => $o_start,
            'end' => $o_end,
            'user_id' => $override['user_id'],
            'username' => $override['username'],
            'name' => $override['name'],
            'surname' => $override['surname'],
            'is_override' => true,
            'description' => $override['description'] ?: 'Manual Override'
        ];

        $segments = $new_segments;
    }

    $segments = array_filter($segments, function($seg) {
        return $seg['end'] > $seg['start'];
    });

    usort($segments, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    return array_values($segments);
}

function get_final_schedule_for_department($department_id, $start_time_str, $end_time_str) {
    $db = get_oncall_db();

    $stmt = $db->prepare("
        SELECT s.*, u.username, u.name, u.surname
        FROM schedule_slots s
        JOIN users u ON s.user_id = u.id
        WHERE s.department_id = ?
          AND s.start_time < ?
          AND s.end_time > ?
        ORDER BY s.start_time ASC
    ");
    $stmt->execute([$department_id, $end_time_str, $start_time_str]);
    $base_slots = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT o.*, u.username, u.name, u.surname
        FROM overrides o
        JOIN users u ON o.user_id = u.id
        WHERE o.department_id = ?
          AND o.start_time < ?
          AND o.end_time > ?
        ORDER BY o.start_time ASC
    ");
    $stmt->execute([$department_id, $end_time_str, $start_time_str]);
    $overrides = $stmt->fetchAll();

    return calculate_final_schedule($base_slots, $overrides);
}

function get_final_schedule_for_user($user_id, $start_time_str, $end_time_str) {
    $db = get_oncall_db();

    $stmt = $db->prepare("
        SELECT department_id FROM department_users WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $depts = $stmt->fetchAll();

    $user_segments = [];
    foreach ($depts as $dept) {
        $dept_id = $dept['department_id'];
        $dept_info = get_department_by_id($dept_id);
        $dept_name = $dept_info ? $dept_info['name'] : 'Unknown';

        $segments = get_final_schedule_for_department($dept_id, $start_time_str, $end_time_str);
        foreach ($segments as $seg) {
            if ($seg['user_id'] == $user_id) {
                $seg['department_id'] = $dept_id;
                $seg['department_name'] = $dept_name;
                $user_segments[] = $seg;
            }
        }
    }

    usort($user_segments, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    return $user_segments;
}


// --- SHIFT TRADES OPERATIONS ---

function get_trade_requests_by_department($department_id = null) {
    $db = get_oncall_db();
    $sql = "
        SELECT tr.*,
               p.name AS proposer_name, p.surname AS proposer_surname, p.username AS proposer_username,
               a.name AS accepter_name, a.surname AS accepter_surname, a.username AS accepter_username,
               d.name AS department_name,
               s_offered.start_time AS offered_start, s_offered.end_time AS offered_end,
               s_counter.start_time AS counter_start, s_counter.end_time AS counter_end
        FROM trade_requests tr
        JOIN users p ON tr.proposing_user_id = p.id
        LEFT JOIN users a ON tr.accepting_user_id = a.id
        JOIN departments d ON tr.department_id = d.id
        JOIN schedule_slots s_offered ON tr.offered_slot_id = s_offered.id
        LEFT JOIN schedule_slots s_counter ON tr.counter_slot_id = s_counter.id
    ";
    $params = [];
    if ($department_id) {
        $sql .= " WHERE tr.department_id = ? ";
        $params[] = $department_id;
    }
    $sql .= " ORDER BY tr.created_at DESC ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_trade_request_by_id($trade_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        SELECT tr.*,
               p.name AS proposer_name, p.surname AS proposer_surname, p.username AS proposer_username,
               a.name AS accepter_name, a.surname AS accepter_surname, a.username AS accepter_username,
               d.name AS department_name,
               s_offered.start_time AS offered_start, s_offered.end_time AS offered_end,
               s_counter.start_time AS counter_start, s_counter.end_time AS counter_end
        FROM trade_requests tr
        JOIN users p ON tr.proposing_user_id = p.id
        LEFT JOIN users a ON tr.accepting_user_id = a.id
        JOIN departments d ON tr.department_id = d.id
        JOIN schedule_slots s_offered ON tr.offered_slot_id = s_offered.id
        LEFT JOIN schedule_slots s_counter ON tr.counter_slot_id = s_counter.id
        WHERE tr.id = ?
    ");
    $stmt->execute([$trade_id]);
    return $stmt->fetch();
}

function get_user_schedule_slots($user_id, $department_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        SELECT s.*, d.name AS department_name
        FROM schedule_slots s
        JOIN departments d ON s.department_id = d.id
        WHERE s.user_id = ? AND s.department_id = ? AND s.start_time >= NOW()
        ORDER BY s.start_time ASC
    ");
    $stmt->execute([$user_id, $department_id]);
    return $stmt->fetchAll();
}

function propose_trade($department_id, $offered_slot_id, $proposing_user_id) {
    $db = get_oncall_db();

    $stmt = $db->prepare("SELECT id FROM trade_requests WHERE offered_slot_id = ? AND status IN ('open', 'offered', 'agreed')");
    $stmt->execute([$offered_slot_id]);
    if ($stmt->fetch()) {
        throw new Exception("This slot is already up for trade or has a pending request.");
    }

    $stmt = $db->prepare("
        INSERT INTO trade_requests (department_id, offered_slot_id, proposing_user_id, status)
        VALUES (?, ?, ?, 'open')
    ");
    $res = $stmt->execute([$department_id, $offered_slot_id, $proposing_user_id]);

    log_action('PROPOSE_SHIFT_TRADE', [
        'department_id' => $department_id,
        'offered_slot_id' => $offered_slot_id,
        'proposing_user_id' => $proposing_user_id
    ]);

    return $res;
}

function accept_trade_take($trade_id, $accepting_user_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        UPDATE trade_requests
        SET accepting_user_id = ?, counter_slot_id = NULL, status = 'agreed'
        WHERE id = ? AND status = 'open'
    ");
    $res = $stmt->execute([$accepting_user_id, $trade_id]);

    log_action('ACCEPT_TRADE_TAKE', [
        'trade_id' => $trade_id,
        'accepting_user_id' => $accepting_user_id
    ]);

    return $res;
}

function accept_trade_swap($trade_id, $accepting_user_id, $counter_slot_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        UPDATE trade_requests
        SET accepting_user_id = ?, counter_slot_id = ?, status = 'offered'
        WHERE id = ? AND status = 'open'
    ");
    $res = $stmt->execute([$accepting_user_id, $counter_slot_id, $trade_id]);

    log_action('OFFER_TRADE_SWAP', [
        'trade_id' => $trade_id,
        'accepting_user_id' => $accepting_user_id,
        'counter_slot_id' => $counter_slot_id
    ]);

    return $res;
}

function proposer_agree_swap($trade_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        UPDATE trade_requests
        SET status = 'agreed'
        WHERE id = ? AND status = 'offered'
    ");
    $res = $stmt->execute([$trade_id]);

    log_action('PROPOSER_AGREE_SWAP', [
        'trade_id' => $trade_id
    ]);

    return $res;
}

function cancel_trade_request($trade_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("DELETE FROM trade_requests WHERE id = ?");
    $res = $stmt->execute([$trade_id]);

    log_action('CANCEL_SHIFT_TRADE', [
        'trade_id' => $trade_id
    ]);

    return $res;
}

function manager_approve_trade($trade_id, $manager_user_id) {
    $db = get_oncall_db();
    $trade = get_trade_request_by_id($trade_id);
    if (!$trade) {
        throw new Exception("Trade request not found.");
    }

    if (!can_manage_department($trade['department_id'])) {
        throw new Exception("Unauthorized: Only the department manager or admin can approve trades.");
    }

    if ($trade['status'] !== 'agreed') {
        throw new Exception("Trade request is not in agreed state.");
    }

    $db->beginTransaction();
    try {
        if ($trade['counter_slot_id']) {
            $stmt = $db->prepare("UPDATE schedule_slots SET user_id = ? WHERE id = ?");
            $stmt->execute([$trade['accepting_user_id'], $trade['offered_slot_id']]);

            $stmt = $db->prepare("UPDATE schedule_slots SET user_id = ? WHERE id = ?");
            $stmt->execute([$trade['proposing_user_id'], $trade['counter_slot_id']]);
        } else {
            $stmt = $db->prepare("UPDATE schedule_slots SET user_id = ? WHERE id = ?");
            $stmt->execute([$trade['accepting_user_id'], $trade['offered_slot_id']]);
        }

        $stmt = $db->prepare("UPDATE trade_requests SET status = 'approved' WHERE id = ?");
        $stmt->execute([$trade_id]);

        $db->commit();

        log_action('MANAGER_APPROVE_TRADE', [
            'trade_id' => $trade_id,
            'department_id' => $trade['department_id'],
            'proposing_user_id' => $trade['proposing_user_id'],
            'accepting_user_id' => $trade['accepting_user_id'],
            'counter_slot_id' => $trade['counter_slot_id']
        ]);

        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function manager_reject_trade($trade_id, $manager_user_id) {
    $db = get_oncall_db();
    $trade = get_trade_request_by_id($trade_id);
    if (!$trade) {
         throw new Exception("Trade request not found.");
    }

    if (!can_manage_department($trade['department_id'])) {
        throw new Exception("Unauthorized: Only the department manager or admin can reject trades.");
    }

    $stmt = $db->prepare("UPDATE trade_requests SET status = 'rejected' WHERE id = ?");
    $res = $stmt->execute([$trade_id]);

    log_action('MANAGER_REJECT_TRADE', [
        'trade_id' => $trade_id,
        'department_id' => $trade['department_id']
    ]);

    return $res;
}
