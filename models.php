<?php
// models.php - Database Operations and Business Logic
require_once 'db.php';

// --- ZABBIX USER SYNC ---

function sync_zabbix_users() {
    $zabbix_db = get_zabbix_db();
    $oncall_db = get_oncall_db();

    // Fetch users from Zabbix
    $stmt = $zabbix_db->query("SELECT userid, username, name, surname FROM users");
    $zabbix_users = $stmt->fetchAll();

    $synced_count = 0;
    foreach ($zabbix_users as $z_user) {
        // Check if user already exists in oncall_system
        $check_stmt = $oncall_db->prepare("SELECT id FROM users WHERE zabbix_userid = ?");
        $check_stmt->execute([$z_user['userid']]);
        $existing = $check_stmt->fetch();

        if ($existing) {
            // Update details
            $update_stmt = $oncall_db->prepare("
                UPDATE users
                SET username = ?, name = ?, surname = ?
                WHERE zabbix_userid = ?
            ");
            $update_stmt->execute([
                $z_user['username'],
                $z_user['name'],
                $z_user['surname'],
                $z_user['userid']
            ]);
        } else {
            // Insert user
            // We default email to username@example.com
            $email = $z_user['username'] . '@example.com';
            $insert_stmt = $oncall_db->prepare("
                INSERT INTO users (zabbix_userid, username, name, surname, email)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $z_user['userid'],
                $z_user['username'],
                $z_user['name'],
                $z_user['surname'],
                $email
            ]);
            $synced_count++;
        }
    }
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
    $stmt = $db->query("SELECT * FROM departments ORDER BY name ASC");
    return $stmt->fetchAll();
}

function get_department_by_id($id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function create_department($name) {
    $db = get_oncall_db();
    $stmt = $db->prepare("INSERT INTO departments (name) VALUES (?)");
    return $stmt->execute([trim($name)]);
}

function delete_department($id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("DELETE FROM departments WHERE id = ?");
    return $stmt->execute([$id]);
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
        // Clear current users
        $stmt = $db->prepare("DELETE FROM department_users WHERE department_id = ?");
        $stmt->execute([$department_id]);

        // Insert new associations
        if (!empty($user_ids)) {
            $stmt = $db->prepare("INSERT INTO department_users (department_id, user_id) VALUES (?, ?)");
            foreach ($user_ids as $u_id) {
                $stmt->execute([$department_id, $u_id]);
            }
        }
        $db->commit();
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

    // Align start_date to Monday at 17:00:00 of that week
    $startDateTime = new DateTime($start_date_str);
    $startDateTime->setTime(17, 0, 0);
    if ($startDateTime->format('N') != 1) {
        $startDateTime->modify('last Monday');
    }

    $db->beginTransaction();
    try {
        // Delete all existing schedule slots for this department
        $stmt = $db->prepare("DELETE FROM schedule_slots WHERE department_id = ?");
        $stmt->execute([$department_id]);

        // Generate 52 weeks (364 days, close to 365)
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
    return $stmt->execute([
        $department_id,
        $user_id,
        $start_time,
        $end_time,
        trim($description)
    ]);
}

function update_override($id, $department_id, $user_id, $start_time, $end_time, $description) {
    $db = get_oncall_db();
    $stmt = $db->prepare("
        UPDATE overrides
        SET department_id = ?, user_id = ?, start_time = ?, end_time = ?, description = ?
        WHERE id = ?
    ");
    return $stmt->execute([
        $department_id,
        $user_id,
        $start_time,
        $end_time,
        trim($description),
        $id
    ]);
}

function delete_override($id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("DELETE FROM overrides WHERE id = ?");
    return $stmt->execute([$id]);
}


// --- SCHEDULE CALCULATIONS & PRECEDENCE LOGIC ---

function calculate_final_schedule($base_slots, $overrides) {
    // Convert base slots to segment format
    $segments = [];
    foreach ($base_slots as $slot) {
        $segments[] = [
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

    // Apply overrides
    // Sort overrides chronologically by ID/creation so newer/last override wins if they overlap
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

            // Check overlap
            if ($s_end <= $o_start || $s_start >= $o_end) {
                // No overlap, keep segment
                $new_segments[] = $seg;
            } else {
                // Overlap exists!
                // Left part (if any)
                if ($s_start < $o_start) {
                    $left = $seg;
                    $left['end'] = $o_start;
                    $new_segments[] = $left;
                }
                // Right part (if any)
                if ($s_end > $o_end) {
                    $right = $seg;
                    $right['start'] = $o_end;
                    $new_segments[] = $right;
                }
            }
        }

        // Add the override itself as a segment
        $new_segments[] = [
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

    // Filter out segments with duration 0 or negative
    $segments = array_filter($segments, function($seg) {
        return $seg['end'] > $seg['start'];
    });

    // Sort segments by start time
    usort($segments, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    return array_values($segments);
}

function get_final_schedule_for_department($department_id, $start_time_str, $end_time_str) {
    $db = get_oncall_db();

    // Fetch base slots overlapping the period
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

    // Fetch overrides overlapping the period
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
    // To show user's schedule, we fetch final schedule for ALL departments they belong to,
    // and then filter for segments where user_id matches.
    $db = get_oncall_db();

    // Get departments the user belongs to
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

    // Sort user segments by start time
    usort($user_segments, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    return $user_segments;
}
