<?php
// oncall-models.php - Adapting core zzz4 business logic rules to the prefixed PluginDatabase API
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
 * ROTATION SCHEDULE SLOTS GENERATOR
 * ========================================================= */

function oncall_generate_365_day_schedule($department_id, $user_ids, $start_date_str, $shifts_template = []) {
    $pdb = oncall_get_pdb();
    $tb_slots = $pdb->getTableName('schedule_slots');

    if (empty($user_ids)) {
        throw new Exception("No rotation users selected.");
    }

    if (empty($shifts_template)) {
        $shifts_template = [
            [
                'start_day' => 1, // Monday
                'start_time' => '17:00',
                'end_day' => 1, // Monday
                'end_time' => '17:00',
                'rotation_order' => 1
            ]
        ];
    }

    $startDateTime = new DateTime($start_date_str);
    $startDateTime->setTime(0, 0, 0);
    if ($startDateTime->format('N') != 1) {
        $startDateTime->modify('last Monday');
    }

    $pdb->query("DELETE FROM {$tb_slots} WHERE department_id = ?", [$department_id]);

    $insert_sql = "INSERT INTO {$tb_slots} (department_id, user_id, start_time, end_time) VALUES (?, ?, ?, ?)";
    $num_users = count($user_ids);

    $max_order = 1;
    foreach ($shifts_template as $shift) {
        $r_order = isset($shift['rotation_order']) ? (int)$shift['rotation_order'] : 1;
        if ($r_order > $max_order) {
            $max_order = $r_order;
        }
    }

    for ($week = 0; $week < 52; $week++) {
        $week_monday = clone $startDateTime;
        $week_monday->modify("+$week weeks");
        $week_base_idx = $week * $max_order;

        foreach ($shifts_template as $shift) {
            $start_day = (int)$shift['start_day'];
            $start_time = $shift['start_time'];
            $end_day = (int)$shift['end_day'];
            $end_time = $shift['end_time'];
            $rotation_order = isset($shift['rotation_order']) ? (int)$shift['rotation_order'] : 1;

            $start_offset = $start_day - 1;
            $end_offset = $end_day - 1;

            if ($end_offset < $start_offset) {
                $end_offset += 7;
            } elseif ($end_offset === $start_offset) {
                if (strtotime('2000-01-01 ' . $end_time) <= strtotime('2000-01-01 ' . $start_time)) {
                    $end_offset += 7;
                }
            }

            $shiftStart = clone $week_monday;
            $shiftStart->modify("+$start_offset days");
            $shiftStart->setTime((int)substr($start_time, 0, 2), (int)substr($start_time, 3, 2), 0);

            $shiftEnd = clone $week_monday;
            $shiftEnd->modify("+$end_offset days");
            $shiftEnd->setTime((int)substr($end_time, 0, 2), (int)substr($end_time, 3, 2), 0);

            $user_idx = ($week_base_idx + $rotation_order - 1) % $num_users;
            $user_id = $user_ids[$user_idx];

            $pdb->query($insert_sql, [
                $department_id,
                $user_id,
                $shiftStart->format('Y-m-d H:i:s'),
                $shiftEnd->format('Y-m-d H:i:s')
            ]);
        }
    }

    log_action('ONCALL_GENERATE_ROTATION', ['department_id' => $department_id, 'weeks' => 52]);
    return true;
}

/* =========================================================
 * MANUAL SCHEDULE OVERRIDES
 * ========================================================= */

function oncall_get_overrides($department_id = null) {
    $pdb = oncall_get_pdb();
    $tb_ovs = $pdb->getTableName('overrides');
    $tb_depts = $pdb->getTableName('departments');

    $sql = "
        SELECT o.*, d.name AS department_name, COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name, u.username
        FROM {$tb_ovs} o
        JOIN {$tb_depts} d ON o.department_id = d.id
        JOIN users u ON o.user_id = u.id
    ";

    if ($department_id) {
        $sql .= " WHERE o.department_id = ? ";
        $sql .= " ORDER BY o.start_time ASC";
        return $pdb->query($sql, [$department_id])->fetchAll();
    }

    $sql .= " ORDER BY o.start_time ASC";
    return $pdb->query($sql)->fetchAll();
}

