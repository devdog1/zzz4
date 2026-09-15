<?php
/**
 * Plugin Name: On-Call Schedule Manager
 * Description: Enterprise On-Call Rotation, Shift Trade Center, Manual Overrides, Metaswitch CommPortal & Zabbix Integration.
 * Version: 2.1
 * Author: DevDog
 * Permissions: view_schedule, manage_schedule, manage_trades, manage_departments, manage_telephony, manage_settings
 * Roles: manager:view_schedule,manage_schedule,manage_trades,manage_departments,manage_telephony,manage_settings; operator:view_schedule,manage_trades; viewer:view_schedule
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/../../');
}

// Load Plugin Models & Telephony Engine
require_once __DIR__ . '/CommPortal.php';
require_once __DIR__ . '/models/oncall-models.php';

// Load Plugin Views
require_once __DIR__ . '/views/calendar-view.php';
require_once __DIR__ . '/views/trades-view.php';
require_once __DIR__ . '/views/overrides-view.php';
require_once __DIR__ . '/views/departments-view.php';
require_once __DIR__ . '/views/telephony-view.php';
require_once __DIR__ . '/views/generate-view.php';
require_once __DIR__ . '/views/settings-view.php';

// Load Background Tasks
require_once __DIR__ . '/tasks/commportal-sync-task.php';
require_once __DIR__ . '/tasks/zabbix-sync-task.php';
require_once __DIR__ . '/tasks/zabbix-group-assign-task.php';

/* =========================================================
 * ACTIVATION & DEACTIVATION HOOKS
 * ========================================================= */

add_action('plugin_activate_oncall-manager', 'oncall_plugin_install_tables');
function oncall_plugin_install_tables() {
    $install_sql_file = __DIR__ . '/sql/install.sql';
    if (file_exists($install_sql_file)) {
        $pdb = oncall_get_pdb();
        $db = get_db_connection();
        $sql = file_get_contents($install_sql_file);

        $sql = str_replace('{prefix}', $pdb->getPrefix(), $sql);
        $db->exec($sql);
    }
}

add_action('plugin_deactivate_oncall-manager', 'oncall_plugin_uninstall_tables');
function oncall_plugin_uninstall_tables($purge_tables = false) {
    if (!$purge_tables) {
        return; // Retain plugin database tables unless explicitly requested to purge!
    }
    $uninstall_sql_file = __DIR__ . '/sql/uninstall.sql';
    if (file_exists($uninstall_sql_file)) {
        $pdb = oncall_get_pdb();
        $db = get_db_connection();
        $sql = file_get_contents($uninstall_sql_file);

        $sql = str_replace('{prefix}', $pdb->getPrefix(), $sql);
        $db->exec($sql);
    }
}

/* =========================================================
 * NAVIGATION MENU LINKS
 * ========================================================= */

add_filter('theme_nav_links', function($nav) {
    if (!has_permission('view_schedule') && !has_permission('oncall_manager_view_schedule')) {
        return $nav;
    }

    $oncall_menu = [
        'label' => 'On-Call Schedule',
        'icon' => 'fa-solid fa-phone-volume',
        'route' => 'oncall_calendar',
        'children' => [
            ['label' => 'Rotation Calendar', 'icon' => 'fa-solid fa-calendar-days', 'route' => 'oncall_calendar'],
            ['label' => 'Shift Trade Center', 'icon' => 'fa-solid fa-handshake', 'route' => 'oncall_trades']
        ]
    ];

    if (has_permission('manage_schedule') || has_permission('oncall_manager_manage_schedule')) {
        $oncall_menu['children'][] = ['label' => 'Manual Overrides', 'icon' => 'fa-solid fa-calendar-minus', 'route' => 'oncall_overrides'];
        $oncall_menu['children'][] = ['label' => '365-Day Shift Generator', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'route' => 'oncall_generate'];
    }

    if (has_permission('manage_departments') || has_permission('oncall_manager_manage_departments')) {
        $oncall_menu['children'][] = ['label' => 'Department Management', 'icon' => 'fa-solid fa-building-user', 'route' => 'oncall_departments'];
    }

    if (has_permission('manage_telephony') || has_permission('oncall_manager_manage_telephony')) {
        $oncall_menu['children'][] = ['label' => 'CommPortal Telephony', 'icon' => 'fa-solid fa-headset', 'route' => 'oncall_telephony'];
    }

    if (has_permission('manage_settings') || has_permission('oncall_manager_manage_settings')) {
        $oncall_menu['children'][] = ['label' => 'Plugin Settings', 'icon' => 'fa-solid fa-gears', 'route' => 'oncall_settings'];
    }

    $nav[] = $oncall_menu;
    return $nav;
});

