<?php
// views/trades-view.php - Shift Trade Center View

function oncall_render_trades_page() {
    $current_user_id = $_SESSION['user_id'] ?? null;
    $departments = oncall_get_all_departments();
    $selected_dept = $_GET['department_id'] ?? ($departments[0]['id'] ?? 1);

    $my_slots = $current_user_id ? oncall_get_user_schedule_slots($current_user_id, $selected_dept) : [];
    $all_trades = oncall_get_trade_requests_by_department($selected_dept);
    $can_approve = oncall_can_manage_department($selected_dept);

    $msg = $_GET['msg'] ?? null;
    $err = $_GET['err'] ?? null;
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Shift Trade Center</h1>
            <p class="text-muted mb-0">Propose peer-to-peer shift trades or perform direct takes/swaps</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($err); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-box-arrow-up-right me-2"></i>Propose New Trade</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="<?php echo url_for('oncall_trades'); ?>" class="mb-3">
                        <input type="hidden" name="route" value="oncall_trades">
                        <label for="department_id" class="form-label fw-bold">Select Department:</label>
                        <select name="department_id" id="department_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo ($selected_dept == $dept['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <form method="POST" action="<?php echo url_for('oncall_trades'); ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="propose_trade">
                        <input type="hidden" name="department_id" value="<?php echo (int)$selected_dept; ?>">

                        <div class="mb-3">
                            <label for="offered_slot_id" class="form-label fw-bold">Select Your Slot to Offer:</label>
                            <?php if (empty($my_slots)): ?>
                                <p class="text-muted small border rounded p-2 bg-light">You have no upcoming shifts assigned in this department.</p>
                            <?php else: ?>
                                <select name="offered_slot_id" id="offered_slot_id" class="form-select" required>
                                    <?php foreach ($my_slots as $slot): ?>
                                        <option value="<?php echo $slot['id']; ?>">
                                            <?php echo date('M d, H:i', strtotime($slot['start_time'])) . ' - ' . date('M d, H:i', strtotime($slot['end_time'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" <?php echo empty($my_slots) ? 'disabled' : ''; ?>>
                            <i class="bi bi-arrow-left-right me-1"></i> Post Trade Offer
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0"><i class="bi bg-list-check me-2"></i>Active & Pending Trade Requests</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Proposer</th>
                                    <th>Offered Shift</th>
                                    <th>Counter Shift</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_trades)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No trade requests found for this department.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_trades as $tr): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($tr['proposer_name']); ?></strong></td>
                                            <td>
                                                <small class="d-block text-muted"><?php echo date('M d, Y', strtotime($tr['offered_start'])); ?></small>
                                                <span><?php echo date('H:i', strtotime($tr['offered_start'])) . ' - ' . date('H:i', strtotime($tr['offered_end'])); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($tr['counter_slot_id']): ?>
                                                    <small class="d-block text-muted"><?php echo date('M d, Y', strtotime($tr['counter_start'])); ?></small>
                                                    <span><?php echo date('H:i', strtotime($tr['counter_start'])) . ' - ' . date('H:i', strtotime($tr['counter_end'])); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Giveaway / Take</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = 'bg-secondary';
                                                if ($tr['status'] === 'open') $badge_class = 'bg-info text-dark';
                                                elseif ($tr['status'] === 'offered') $badge_class = 'bg-warning text-dark';
                                                elseif ($tr['status'] === 'agreed') $badge_class = 'bg-primary';
                                                elseif ($tr['status'] === 'approved') $badge_class = 'bg-success';
                                                elseif ($tr['status'] === 'rejected') $badge_class = 'bg-danger';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>"><?php echo strtoupper($tr['status']); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($tr['status'] === 'open' && $tr['proposing_user_id'] != $current_user_id): ?>
                                                    <form method="POST" action="<?php echo url_for('oncall_trades'); ?>" class="d-inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="accept_take">
                                                        <input type="hidden" name="trade_id" value="<?php echo $tr['id']; ?>">
                                                        <input type="hidden" name="department_id" value="<?php echo $selected_dept; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-success mb-1">Take Shift</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($tr['status'] === 'offered' && $tr['proposing_user_id'] == $current_user_id): ?>
                                                    <form method="POST" action="<?php echo url_for('oncall_trades'); ?>" class="d-inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="proposer_agree">
                                                        <input type="hidden" name="trade_id" value="<?php echo $tr['id']; ?>">
                                                        <input type="hidden" name="department_id" value="<?php echo $selected_dept; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success mb-1">Agree Swap</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($can_approve && $tr['status'] === 'agreed'): ?>
                                                    <form method="POST" action="<?php echo url_for('oncall_trades'); ?>" class="d-inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="approve_trade">
                                                        <input type="hidden" name="trade_id" value="<?php echo $tr['id']; ?>">
                                                        <input type="hidden" name="department_id" value="<?php echo $selected_dept; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success mb-1"><i class="bi bi-check-lg"></i> Approve</button>
                                                    </form>
                                                    <form method="POST" action="<?php echo url_for('oncall_trades'); ?>" class="d-inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="reject_trade">
                                                        <input type="hidden" name="trade_id" value="<?php echo $tr['id']; ?>">
                                                        <input type="hidden" name="department_id" value="<?php echo $selected_dept; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger mb-1"><i class="bi bi-x-lg"></i> Reject</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($tr['proposing_user_id'] == $current_user_id && in_array($tr['status'], ['open', 'offered'])): ?>
                                                    <form method="POST" action="<?php echo url_for('oncall_trades'); ?>" class="d-inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="cancel_trade">
                                                        <input type="hidden" name="trade_id" value="<?php echo $tr['id']; ?>">
                                                        <input type="hidden" name="department_id" value="<?php echo $selected_dept; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary mb-1">Cancel</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
