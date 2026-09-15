<?php
// oncall-trades-models.php - Shift Trade Center, Proposals, Swaps, Takes, and Manager Approvals

/* =========================================================
 * SHIFT TRADE CENTER ENGINE
 * ========================================================= */

function oncall_get_trade_requests_by_department($department_id = null) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');
    $tb_depts = $pdb->getTableName('departments');
    $tb_slots = $pdb->getTableName('schedule_slots');

    if ($department_id) {
        $sql = "
            SELECT tr.*,
                   d.name AS department_name,
                   pu.username AS proposing_username, COALESCE(NULLIF(pu.display_name, ''), pu.username) AS proposing_name,
                   au.username AS accepting_username, COALESCE(NULLIF(au.display_name, ''), au.username) AS accepting_name,
                   os.start_time AS offered_start_time, os.end_time AS offered_end_time,
                   cs.start_time AS counter_start_time, cs.end_time AS counter_end_time
            FROM {$tb_tr} tr
            JOIN {$tb_depts} d ON tr.department_id = d.id
            JOIN users pu ON tr.proposing_user_id = pu.id
            LEFT JOIN users au ON tr.accepting_user_id = au.id
            JOIN {$tb_slots} os ON tr.offered_slot_id = os.id
            LEFT JOIN {$tb_slots} cs ON tr.counter_slot_id = cs.id
            WHERE tr.department_id = ?
            ORDER BY tr.created_at DESC
        ";
        return $pdb->query($sql, [$department_id])->fetchAll();
    } else {
        $sql = "
            SELECT tr.*,
                   d.name AS department_name,
                   pu.username AS proposing_username, COALESCE(NULLIF(pu.display_name, ''), pu.username) AS proposing_name,
                   au.username AS accepting_username, COALESCE(NULLIF(au.display_name, ''), au.username) AS accepting_name,
                   os.start_time AS offered_start_time, os.end_time AS offered_end_time,
                   cs.start_time AS counter_start_time, cs.end_time AS counter_end_time
            FROM {$tb_tr} tr
            JOIN {$tb_depts} d ON tr.department_id = d.id
            JOIN users pu ON tr.proposing_user_id = pu.id
            LEFT JOIN users au ON tr.accepting_user_id = au.id
            JOIN {$tb_slots} os ON tr.offered_slot_id = os.id
            LEFT JOIN {$tb_slots} cs ON tr.counter_slot_id = cs.id
            ORDER BY tr.created_at DESC
        ";
        return $pdb->query($sql)->fetchAll();
    }
}

function oncall_get_trade_request_by_id($trade_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');
    $tb_depts = $pdb->getTableName('departments');
    $tb_slots = $pdb->getTableName('schedule_slots');

    $sql = "
        SELECT tr.*,
               d.name AS department_name,
               pu.username AS proposing_username, COALESCE(NULLIF(pu.display_name, ''), pu.username) AS proposing_name,
               au.username AS accepting_username, COALESCE(NULLIF(au.display_name, ''), au.username) AS accepting_name,
               os.start_time AS offered_start_time, os.end_time AS offered_end_time,
               cs.start_time AS counter_start_time, cs.end_time AS counter_end_time
        FROM {$tb_tr} tr
        JOIN {$tb_depts} d ON tr.department_id = d.id
        JOIN users pu ON tr.proposing_user_id = pu.id
        LEFT JOIN users au ON tr.accepting_user_id = au.id
        JOIN {$tb_slots} os ON tr.offered_slot_id = os.id
        LEFT JOIN {$tb_slots} cs ON tr.counter_slot_id = cs.id
        WHERE tr.id = ?
    ";
    return $pdb->query($sql, [$trade_id])->fetch();
}

function oncall_get_user_schedule_slots($user_id, $department_id) {
    $pdb = oncall_get_pdb();
    $tb_slots = $pdb->getTableName('schedule_slots');

    $sql = "
        SELECT s.*
        FROM {$tb_slots} s
        WHERE s.user_id = ? AND s.department_id = ? AND s.end_time >= NOW()
        ORDER BY s.start_time ASC
    ";
    return $pdb->query($sql, [$user_id, $department_id])->fetchAll();
}

function oncall_propose_trade($department_id, $offered_slot_id, $proposing_user_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');

    $sql = "INSERT INTO {$tb_tr} (department_id, proposing_user_id, offered_slot_id, status) VALUES (?, ?, ?, 'open')";
    $pdb->query($sql, [$department_id, $proposing_user_id, $offered_slot_id]);

    log_action('ONCALL_PROPOSE_TRADE', ['department' => $department_id, 'slot' => $offered_slot_id]);
    return true;
}

function oncall_accept_trade_take($trade_id, $accepting_user_id) {
    $pdb = oncall_get_pdb();
    $tb_tr = $pdb->getTableName('trade_requests');

    $sql = "UPDATE {$tb_tr} SET accepting_user_id = ?, status = 'agreed' WHERE id = ? AND status = 'open'";
    $pdb->query($sql, [$accepting_user_id, $trade_id]);

    log_action('ONCALL_ACCEPT_TAKE', ['trade_id' => $trade_id, 'user' => $accepting_user_id]);
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
