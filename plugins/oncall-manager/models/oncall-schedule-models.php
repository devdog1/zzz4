<?php
// oncall-schedule-models.php - Shift Generator, Schedule Calculations, Overrides, and NOC Business Hours

/* =========================================================
 * 365-DAY ROTATION SHIFT GENERATOR ENGINE
 * ========================================================= */

function oncall_generate_365_day_schedule($department_id, $user_ids, $start_date_str, $shifts_template = []) {
    $pdb = oncall_get_pdb();
    $tb_slots = $pdb->getTableName('schedule_slots');

    if (empty($user_ids)) {
        throw new Exception("Cannot generate schedule: Department user roster is empty.");
    }

    $start_dt = new DateTime($start_date_str);

    // Default 1-week rotation template (Monday 09:00 -> Next Monday 09:00)
    if (empty($shifts_template)) {
        $shifts_template = [
            [
                'start_day_offset' => 0,
                'start_time' => '09:00:00',
                'end_day_offset' => 7,
                'end_time' => '09:00:00'
            ]
        ];
    }

    // Delete existing base slots from start date onwards for this department
    $pdb->query("DELETE FROM {$tb_slots} WHERE department_id = ? AND start_time >= ?", [
        $department_id,
        $start_dt->format('Y-m-d 00:00:00')
    ]);

    $inserted_count = 0;
    $user_count = count($user_ids);
    $user_index = 0;

    // Generate 52 weeks (365 days) into future
    $current_week_start = clone $start_dt;
    for ($week = 0; $week < 52; $week++) {
        $assigned_user_id = $user_ids[$user_index % $user_count];

        foreach ($shifts_template as $tpl) {
            $s_time = clone $current_week_start;
            $s_time->modify('+' . $tpl['start_day_offset'] . ' days');
            $s_parts = explode(':', $tpl['start_time']);
            $s_time->setTime((int)$s_parts[0], (int)$s_parts[1], (int)($s_parts[2] ?? 0));

            $e_time = clone $current_week_start;
            $e_time->modify('+' . $tpl['end_day_offset'] . ' days');
            $e_parts = explode(':', $tpl['end_time']);
            $e_time->setTime((int)$e_parts[0], (int)$e_parts[1], (int)($e_parts[2] ?? 0));

            $sql = "INSERT INTO {$tb_slots} (department_id, user_id, start_time, end_time) VALUES (?, ?, ?, ?)";
            $pdb->query($sql, [
                $department_id,
                $assigned_user_id,
                $s_time->format('Y-m-d H:i:s'),
                $e_time->format('Y-m-d H:i:s')
            ]);
            $inserted_count++;
        }

        $current_week_start->modify('+7 days');
        $user_index++;
    }

    log_action('ONCALL_GENERATE_365_SCHEDULE', [
        'department_id' => $department_id,
        'start_date' => $start_date_str,
        'slots_created' => $inserted_count
    ]);

    return $inserted_count;
}

/* =========================================================
 * MANUAL OVERRIDES ENGINE
 * ========================================================= */

function oncall_get_overrides($department_id = null) {
    $pdb = oncall_get_pdb();
    $tb_ovs = $pdb->getTableName('overrides');
    $tb_depts = $pdb->getTableName('departments');

    if ($department_id) {
        $sql = "
            SELECT o.*, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name, d.name AS department_name
            FROM {$tb_ovs} o
            JOIN users u ON o.user_id = u.id
            JOIN {$tb_depts} d ON o.department_id = d.id
            WHERE o.department_id = ?
            ORDER BY o.start_time DESC
        ";
        return $pdb->query($sql, [$department_id])->fetchAll();
    } else {
        $sql = "
            SELECT o.*, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name, d.name AS department_name
            FROM {$tb_ovs} o
            JOIN users u ON o.user_id = u.id
            JOIN {$tb_depts} d ON o.department_id = d.id
            ORDER BY o.start_time DESC
        ";
        return $pdb->query($sql)->fetchAll();
    }
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