/* =========================================================
 * SCHEDULED TASKS REGISTRATION
 * ========================================================= */

add_action('init_scheduler', function($scheduler) {
    $scheduler->registerTask(
        'oncall_commportal_sync',
        'oncall_task_commportal_sync',
        60,
        'oncall-manager'
    );

    $scheduler->registerTask(
        'oncall_zabbix_sync',
        'oncall_task_zabbix_sync',
        3600,
        'oncall-manager'
    );

    $scheduler->registerTask(
        'oncall_zabbix_group_assign',
        'oncall_task_zabbix_group_assign',
        60,
        'oncall-manager'
    );
});

/* =========================================================
 * ROUTE HANDLERS
 * ========================================================= */

add_action('register_routes', function() {
    register_route('oncall_calendar', function() {
        if (!has_permission('view_schedule') && !has_permission('oncall_manager_view_schedule')) die('Access Denied');
        oncall_render_calendar_page();
    });

    register_route('oncall_trades', function() {
        if (!has_permission('manage_trades') && !has_permission('oncall_manager_manage_trades')) die('Access Denied');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';
            $dept_id = (int)($_POST['department_id'] ?? 0);
            $current_user_id = $_SESSION['user_id'] ?? null;

            try {
                if ($action === 'propose_trade') {
                    $slot_id = (int)$_POST['offered_slot_id'];
                    oncall_propose_trade($dept_id, $slot_id, $current_user_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Trade proposal created successfully.'));
                } elseif ($action === 'accept_take') {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_accept_trade_take($trade_id, $current_user_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Accepted shift take request. Pending manager approval.'));
                } elseif ($action === 'proposer_agree') {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_proposer_agree_swap($trade_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Agreed to swap. Pending manager approval.'));
                } elseif ($action === 'cancel_trade') {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_cancel_trade_request($trade_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Trade request canceled.'));
                } elseif ($action === 'approve_trade' && oncall_can_manage_department($dept_id)) {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_manager_approve_trade($trade_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Trade request approved and schedule updated.'));
                } elseif ($action === 'reject_trade' && oncall_can_manage_department($dept_id)) {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_manager_reject_trade($trade_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Trade request rejected.'));
                }
            } catch (Exception $e) {
                redirect(url_for('oncall_trades') . "&department_id={$dept_id}&err=" . urlencode($e->getMessage()));
            }
        }

        oncall_render_trades_page();
    });

    register_route('oncall_overrides', function() {
        if (!has_permission('manage_schedule') && !has_permission('oncall_manager_manage_schedule')) die('Access Denied');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'create_override') {
                $dept_id = (int)$_POST['department_id'];
                $user_id = (int)$_POST['user_id'];
                $start = $_POST['start_time'];
                $end = $_POST['end_time'];
                $desc = $_POST['description'];

                oncall_create_override($dept_id, $user_id, $start, $end, $desc);
                redirect(url_for('oncall_overrides') . '&msg=' . urlencode('Schedule override created successfully.'));
            } elseif ($action === 'delete_override') {
                $id = (int)$_POST['id'];
                oncall_delete_override($id);
                redirect(url_for('oncall_overrides') . '&msg=' . urlencode('Schedule override removed successfully.'));
            }
        }

        oncall_render_overrides_page();
    });

    register_route('oncall_departments', function() {
        if (!has_permission('manage_departments') && !has_permission('oncall_manager_manage_departments')) die('Access Denied');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'create_department') {
                $name = $_POST['name'] ?? '';
                $mgr = !empty($_POST['manager_user_id']) ? (int)$_POST['manager_user_id'] : null;
                oncall_create_department($name, $mgr);
                redirect(url_for('oncall_departments') . '&msg=' . urlencode('Department created successfully.'));
            } elseif ($action === 'update_department') {
                $id = (int)$_POST['id'];
                $name = $_POST['name'] ?? '';
                $mgr = !empty($_POST['manager_user_id']) ? (int)$_POST['manager_user_id'] : null;
                $noc = !empty($_POST['noc_mode']) ? 1 : 0;
                oncall_update_department($id, $name, $mgr, $noc);

                $z_groups = [];
                if (!empty($_POST['zabbix_usrgrp_ids'])) {
                    $raw = explode(',', $_POST['zabbix_usrgrp_ids']);
                    foreach ($raw as $r) {
                        $trimmed = trim($r);
                        if (is_numeric($trimmed)) {
                            $z_groups[] = (int)$trimmed;
                        }
                    }
                }
                oncall_save_department_zabbix_groups($id, $z_groups);

                redirect(url_for('oncall_departments') . "&id={$id}&msg=" . urlencode('Department settings updated.'));
            } elseif ($action === 'delete_department') {
                $id = (int)$_POST['id'];
                oncall_delete_department($id);
                redirect(url_for('oncall_departments') . '&msg=' . urlencode('Department deleted.'));
            } elseif ($action === 'update_members') {
                $dept_id = (int)$_POST['department_id'];
                $u_ids = $_POST['user_ids'] ?? [];
                oncall_save_department_users($dept_id, $u_ids);
                redirect(url_for('oncall_departments') . "&id={$dept_id}&msg=" . urlencode('Department roster updated.'));
            }
        }

        oncall_render_departments_page();
    });

    register_route('oncall_telephony', function() {
        if (!has_permission('manage_telephony') && !has_permission('oncall_manager_manage_telephony')) die('Access Denied');

        $pdb = oncall_get_pdb();
        $tb_accounts = $pdb->getTableName('commportal_accounts');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'save_account') {
                $num = $_POST['account_number'] ?? '';
                $pass = $_POST['commportal_pass'] ?? '';
                $fwd = $_POST['forwarding_number'] ?? '';

                $pdb->query("
                    INSERT INTO {$tb_accounts} (account_number, commportal_pass, forwarding_number)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE commportal_pass = ?, forwarding_number = ?
                ", [$num, $pass, $fwd, $pass, $fwd]);

                redirect(url_for('oncall_telephony') . '&msg=' . urlencode('Telephony account saved.'));
            } elseif ($action === 'delete_account') {
                $id = (int)$_POST['id'];
                $pdb->query("DELETE FROM {$tb_accounts} WHERE id = ?", [$id]);
                redirect(url_for('oncall_telephony') . '&msg=' . urlencode('Telephony account removed.'));
            }
        }

        oncall_render_telephony_page();
    });

    register_route('oncall_generate', function() {
        if (!has_permission('manage_schedule') && !has_permission('oncall_manager_manage_schedule')) die('Access Denied');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'generate_schedule') {
                $dept_id = (int)$_POST['department_id'];
                $start_date = $_POST['start_date'];
                $user_ids = $_POST['user_ids'] ?? [];

                try {
                    oncall_generate_365_day_schedule($dept_id, $user_ids, $start_date);
                    redirect(url_for('oncall_calendar') . "&department_id={$dept_id}&msg=" . urlencode('365-day rotation schedule successfully generated.'));
                } catch (Exception $e) {
                    redirect(url_for('oncall_generate') . "&department_id={$dept_id}&err=" . urlencode($e->getMessage()));
                }
            }
        }

        oncall_render_generate_page();
    });

    register_route('oncall_settings', function() {
        if (!has_permission('manage_settings') && !has_permission('oncall_manager_manage_settings')) die('Access Denied');

        $pdb = oncall_get_pdb();
        $tb_noc = $pdb->getTableName('noc_business_hours');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'save_zabbix_settings') {
                $url = $_POST['zabbix_api_url'] ?? '';
                $token = $_POST['zabbix_api_token'] ?? '';
                $domain = $_POST['zabbix_sync_domain'] ?? '';

                oncall_set_setting('zabbix_api_url', $url);
                oncall_set_setting('zabbix_api_token', $token);
                oncall_set_setting('zabbix_sync_domain', $domain);

                redirect(url_for('oncall_settings') . '&msg=' . urlencode('Zabbix API integration settings saved.'));
            } elseif ($action === 'save_noc_hours') {
                $hours_input = $_POST['noc_hours'] ?? [];
                foreach ($hours_input as $dow => $times) {
                    $start = !empty($times['start']) ? $times['start'] . ':00' : '08:00:00';
                    $end = !empty($times['end']) ? $times['end'] . ':00' : '17:00:00';

                    $pdb->query("
                        INSERT INTO {$tb_noc} (day_of_week, start_time, end_time)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE start_time = ?, end_time = ?
                    ", [(int)$dow, $start, $end, $start, $end]);
                }

                redirect(url_for('oncall_settings') . '&msg=' . urlencode('NOC business hours overlay updated.'));
            }
        }

        oncall_render_settings_page();
    });

    register_route('oncall_api_events', function() {
        header('Content-Type: application/json');

        if (!has_permission('view_schedule') && !has_permission('oncall_manager_view_schedule')) {
            echo json_encode([]);
            exit;
        }

        $department_id = $_GET['department_id'] ?? null;
        if (!$department_id) {
            echo json_encode([]);
            exit;
        }

        $start_str = $_GET['start'] ?? date('Y-m-d H:i:s', strtotime('-1 month'));
        $end_str = $_GET['end'] ?? date('Y-m-d H:i:s', strtotime('+1 month'));

        $segments = oncall_get_final_schedule_for_department($department_id, $start_str, $end_str);

        $events = [];
        foreach ($segments as $seg) {
            $events[] = [
                'id' => $seg['id'],
                'title' => $seg['display_name'] . ($seg['is_override'] ? ' (' . $seg['description'] . ')' : ''),
                'start' => date('c', $seg['start']),
                'end' => date('c', $seg['end']),
                'color' => $seg['is_override'] ? '#dc3545' : '#0d6efd',
                'extendedProps' => [
                    'description' => $seg['description']
                ]
            ];
        }

        echo json_encode($events);
        exit;
    });
});

