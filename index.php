<?php
// index.php - Dashboard
require_once 'header.php';
require_once 'models.php';

$departments = get_all_departments();
$all_users = get_all_users();
$now = time();
$now_str = date('Y-m-d H:i:s', $now);

$current_user_id = $_SESSION['user_id'] ?? null;

function get_current_on_call($department_id, $now) {
    $start_str = date('Y-m-d H:i:s', $now - 10);
    $end_str = date('Y-m-d H:i:s', $now + 10);
    $segments = get_final_schedule_for_department($department_id, $start_str, $end_str);
    foreach ($segments as $seg) {
        if ($now >= $seg['start'] && $now <= $seg['end']) {
            return $seg;
        }
    }
    return null;
}

// 1. Get user's next and upcoming shifts (past to 365 days in future)
$my_next_shifts = [];
if ($current_user_id) {
    $end_of_year = date('Y-m-d H:i:s', $now + (365 * 24 * 3600));
    $user_upcoming = get_final_schedule_for_user($current_user_id, $now_str, $end_of_year);
    $my_next_shifts = array_slice($user_upcoming, 0, 3);
}

// 2. Get open trades available to them in their departments
$available_trades_count = 0;
if ($current_user_id) {
    $db = get_oncall_db();
    $stmt = $db->prepare("SELECT department_id FROM department_users WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $my_depts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($my_depts)) {
        foreach ($my_depts as $d_id) {
            $trades = get_trade_requests_by_department($d_id);
            foreach ($trades as $t) {
                if ($t['status'] === 'open' && $t['proposing_user_id'] != $current_user_id) {
                    $available_trades_count++;
                }
            }
        }
    }
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Dashboard</h1>
        <p class="text-muted">Overview of active on-call assignments, coverage status, and upcoming rotations.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <span class="badge bg-secondary p-2"><i class="fa-solid fa-clock me-1"></i> Current Time: <?= date('Y-m-d H:i:s') ?></span>
    </div>
</div>

<div class="row">
    <!-- Main Left Column: Departments Coverage -->
    <div class="col-lg-8">

        <!-- Open Trades Notification Alert -->
        <?php if ($current_user_id && $available_trades_count > 0): ?>
            <div class="alert alert-info d-flex align-items-center justify-content-between mb-4 shadow-sm" role="alert">
                <div>
                    <i class="fa-solid fa-right-left me-2 fs-5"></i>
                    There <?= $available_trades_count === 1 ? 'is <strong>1 open shift</strong>' : "are <strong>{$available_trades_count} open shifts</strong>" ?> available for trade in your departments!
                </div>
                <a href="trades.php" class="btn btn-sm btn-primary"><i class="fa-solid fa-arrow-right me-1"></i>Go to Trade Center</a>
            </div>
        <?php endif; ?>

        <h3 class="h4 mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Department Live Coverage</h3>

        <?php if (empty($departments)): ?>
            <div class="alert alert-info">
                <i class="fa-solid fa-info-circle me-1"></i> No departments created yet. Go to <a href="departments.php" class="alert-link">Departments</a> to add one!
            </div>
        <?php else: ?>
            <?php foreach ($departments as $dept): ?>
                <?php
                $current = get_current_on_call($dept['id'], $now);
                $is_override = $current && $current['is_override'];

                // Fetch next 3 upcoming shifts in the next 30 days
                $upcoming_start = date('Y-m-d H:i:s', $now);
                $upcoming_end = date('Y-m-d H:i:s', $now + (30 * 24 * 3600));
                $all_segments = get_final_schedule_for_department($dept['id'], $upcoming_start, $upcoming_end);

                // Filter out current segment if any, or just take the first 3
                $upcoming = [];
                $count = 0;
                foreach ($all_segments as $seg) {
                    if ($current && $seg['start'] == $current['start'] && $seg['end'] == $current['end']) {
                        continue;
                    }
                    if ($seg['end'] <= $now) {
                        continue;
                    }
                    $upcoming[] = $seg;
                    if (++$count >= 3) break;
                }
                ?>
                <div class="card <?= $current ? ($is_override ? 'oncall-override' : 'oncall-active') : 'border-danger' ?> mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h4 class="card-title mb-1 text-primary"><?= htmlspecialchars($dept['name']) ?></h4>
                                <p class="text-muted mb-0">Active Coverage Status</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <?php if ($current): ?>
                                    <?php if ($is_override): ?>
                                        <span class="badge bg-warning text-dark p-2"><i class="fa-solid fa-circle-exclamation me-1"></i> Manual Override</span>
                                    <?php else: ?>
                                        <span class="badge bg-success p-2"><i class="fa-solid fa-circle-check me-1"></i> Normal Rotation</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-danger p-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> NO ACTIVE COVERAGE</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr class="my-3">

                        <div class="row">
                            <div class="col-md-6 border-end">
                                <h5 class="h6 text-uppercase text-muted small">On-Call Person</h5>
                                <?php if ($current): ?>
                                    <div class="d-flex align-items-center mt-2">
                                        <div class="bg-light rounded-circle p-3 text-center me-3" style="width: 50px; height: 50px;">
                                            <i class="fa-solid fa-user text-secondary"></i>
                                        </div>
                                        <div>
                                            <h5 class="mb-0 fw-bold"><?= htmlspecialchars($current['name'] . ' ' . $current['surname']) ?></h5>
                                            <small class="text-muted">(@<?= htmlspecialchars($current['username']) ?>)</small>
                                        </div>
                                    </div>
                                    <ul class="list-unstyled mt-3 mb-0 small text-muted">
                                        <?php
                                        $curr_user = get_user_by_id($current['user_id']);
                                        $email_display = ($curr_user && $curr_user['email']) ? $curr_user['email'] : ($current['username'] . '@' . get_setting('zabbix_default_domain', 'example.com'));
                                        ?>
                                        <li><i class="fa-solid fa-envelope me-2"></i> <?= htmlspecialchars($email_display) ?></li>
                                        <li><i class="fa-solid fa-calendar-day me-2"></i> Shift: <?= date('M d, H:i', $current['start']) ?> &rarr; <?= date('M d, H:i', $current['end']) ?></li>
                                        <?php if ($is_override && !empty($current['description'])): ?>
                                            <li class="text-warning"><i class="fa-solid fa-tag me-2"></i> Reason: <?= htmlspecialchars($current['description']) ?></li>
                                        <?php endif; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-danger mt-2 fw-semibold mb-0">No one is currently scheduled or on call for this department.</p>
                                    <p class="small text-muted mb-0">Go to the Schedule Generator or Overrides to set up a rotation.</p>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6 ps-md-4">
                                <h5 class="h6 text-uppercase text-muted small">Upcoming Shifts</h5>
                                <?php if (empty($upcoming)): ?>
                                    <p class="text-muted small mt-2">No upcoming shifts scheduled for the next 30 days.</p>
                                <?php else: ?>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-borderless align-middle mb-0 small">
                                            <thead>
                                                <tr class="text-muted border-bottom">
                                                    <th>User</th>
                                                    <th>Starts</th>
                                                    <th>Type</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($upcoming as $up_seg): ?>
                                                    <tr>
                                                        <td class="fw-semibold text-dark">
                                                            <?= htmlspecialchars($up_seg['name'] . ' ' . $up_seg['surname']) ?>
                                                        </td>
                                                        <td>
                                                            <?= date('M d, H:i', $up_seg['start']) ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($up_seg['is_override']): ?>
                                                                <span class="badge bg-warning text-dark">Override</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-light text-secondary">Rotation</span>
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
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Sidebar Right Column: Stats & Quick Links -->
    <div class="col-lg-4">
        <!-- Card 1: Quick Actions (Now with iCal sync) -->
        <div class="card mb-4 shadow-sm border-primary">
            <div class="card-header bg-primary text-white">
                <i class="fa-solid fa-bolt me-2"></i> Quick Actions
            </div>
            <div class="list-group list-group-flush border-bottom">
                <?php
                $can_generate = false;
                $can_override = false;
                foreach ($departments as $d) {
                    if (can_manage_department($d['id'])) {
                        $can_generate = true;
                    }
                    if (can_user_create_override($d['id'], $current_user_id)) {
                        $can_override = true;
                    }
                }
                ?>
                <?php if ($can_generate): ?>
                    <a href="generate.php" class="list-group-item list-group-item-action">
                        <i class="fa-solid fa-calendar-plus text-primary me-2"></i> Generate 365-Day Schedule
                    </a>
                    <a href="commportal_mgmt.php" class="list-group-item list-group-item-action">
                        <i class="fa-solid fa-phone text-info me-2"></i> CommPortal Telephony Sync
                    </a>
                <?php endif; ?>
                <?php if ($can_override): ?>
                    <a href="overrides.php?action=new" class="list-group-item list-group-item-action">
                        <i class="fa-solid fa-clock-rotate-left text-warning me-2"></i> Create Manual Override
                    </a>
                <?php endif; ?>
                <?php if (has_permission('manage_departments')): ?>
                    <a href="sync.php" class="list-group-item list-group-item-action">
                        <i class="fa-solid fa-arrows-rotate text-success me-2"></i> Sync Users from Zabbix
                    </a>
                    <a href="verify_db.php" class="list-group-item list-group-item-action">
                        <i class="fa-solid fa-stethoscope text-primary me-2"></i> Database Diagnostics
                    </a>
                <?php endif; ?>
                <a href="calendar.php" class="list-group-item list-group-item-action">
                    <i class="fa-solid fa-calendar-days text-info me-2"></i> View Schedules Calendar
                </a>
            </div>
            <?php if ($current_user_id): ?>
                <div class="card-body bg-light">
                    <label class="form-label small fw-semibold text-muted mb-1"><i class="fa-solid fa-calendar-plus me-1 text-primary"></i>Your Personal iCal Feed (Outlook):</label>
                    <div class="input-group input-group-sm">
                        <?php
                        $feed_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'webcal' : 'http') . '://' . $_SERVER['HTTP_HOST'] . str_replace('index.php', '', $_SERVER['PHP_SELF']) . 'ical_feed.php?userid=' . $current_user_id;
                        ?>
                        <input type="text" id="ical_url_input" class="form-control form-control-sm bg-white" value="<?= htmlspecialchars($feed_url) ?>" readonly>
                        <button class="btn btn-outline-primary btn-sm" onclick="copyICalInput()" type="button" title="Copy Feed Link">
                            <i class="fa-solid fa-copy"></i>
                        </button>
                    </div>
                    <span id="ical_msg" class="text-success small mt-1" style="display:none;"><i class="fa-solid fa-circle-check me-1"></i>Copied to clipboard!</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Card 2: Upcoming Shifts Box (Directly below Quick Actions) -->
        <?php if ($current_user_id): ?>
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white fw-bold border-bottom">
                    <i class="fa-solid fa-calendar-check me-2 text-primary"></i>Your Next On-Call Shifts
                </div>
                <div class="card-body p-0">
                    <?php if (empty($my_next_shifts)): ?>
                        <p class="text-muted small p-3 mb-0">You have no upcoming on-call shifts scheduled for the next 365 days.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Group</th>
                                        <th>Starts</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($my_next_shifts as $sh): ?>
                                        <tr>
                                            <td class="fw-semibold"><?= htmlspecialchars($sh['department_name']) ?></td>
                                            <td><code><?= date('M d, H:i', $sh['start']) ?></code></td>
                                            <td>
                                                <?php if ($sh['is_override']): ?>
                                                    <span class="badge bg-warning text-dark">Override</span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-secondary">Rotation</span>
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
        <?php endif; ?>

        <!-- Card 3: System Statistics -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="fa-solid fa-chart-simple me-2"></i> System Statistics
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span>Total Departments</span>
                    <span class="badge bg-primary rounded-pill"><?= count($departments) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span>Total Synced Users</span>
                    <span class="badge bg-success rounded-pill"><?= count($all_users) ?></span>
                </div>
                <?php
                // Fetch active overrides
                $active_overrides_count = 0;
                foreach ($departments as $dept) {
                    $all_ovs = get_overrides($dept['id']);
                    foreach ($all_ovs as $ov) {
                        $ov_start = strtotime($ov['start_time']);
                        $ov_end = strtotime($ov['end_time']);
                        if ($now >= $ov_start && $now <= $ov_end) {
                            $active_overrides_count++;
                        }
                    }
                }
                ?>
                <div class="d-flex justify-content-between align-items-center">
                    <span>Active Manual Overrides</span>
                    <span class="badge bg-warning text-dark rounded-pill"><?= $active_overrides_count ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyICalInput() {
    const copyText = document.getElementById("ical_url_input");
    copyText.select();
    copyText.setSelectionRange(0, 99999); // For mobile devices
    navigator.clipboard.writeText(copyText.value).then(function() {
        const msg = document.getElementById('ical_msg');
        msg.style.display = 'inline-block';
        setTimeout(function() {
            msg.style.display = 'none';
        }, 3000);
    });
}
</script>

<?php require_once 'footer.php'; ?>
