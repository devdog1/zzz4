<?php
// trades.php - On-Call Shift Trade Center (Propose, Offer, Agree, and Manager Approval)
require_once 'header.php';
require_once 'models.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$message = '';
$error = '';

// Handle Proposing a Trade
if (isset($_POST['propose_trade'])) {
    $slot_id = (int)$_POST['slot_id'];

    // Fetch slot to find department
    $db = get_oncall_db();
    $stmt = $db->prepare("SELECT * FROM schedule_slots WHERE id = ? AND user_id = ?");
    $stmt->execute([$slot_id, $current_user_id]);
    $slot = $stmt->fetch();

    if (!$slot) {
        $error = "Invalid shift selected. You can only put up your own shifts for trade.";
    } else {
        try {
            propose_trade($slot['department_id'], $slot_id, $current_user_id);
            $message = "Your on-call shift has been successfully put up for trade!";
        } catch (Exception $e) {
            $error = "Failed to put shift up for trade: " . $e->getMessage();
        }
    }
}

// Handle Accepting Trade as a "Take" (instant 'agreed')
if (isset($_POST['accept_take'])) {
    $trade_id = (int)$_POST['trade_id'];
    try {
        accept_trade_take($trade_id, $current_user_id);
        $message = "You have agreed to take this shift! It has been sent to the Department Manager for final approval.";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle Offering a "Swap"
if (isset($_POST['accept_swap'])) {
    $trade_id = (int)$_POST['trade_id'];
    $counter_slot_id = (int)$_POST['counter_slot_id'];
    try {
        accept_trade_swap($trade_id, $current_user_id, $counter_slot_id);
        $message = "Your swap offer has been submitted! Waiting for the proposer to agree to this swap.";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle Proposer agreeing to a Swap offer (moves 'offered' -> 'agreed')
if (isset($_POST['proposer_agree'])) {
    $trade_id = (int)$_POST['trade_id'];
    try {
        proposer_agree_swap($trade_id);
        $message = "You agreed to the swap offer! It is now sent to the Department Manager for final approval.";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle Manager Approving Trade (updates schedule_slots)
if (isset($_POST['manager_approve'])) {
    $trade_id = (int)$_POST['trade_id'];
    try {
        manager_approve_trade($trade_id, $current_user_id);
        $message = "Trade approved! The schedule slots have been successfully updated.";
    } catch (Exception $e) {
        $error = "Approval failed: " . $e->getMessage();
    }
}

// Handle Manager Rejecting Trade
if (isset($_POST['manager_reject'])) {
    $trade_id = (int)$_POST['trade_id'];
    try {
        manager_reject_trade($trade_id, $current_user_id);
        $message = "Trade request has been rejected.";
    } catch (Exception $e) {
        $error = "Rejection failed: " . $e->getMessage();
    }
}

// Handle Proposer Cancelling Trade
if (isset($_POST['cancel_trade'])) {
    $trade_id = (int)$_POST['trade_id'];
    try {
        cancel_trade_request($trade_id);
        $message = "Trade request cancelled successfully.";
    } catch (Exception $e) {
        $error = "Cancellation failed: " . $e->getMessage();
    }
}

// Get departments the user belongs to
$db = get_oncall_db();
$stmt = $db->prepare("SELECT department_id FROM department_users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$my_dept_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch user's future slots that are eligible for trade
$eligible_slots = [];
if (!empty($my_dept_ids)) {
    $in = implode(',', array_fill(0, count($my_dept_ids), '?'));
    $stmt = $db->prepare("
        SELECT s.*, d.name AS department_name
        FROM schedule_slots s
        JOIN departments d ON s.department_id = d.id
        WHERE s.user_id = ? AND s.department_id IN ($in) AND s.start_time >= NOW()
        ORDER BY s.start_time ASC
    ");
    $stmt->execute(array_merge([$current_user_id], $my_dept_ids));
    $eligible_slots = $stmt->fetchAll();
}

// Fetch all open/offered/agreed trade requests in the departments the user belongs to
$all_trades = [];
if (!empty($my_dept_ids)) {
    foreach ($my_dept_ids as $dept_id) {
        $dept_trades = get_trade_requests_by_department($dept_id);
        $all_trades = array_merge($all_trades, $dept_trades);
    }
}

// Group manager pending trades
$pending_approvals = [];
$all_departments = get_all_departments();
foreach ($all_departments as $dept) {
    if (can_manage_department($dept['id'])) {
        $dept_trades = get_trade_requests_by_department($dept['id']);
        foreach ($dept_trades as $t) {
            if ($t['status'] === 'agreed') {
                $pending_approvals[] = $t;
            }
        }
    }
}
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-right-left text-primary me-2"></i>Shift Trade Center</h1>
        <p class="text-muted">Put your scheduled shifts up for trade, take or swap shifts with your team, and manage trade approvals.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- MANAGER APPROVAL PANEL -->
<?php if (!empty($pending_approvals)): ?>
    <div class="card border-warning mb-4">
        <div class="card-header bg-warning text-dark d-flex align-items-center">
            <i class="fa-solid fa-gavel me-2"></i>
            <span class="fw-bold">Manager Trade Approvals (Pending)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Dept</th>
                            <th>Offered Shift (Owner)</th>
                            <th>Covering Shift (Trade)</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_approvals as $t): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($t['department_name']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($t['proposer_name'] . ' ' . $t['proposer_surname']) ?></strong><br>
                                    <span class="small text-muted">
                                        <?= date('M d, H:i', strtotime($t['offered_start'])) ?> &rarr; <?= date('M d, H:i', strtotime($t['offered_end'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($t['accepter_name'] . ' ' . $t['accepter_surname']) ?></strong><br>
                                    <span class="small text-muted">
                                        <?php if ($t['counter_slot_id']): ?>
                                            <span class="badge bg-info text-dark me-1">SWAP</span>
                                            <?= date('M d, H:i', strtotime($t['counter_start'])) ?> &rarr; <?= date('M d, H:i', strtotime($t['counter_end'])) ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary text-white me-1">TAKE</span> covers entire shift
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">
                                        <button type="submit" name="manager_approve" class="btn btn-sm btn-success me-1">
                                            <i class="fa-solid fa-check me-1"></i>Approve
                                        </button>
                                        <button type="submit" name="manager_reject" class="btn btn-sm btn-danger">
                                            <i class="fa-solid fa-xmark me-1"></i>Reject
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Left Column: Put Up For Trade / Your Future Slots -->
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header bg-white">
                <i class="fa-solid fa-calendar-plus text-primary me-2"></i>Put Shift Up For Trade
            </div>
            <div class="card-body">
                <?php if (empty($eligible_slots)): ?>
                    <p class="text-muted small text-center my-3">You don't have any future scheduled shifts in your departments to put up for trade.</p>
                <?php else: ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label for="slot_id" class="form-label small fw-semibold">Select Shift to Trade</label>
                            <select name="slot_id" id="slot_id" class="form-select form-select-sm" required>
                                <option value="">-- Choose Scheduled Shift --</option>
                                <?php foreach ($eligible_slots as $slot): ?>
                                    <option value="<?= $slot['id'] ?>">
                                        [<?= htmlspecialchars($slot['department_name']) ?>] <?= date('M d, H:i', strtotime($slot['start_time'])) ?> &rarr; <?= date('M d, H:i', strtotime($slot['end_time'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="propose_trade" class="btn btn-primary btn-sm w-100">
                            <i class="fa-solid fa-right-left me-1"></i>Publish Shift for Trade
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Active Trades Center -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white">
                <i class="fa-solid fa-arrows-spin text-success me-2"></i>Active Trades in Your Groups
            </div>
            <div class="card-body p-0">
                <?php if (empty($all_trades)): ?>
                    <p class="text-muted small text-center my-4">No active shift trades published in your departments.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Offered Shift (Owner)</th>
                                    <th>Offer/Cover</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_trades as $t): ?>
                                    <?php
                                    $is_proposer = ($t['proposing_user_id'] == $current_user_id);

                                    // Status badges
                                    $status_badge = '';
                                    if ($t['status'] === 'open') {
                                        $status_badge = '<span class="badge bg-primary">Open</span>';
                                    } elseif ($t['status'] === 'offered') {
                                        $status_badge = '<span class="badge bg-info text-dark">Offer Pending</span>';
                                    } elseif ($t['status'] === 'agreed') {
                                        $status_badge = '<span class="badge bg-warning text-dark">Agreed (Mgr Review)</span>';
                                    } elseif ($t['status'] === 'approved') {
                                        $status_badge = '<span class="badge bg-success">Approved</span>';
                                    } elseif ($t['status'] === 'rejected') {
                                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
                                    }

                                    // Fetch accepting user future slots for swapping
                                    $my_swaps = get_user_schedule_slots($current_user_id, $t['department_id']);
                                    ?>
                                    <tr>
                                        <td>
                                            <?= $status_badge ?><br>
                                            <span class="text-muted small"><?= htmlspecialchars($t['department_name']) ?></span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($t['proposer_name'] . ' ' . $t['proposer_surname']) ?></strong>
                                            <?php if ($is_proposer): ?>
                                                <span class="badge bg-dark small">Yours</span>
                                            <?php endif; ?><br>
                                            <span class="small text-muted">
                                                <?= date('M d, H:i', strtotime($t['offered_start'])) ?> &rarr; <?= date('M d, H:i', strtotime($t['offered_end'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($t['status'] === 'open'): ?>
                                                <span class="text-muted small">No coverage offer yet</span>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($t['accepter_name'] . ' ' . $t['accepter_surname']) ?></strong><br>
                                                <span class="small text-muted">
                                                    <?php if ($t['counter_slot_id']): ?>
                                                        <span class="badge bg-info text-dark me-1">SWAP</span>
                                                        <?= date('M d, H:i', strtotime($t['counter_start'])) ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary text-white me-1">TAKE</span> just take
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_proposer): ?>
                                                <!-- Current User is the Proposer -->
                                                <?php if ($t['status'] === 'offered'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">
                                                        <button type="submit" name="proposer_agree" class="btn btn-sm btn-success">
                                                            <i class="fa-solid fa-check me-1"></i>Agree to Swap
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($t['status'] === 'open' || $t['status'] === 'offered'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">
                                                        <button type="submit" name="cancel_trade" class="btn btn-sm btn-outline-danger">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <!-- Current User is a Responder -->
                                                <?php if ($t['status'] === 'open'): ?>
                                                    <div class="d-grid gap-1">
                                                        <!-- Option 1: Just Take -->
                                                        <form method="POST">
                                                            <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">
                                                            <button type="submit" name="accept_take" class="btn btn-xs btn-outline-success w-100" style="font-size: 0.75rem;">
                                                                <i class="fa-solid fa-handshake me-1"></i>Just Take Shift
                                                            </button>
                                                        </form>

                                                        <!-- Option 2: Swap -->
                                                        <?php if (!empty($my_swaps)): ?>
                                                            <form method="POST">
                                                                <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">
                                                                <div class="input-group input-group-sm mt-1">
                                                                    <select name="counter_slot_id" class="form-select form-select-sm" style="font-size: 0.7rem;" required>
                                                                        <option value="">-- Swap With --</option>
                                                                        <?php foreach ($my_swaps as $sw): ?>
                                                                            <option value="<?= $sw['id'] ?>">
                                                                                <?= date('M d, H:i', strtotime($sw['start_time'])) ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <button type="submit" name="accept_swap" class="btn btn-outline-info">Swap</button>
                                                                </div>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
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

<?php require_once 'footer.php'; ?>