/* =========================================================
 * DASHBOARD HOME WIDGET
 * ========================================================= */

add_action('index_dashboard_widgets', function($user_context) {
    if (!has_permission('view_schedule') && !has_permission('oncall_manager_view_schedule')) {
        return;
    }

    $departments = oncall_get_all_departments();
    if (empty($departments)) {
        return;
    }

    $now = time();
    $now_str = date('Y-m-d H:i:s', $now);
    $week_ahead_str = date('Y-m-d H:i:s', strtotime('+7 days', $now));

    foreach ($departments as $dept) {
        $now_oncall = oncall_get_current_on_call($dept['id'], $now);
            $background_user = null;
            if ($now_oncall && strpos($now_oncall['description'], 'NOC') !== false) {
                $background_user = oncall_get_background_assigned_user($dept['id'], $now);
            }

        $all_segments = oncall_get_final_schedule_for_department($dept['id'], $now_str, $week_ahead_str);

        // Filter upcoming segments
        $upcoming_segments = [];
        foreach ($all_segments as $seg) {
            if ($now_oncall && $seg['start'] == $now_oncall['start'] && $seg['end'] == $now_oncall['end']) {
                continue; // Skip active current segment
            }
            if ($seg['start'] >= $now) {
                $upcoming_segments[] = $seg;
            }
            if (count($upcoming_segments) >= 3) {
                break;
            }
        }

        // Determine badge status
        $status_badge_class = 'bg-primary text-white';
        $status_badge_label = 'Active Rotation';
        $status_icon = 'fa-solid fa-circle-check';

        if ($now_oncall) {
            if (!empty($now_oncall['is_override'])) {
                $status_badge_class = 'bg-warning text-dark';
                $status_badge_label = 'Manual Override';
                $status_icon = 'fa-solid fa-circle-exclamation';
            }
        } else {
            $status_badge_class = 'bg-secondary text-white';
            $status_badge_label = 'Unassigned';
            $status_icon = 'fa-solid fa-circle-minus';
        }
        ?>
        <div class="col-12 mb-4 text-start widget-block" data-widget-key="oncall_dept_<?php echo $dept['id']; ?>" data-widget-title="<?php echo htmlspecialchars($dept['name']); ?> On-Call Coverage">
            <div class="card shadow-sm border-0 border-start border-4 border-warning">
                <div class="card-body p-4">
                    <!-- Top Header Row -->
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h3 class="fw-bold text-primary mb-0"><?php echo htmlspecialchars($dept['name']); ?></h3>
                            <small class="text-muted">Active Coverage Status</small>
                        </div>
                        <div>
                            <span class="badge <?php echo $status_badge_class; ?> fs-7 px-3 py-2 rounded-pill fw-bold">
                                <i class="<?php echo $status_icon; ?> me-1"></i>
                                <?php echo htmlspecialchars($status_badge_label); ?>
                            </span>
                        </div>
                    </div>

                    <hr class="my-3 text-secondary opacity-25">

                    <div class="row g-4">
                        <!-- Left Column: ON-CALL PERSON -->
                        <div class="col-lg-6 border-end-lg">
                            <small class="text-uppercase fw-bold text-secondary d-block mb-3" style="letter-spacing: 0.5px; font-size: 0.75rem;">ON-CALL PERSON</small>
                            <?php if ($now_oncall): ?>
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-3 text-secondary" style="width: 52px; height: 52px;">
                                        <i class="fa-solid fa-user fs-3"></i>
                                    </div>
                                    <div>
                                        <h4 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($now_oncall['display_name']); ?></h4>
                                        <small class="text-muted">(@<?php echo htmlspecialchars($now_oncall['username']); ?>)</small>
                                    </div>
                                </div>

                                <div class="small text-secondary">
                                    <div class="mb-1">
                                        <i class="fa-solid fa-envelope me-2"></i>
                                        <span><?php echo htmlspecialchars($now_oncall['username']); ?></span>
                                    </div>
                                    <div class="mb-1">
                                        <i class="fa-solid fa-calendar-days me-2"></i>
                                        <span>Shift: <?php echo date('M d, H:i', $now_oncall['start']); ?> &rarr; <?php echo date('M d, H:i', $now_oncall['end']); ?></span>
                                    </div>
                                    <div class="text-warning fw-bold">
                                        <i class="fa-solid fa-tag me-2"></i>
                                        <span>Reason: <?php echo htmlspecialchars($now_oncall['description'] ?: 'Base Rotation'); ?></span>
                                    </div>
                                    <?php if ($background_user): ?>
                                        <div class="mt-2 pt-2 border-top text-dark small">
                                            <i class="fa-solid fa-user-clock text-primary me-1"></i>
                                            <strong>Background Scheduled User:</strong>
                                            <span class="fw-bold"><?php echo htmlspecialchars($background_user['display_name']); ?></span>
                                            <span class="text-muted">(<?php echo htmlspecialchars($background_user['username']); ?>)</span>
                                            <?php if (!empty($background_user['is_override'])): ?>
                                                <span class="badge bg-warning text-dark ms-1">Override</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary border ms-1">Rotation</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted py-3">
                                    <i class="fa-solid fa-triangle-exclamation text-warning me-2 fs-4"></i>
                                    <span>No active on-call user currently assigned for this department.</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right Column: UPCOMING SHIFTS -->
                        <div class="col-lg-6">
                            <small class="text-uppercase fw-bold text-secondary d-block mb-3" style="letter-spacing: 0.5px; font-size: 0.75rem;">UPCOMING SHIFTS</small>
                            <?php if (empty($upcoming_segments)): ?>
                                <p class="text-muted small py-2 mb-0">No upcoming shifts scheduled for the next 7 days.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-borderless table-sm align-middle mb-0 small">
                                        <thead>
                                            <tr class="border-bottom text-dark fw-bold">
                                                <th>User</th>
                                                <th>Starts</th>
                                                <th>Type</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($upcoming_segments as $useg): ?>
                                                <tr>
                                                    <td class="fw-bold text-dark"><?php echo htmlspecialchars($useg['display_name']); ?></td>
                                                    <td class="text-secondary"><?php echo date('M d, H:i', $useg['start']); ?></td>
                                                    <td>
                                                        <?php if ($useg['is_override']): ?>
                                                            <span class="badge bg-warning text-dark px-2 py-1">Override</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-light text-secondary border px-2 py-1">Rotation</span>
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
            </div>
        </div>
        <?php
    }
});