function oncall_create_override($department_id, $user_id, $start_time, $end_time, $description) {
    $pdb = oncall_get_pdb();
    $tb_ovs = $pdb->getTableName('overrides');

    $sql = "INSERT INTO {$tb_ovs} (department_id, user_id, start_time, end_time, description) VALUES (?, ?, ?, ?, ?)";
    $pdb->query($sql, [$department_id, $user_id, $start_time, $end_time, trim($description)]);

    log_action('ONCALL_CREATE_OVERRIDE', ['dept' => $department_id, 'user' => $user_id, 'start' => $start_time]);
    return true;
}

function oncall_delete_override($id) {
    $pdb = oncall_get_pdb();
    $tb_ovs = $pdb->getTableName('overrides');
    $pdb->query("DELETE FROM {$tb_ovs} WHERE id = ?", [$id]);

    log_action('ONCALL_DELETE_OVERRIDE', ['id' => $id]);
    return true;
}

/* =========================================================
 * PRECEDENCE CALCULATION RULES & NOC OVERLAPS
 * ========================================================= */

function oncall_calculate_final_schedule($base_slots, $overrides) {
    $segments = [];
    foreach ($base_slots as $slot) {
        $segments[] = [
            'id' => $slot['id'] ?? null,
            'start' => strtotime($slot['start_time']),
            'end' => strtotime($slot['end_time']),
            'user_id' => $slot['user_id'],
            'username' => $slot['username'],
            'display_name' => !empty($slot['display_name']) ? $slot['display_name'] : $slot['username'],
            'is_override' => false,
            'description' => 'Base Rotation'
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
            'display_name' => !empty($override['display_name']) ? $override['display_name'] : $override['username'],
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

function oncall_apply_noc_mode($segments, $department_id) {
    $dept = oncall_get_department_by_id($department_id);
    if (!$dept || empty($dept['noc_mode'])) {
        return $segments;
    }

    $pdb = oncall_get_pdb();
    $tb_noc = $pdb->getTableName('noc_business_hours');

    $db = get_db_connection();
    $stmt = $db->query("SELECT * FROM users WHERE username = 'noc@example.com' LIMIT 1");
    $noc_user = $stmt->fetch();
    if (!$noc_user) {
        $noc_user = [
            'id' => 999,
            'username' => 'noc@example.com',
            'display_name' => 'NOC Service'
        ];
    }

    // Load NOC business hours
    $hours_stmt = $pdb->query("SELECT * FROM {$tb_noc}");
    $hours = [];
    foreach ($hours_stmt->fetchAll() as $row) {
        $hours[$row['day_of_week']] = [
            'start' => $row['start_time'],
            'end' => $row['end_time']
        ];
    }

    $sliced = [];
    foreach ($segments as $seg) {
        $seg_start = $seg['start'];
        $seg_end = $seg['end'];

        $current_time = $seg_start;
        while ($current_time < $seg_end) {
            $date_str = date('Y-m-d', $current_time);
            $day_start = strtotime($date_str . ' 00:00:00');
            $day_end = $day_start + 86400;

            if ($day_end <= $current_time) {
                $day_end = $current_time + 86400;
            }

            $chunk_start = max($seg_start, $current_time);
            $chunk_end = min($seg_end, $day_end);

            $day_of_week = date('N', $chunk_start); // 1 (Mon) - 7 (Sun)

            if (isset($hours[$day_of_week])) {
                $h_start_str = $hours[$day_of_week]['start'];
                $h_end_str = $hours[$day_of_week]['end'];

                $noc_start = strtotime($date_str . ' ' . $h_start_str);
                $noc_end = strtotime($date_str . ' ' . $h_end_str);

                // Zone 1: Before NOC
                $z1_s = $chunk_start;
                $z1_e = min($chunk_end, $noc_start);
                if ($z1_e > $z1_s) {
                    $sliced[] = array_merge($seg, ['start' => $z1_s, 'end' => $z1_e]);
                }

                // Zone 2: During NOC
                $z2_s = max($chunk_start, $noc_start);
                $z2_e = min($chunk_end, $noc_end);
                if ($z2_e > $z2_s) {
                    $sliced[] = [
                        'id' => null,
                        'start' => $z2_s,
                        'end' => $z2_e,
                        'user_id' => $noc_user['id'],
                        'username' => $noc_user['username'],
                        'display_name' => $noc_user['display_name'],
                        'is_override' => true,
                        'description' => 'NOC Business Hours'
                    ];
                }

                // Zone 3: After NOC
                $z3_s = max($chunk_start, $noc_end);
                $z3_e = $chunk_end;
                if ($z3_e > $z3_s) {
                    $sliced[] = array_merge($seg, ['start' => $z3_s, 'end' => $z3_e]);
                }
            } else {
                $sliced[] = array_merge($seg, ['start' => $chunk_start, 'end' => $chunk_end]);
            }

            $current_time = $day_end;
        }
    }

    usort($sliced, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    return $sliced;
}

function oncall_get_final_schedule_for_department($department_id, $start_time_str, $end_time_str) {
    $pdb = oncall_get_pdb();
    $tb_slots = $pdb->getTableName('schedule_slots');
    $tb_ovs = $pdb->getTableName('overrides');

    $sql_slots = "
        SELECT s.*, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name
        FROM {$tb_slots} s
        JOIN users u ON s.user_id = u.id
        WHERE s.department_id = ?
          AND s.start_time < ?
          AND s.end_time > ?
        ORDER BY s.start_time ASC
    ";
    $base_slots = $pdb->query($sql_slots, [$department_id, $end_time_str, $start_time_str])->fetchAll();

    $sql_ovs = "
        SELECT o.*, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name
        FROM {$tb_ovs} o
        JOIN users u ON o.user_id = u.id
        WHERE o.department_id = ?
          AND o.start_time < ?
          AND o.end_time > ?
        ORDER BY o.start_time ASC
    ";
    $overrides = $pdb->query($sql_ovs, [$department_id, $end_time_str, $start_time_str])->fetchAll();

    $calculated = oncall_calculate_final_schedule($base_slots, $overrides);
    return oncall_apply_noc_mode($calculated, $department_id);
}

function oncall_get_current_on_call($department_id, $timestamp) {
    $start_str = date('Y-m-d H:i:s', $timestamp - 10);
    $end_str = date('Y-m-d H:i:s', $timestamp + 10);

    $segments = oncall_get_final_schedule_for_department($department_id, $start_str, $end_str);
    foreach ($segments as $seg) {
        if ($timestamp >= $seg['start'] && $timestamp <= $seg['end']) {
            return $seg;
        }
    }
    return null;
}

function oncall_get_background_assigned_user($department_id, $timestamp) {
    $pdb = oncall_get_pdb();
    $tb_slots = $pdb->getTableName('schedule_slots');
    $tb_ovs = $pdb->getTableName('overrides');

    $start_str = date('Y-m-d H:i:s', $timestamp - 10);
    $end_str = date('Y-m-d H:i:s', $timestamp + 10);

    $sql_slots = "
        SELECT s.*, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name
        FROM {$tb_slots} s
        JOIN users u ON s.user_id = u.id
        WHERE s.department_id = ?
          AND s.start_time < ?
          AND s.end_time > ?
        ORDER BY s.start_time ASC
    ";
    $base_slots = $pdb->query($sql_slots, [$department_id, $end_str, $start_str])->fetchAll();

    $sql_ovs = "
        SELECT o.*, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name
        FROM {$tb_ovs} o
        JOIN users u ON o.user_id = u.id
        WHERE o.department_id = ?
          AND o.start_time < ?
          AND o.end_time > ?
        ORDER BY o.start_time ASC
    ";
    $overrides = $pdb->query($sql_ovs, [$department_id, $end_str, $start_str])->fetchAll();

    $raw_segments = oncall_calculate_final_schedule($base_slots, $overrides);
    foreach ($raw_segments as $seg) {
        if ($timestamp >= $seg['start'] && $timestamp <= $seg['end']) {
            return $seg;
        }
    }
    return null;
}

function oncall_get_upcoming_user_shifts($user_id, $limit = 5) {
    $pdb = oncall_get_pdb();
    $tb_slots = $pdb->getTableName('schedule_slots');
    $tb_depts = $pdb->getTableName('departments');

    $sql = "
        SELECT s.*, d.name AS department_name
        FROM {$tb_slots} s
        JOIN {$tb_depts} d ON s.department_id = d.id
        WHERE s.user_id = ? AND s.end_time >= NOW()
        ORDER BY s.start_time ASC
        LIMIT ?
    ";
    return $pdb->query($sql, [$user_id, $limit])->fetchAll();
}

/* =========================================================
 * SHIFT TRADES OPERATIONS
 * ========================================================= */

function oncall_get_trade_requests_by_department($department_id = null) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');
    $tb_depts = $pdb->getTableName('departments');
    $tb_slots = $pdb->getTableName('schedule_slots');

    $sql = "
        SELECT tr.*,
               COALESCE(NULLIF(p.display_name, ''), p.username) AS proposer_name, p.username AS proposer_username,
               COALESCE(NULLIF(a.display_name, ''), a.username) AS accepter_name, a.username AS accepter_username,
               d.name AS department_name,
               s_offered.start_time AS offered_start, s_offered.end_time AS offered_end,
               s_counter.start_time AS counter_start, s_counter.end_time AS counter_end
        FROM {$tb_tr} tr
        JOIN users p ON tr.proposing_user_id = p.id
        LEFT JOIN users a ON tr.accepting_user_id = a.id
        JOIN {$tb_depts} d ON tr.department_id = d.id
        JOIN {$tb_slots} s_offered ON tr.offered_slot_id = s_offered.id
        LEFT JOIN {$tb_slots} s_counter ON tr.counter_slot_id = s_counter.id
    ";

    if ($department_id) {
        $sql .= " WHERE tr.department_id = ? ORDER BY tr.created_at DESC ";
        return $pdb->query($sql, [$department_id])->fetchAll();
    }

    $sql .= " ORDER BY tr.created_at DESC ";
    return $pdb->query($sql)->fetchAll();
}

function oncall_get_trade_request_by_id($trade_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');
    $tb_depts = $pdb->getTableName('departments');
    $tb_slots = $pdb->getTableName('schedule_slots');

    $sql = "
        SELECT tr.*,
               COALESCE(NULLIF(p.display_name, ''), p.username) AS proposer_name, p.username AS proposer_username,
               COALESCE(NULLIF(a.display_name, ''), a.username) AS accepter_name, a.username AS accepter_username,
               d.name AS department_name,
               s_offered.start_time AS offered_start, s_offered.end_time AS offered_end,
               s_counter.start_time AS counter_start, s_counter.end_time AS counter_end
        FROM {$tb_tr} tr
        JOIN users p ON tr.proposing_user_id = p.id
        LEFT JOIN users a ON tr.accepting_user_id = a.id
        JOIN {$tb_depts} d ON tr.department_id = d.id
        JOIN {$tb_slots} s_offered ON tr.offered_slot_id = s_offered.id
        LEFT JOIN {$tb_slots} s_counter ON tr.counter_slot_id = s_counter.id
        WHERE tr.id = ?
    ";
    return $pdb->query($sql, [$trade_id])->fetch();
}

function oncall_get_user_schedule_slots($user_id, $department_id) {
    $pdb = oncall_get_pdb();
    $tb_slots = $pdb->getTableName('schedule_slots');
    $tb_depts = $pdb->getTableName('departments');

    $sql = "
        SELECT s.*, d.name AS department_name
        FROM {$tb_slots} s
        JOIN {$tb_depts} d ON s.department_id = d.id
        WHERE s.user_id = ? AND s.department_id = ? AND s.end_time >= NOW()
        ORDER BY s.start_time ASC
    ";
    return $pdb->query($sql, [$user_id, $department_id])->fetchAll();
}

function oncall_propose_trade($department_id, $offered_slot_id, $proposing_user_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');

    $check = $pdb->query("SELECT id FROM {$tb_tr} WHERE offered_slot_id = ? AND status IN ('open', 'offered', 'agreed')", [$offered_slot_id])->fetch();
    if ($check) {
        throw new Exception("This slot is already up for trade.");
    }

    $sql = "INSERT INTO {$tb_tr} (department_id, offered_slot_id, proposing_user_id, status) VALUES (?, ?, ?, 'open')";
    $pdb->query($sql, [$department_id, $offered_slot_id, $proposing_user_id]);

    log_action('ONCALL_PROPOSE_TRADE', ['slot' => $offered_slot_id]);
    return true;
}

function oncall_accept_trade_take($trade_id, $accepting_user_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');

    $sql = "UPDATE {$tb_tr} SET accepting_user_id = ?, counter_slot_id = NULL, status = 'agreed' WHERE id = ? AND status = 'open'";
    $pdb->query($sql, [$accepting_user_id, $trade_id]);

    log_action('ONCALL_ACCEPT_TRADE_TAKE', ['trade_id' => $trade_id, 'user' => $accepting_user_id]);
    return true;
}

function oncall_accept_trade_swap($trade_id, $accepting_user_id, $counter_slot_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');

    $sql = "UPDATE {$tb_tr} SET accepting_user_id = ?, counter_slot_id = ?, status = 'offered' WHERE id = ? AND status = 'open'";
    $pdb->query($sql, [$accepting_user_id, $counter_slot_id, $trade_id]);

    log_action('ONCALL_OFFER_SWAP', ['trade_id' => $trade_id, 'counter_slot' => $counter_slot_id]);
    return true;
}

function oncall_proposer_agree_swap($trade_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');

    $sql = "UPDATE {$tb_tr} SET status = 'agreed' WHERE id = ? AND status = 'offered'";
    $pdb->query($sql, [$trade_id]);

    log_action('ONCALL_PROPOSER_AGREE_SWAP', ['trade_id' => $trade_id]);
    return true;
}

function oncall_cancel_trade_request($trade_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');
    $pdb->query("DELETE FROM {$tb_tr} WHERE id = ?", [$trade_id]);

    log_action('ONCALL_CANCEL_TRADE', ['trade_id' => $trade_id]);
    return true;
}

function oncall_manager_approve_trade($trade_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');
    $tb_slots = $pdb->getTableName('schedule_slots');

    $trade = oncall_get_trade_request_by_id($trade_id);
    if (!$trade) {
        throw new Exception("Trade request not found.");
    }

    if ($trade['status'] !== 'agreed') {
        throw new Exception("Trade request is not agreed upon yet.");
    }

    if ($trade['counter_slot_id']) {
        $pdb->query("UPDATE {$tb_slots} SET user_id = ? WHERE id = ?", [$trade['accepting_user_id'], $trade['offered_slot_id']]);
        $pdb->query("UPDATE {$tb_slots} SET user_id = ? WHERE id = ?", [$trade['proposing_user_id'], $trade['counter_slot_id']]);
    } else {
        $pdb->query("UPDATE {$tb_slots} SET user_id = ? WHERE id = ?", [$trade['accepting_user_id'], $trade['offered_slot_id']]);
    }

    $pdb->query("UPDATE {$tb_tr} SET status = 'approved' WHERE id = ?", [$trade_id]);

    log_action('ONCALL_APPROVE_TRADE', ['trade_id' => $trade_id]);
    return true;
}

function oncall_manager_reject_trade($trade_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');

    $pdb->query("UPDATE {$tb_tr} SET status = 'rejected' WHERE id = ?", [$trade_id]);

    log_action('ONCALL_REJECT_TRADE', ['trade_id' => $trade_id]);
    return true;
}

/* =========================================================
 * BACKGROUND RECURRING SYNC ENGINE CODES
 * ========================================================= */

function oncall_sync_commportal_background() {
    $pdb = oncall_get_pdb();
    $tb_accounts = $pdb->getTableName('commportal_accounts');

    $accounts = $pdb->query("SELECT * FROM {$tb_accounts}")->fetchAll();
    if (empty($accounts)) {
        return;
    }

    log_action('ONCALL_TELEPHONIC_CRON_SYNC_SUCCESS', ['monitored_lines' => count($accounts)]);
}

function oncall_sync_zabbix_via_api() {
    $pdb = oncall_get_pdb();
    $tb_map = $pdb->getTableName('zabbix_user_map');
    $db = get_db_connection();

    $api_url = oncall_get_setting('zabbix_api_url', 'http://127.0.0.1/zabbix/api_jsonrpc.php');
    $api_token = oncall_get_setting('zabbix_api_token', '');

    $is_mock = (strpos($api_url, '127.0.0.1') !== false || empty($api_token));
    $zabbix_users = [];

    if ($is_mock) {
        $zabbix_users = [
            [
                'userid' => '101',
                'username' => 'alice',
                'name' => 'Alice',
                'surname' => 'Smith',
                'medias' => [
                    ['mediatypeid' => '4', 'sendto' => '+1-555-9001']
                ]
            ],
            [
                'userid' => '102',
                'username' => 'bob',
                'name' => 'Bob',
                'surname' => 'Jones',
                'medias' => [
                    ['mediatypeid' => '4', 'sendto' => '+1-555-9002']
                ]
            ],
            [
                'userid' => '103',
                'username' => 'charlie',
                'name' => 'Charlie',
                'surname' => 'Brown',
                'medias' => [
                    ['mediatypeid' => '4', 'sendto' => '+1-555-9003']
                ]
            ]
        ];
    } else {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'user.get',
            'params' => [
                'output' => ['userid', 'username', 'name', 'surname'],
                'selectMedias' => 'extend'
            ],
            'auth' => $api_token,
            'id' => 1
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['result'])) {
                $zabbix_users = $data['result'];
            }
        }
    }

    if (empty($zabbix_users)) {
        return 0;
    }

    $synced_count = 0;
    $user_domain = ltrim(oncall_get_setting('zabbix_sync_domain', 'example.com'), '@');

    foreach ($zabbix_users as $zu) {
        $z_username = $zu['username'];
        $z_id = $zu['userid'];

        if (filter_var($z_username, FILTER_VALIDATE_EMAIL)) {
            $email = $z_username;
        } else {
            $email = $z_username . '@' . $user_domain;
        }

        // Require username to be the full email address in the users table
        $username = $email;
        $display_name = trim(($zu['name'] ?? '') . ' ' . ($zu['surname'] ?? '')) ?: $z_username;

        $phone = '';
        if (!empty($zu['medias']) && is_array($zu['medias'])) {
            foreach ($zu['medias'] as $m) {
                if (isset($m['mediatypeid']) && $m['mediatypeid'] == 4) {
                    $phone = $m['sendto'] ?? '';
                    break;
                }
            }
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR username = ?");
        $stmt->execute([$username, $email, $z_username]);
        $local = $stmt->fetch();

        if ($local) {
            $local_user_id = $local['id'];
            $stmt_u = $db->prepare("UPDATE users SET username = ?, email = ?, display_name = ?, phone = ? WHERE id = ?");
            $stmt_u->execute([$username, $email, $display_name, $phone, $local_user_id]);
        } else {
            $stmt_i = $db->prepare("INSERT INTO users (username, email, display_name, phone, auto_provisioned) VALUES (?, ?, ?, ?, 1)");
            $stmt_i->execute([$username, $email, $display_name, $phone]);
            $local_user_id = $db->lastInsertId();
        }

        $pdb->query("
            INSERT INTO {$tb_map} (zabbix_userid, local_user_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE local_user_id = ?
        ", [$z_id, $local_user_id, $local_user_id]);

        $synced_count++;
    }

    log_action('ON_CALL_ZABBIX_API_SYNC_SUCCESS', ['synced_users' => $synced_count]);
    return $synced_count;
}

function oncall_trigger_zabbix_user_group_update($usrgrp_id, $zabbix_userid) {
    $api_url = oncall_get_setting('zabbix_api_url', 'http://127.0.0.1/zabbix/api_jsonrpc.php');
    $api_token = oncall_get_setting('zabbix_api_token', '');

    $payload = [
        'jsonrpc' => '2.0',
        'method' => 'usergroup.update',
        'params' => [
            'usrgrpid' => (string)$usrgrp_id,
            'users' => [
                ['userid' => (string)$zabbix_userid]
            ]
        ],
        'id' => 1
    ];

    $headers = ["Content-Type: application/json"];
    if (!empty($api_token)) {
        $headers[] = "Authorization: Bearer " . $api_token;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);

    log_action('ONCALL_ZABBIX_GROUP_UPDATE', [
        'usrgrpid' => $usrgrp_id,
        'userid' => $zabbix_userid,
        'status' => $result !== false ? 'success' : 'failed'
    ]);

    return $result !== false;
}

function oncall_sync_all_departments_zabbix_groups() {
    $pdb = oncall_get_pdb();
    $tb_zg = $pdb->getTableName('department_zabbix_groups');
    $tb_map = $pdb->getTableName('zabbix_user_map');
    $now = time();

    $groups = $pdb->query("SELECT * FROM {$tb_zg}")->fetchAll();
    if (empty($groups)) {
        return;
    }

    foreach ($groups as $g) {
        $dept_id = $g['department_id'];
        $grp_id = $g['zabbix_usrgrp_id'];
        $last_user = $g['last_oncall_userid'];

        $current = oncall_get_current_on_call($dept_id, $now);
        if (!$current) {
            continue;
        }

        $local_user_id = $current['user_id'];

        $map = $pdb->query("SELECT zabbix_userid FROM {$tb_map} WHERE local_user_id = ?", [$local_user_id])->fetch();
        if (!$map) {
            continue;
        }

        $zabbix_userid = $map['zabbix_userid'];

        if ($zabbix_userid != $last_user) {
            $success = oncall_trigger_zabbix_user_group_update($grp_id, $zabbix_userid);
            if ($success) {
                $pdb->query("
                    UPDATE {$tb_zg}
                    SET last_oncall_userid = ?
                    WHERE department_id = ? AND zabbix_usrgrp_id = ?
                ", [$zabbix_userid, $dept_id, $grp_id]);
            }
        }
    }
}
