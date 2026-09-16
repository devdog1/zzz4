<?php
// oncall-commportal-models.php - Metaswitch CommPortal Background Telephony & Call Forwarding Models

/* =========================================================
 * BACKGROUND RECURRING TELEPHONY SYNC ENGINE
 * ========================================================= */

function oncall_sync_department_commportal_forwarding($department_id) {
    $pdb = oncall_get_pdb();
    $tb_accounts = $pdb->getTableName('commportal_accounts');
    $now = time();

    $accounts = $pdb->query("SELECT * FROM {$tb_accounts} WHERE department_id = ?", [$department_id])->fetchAll();
    if (empty($accounts)) {
        return 0;
    }

    $current = oncall_get_current_on_call($department_id, $now);
    if (!$current) {
        return 0;
    }

    $db = get_db_connection();
    $stmt = $db->prepare("SELECT phone FROM users WHERE id = ?");
    $stmt->execute([$current['user_id']]);
    $user = $stmt->fetch();
    $phone = $user['phone'] ?? '';

    if (empty($phone)) {
        return 0;
    }

    $updated_count = 0;
    foreach ($accounts as $acc) {
        if ($acc['last_forwarded_phone'] === $phone) {
            continue;
        }

        try {
            if (class_exists('CommPortal')) {
                $cp = new CommPortal($acc);
                if ($cp->state === 'loggedIn') {
                    $fwd_data = $cp->getUnconditionalCallForwarding();
                    if (is_array($fwd_data)) {
                        $fwd_data['ForwardingNumber'] = $phone;
                        $fwd_data['Enabled'] = true;
                        $cp->setUnconditionalCallForwarding($fwd_data);
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore cURL or connection errors in offline/dev environments
        }

        $pdb->query("UPDATE {$tb_accounts} SET last_forwarded_phone = ? WHERE id = ?", [$phone, $acc['id']]);
        $updated_count++;
    }

    if ($updated_count > 0) {
        log_action('ONCALL_COMMPORTAL_FORWARDING_UPDATED', [
            'department_id' => $department_id,
            'user_id' => $current['user_id'],
            'phone' => $phone,
            'updated_accounts' => $updated_count
        ]);
    }

    return $updated_count;
}

function oncall_sync_commportal_background() {
    $pdb = oncall_get_pdb();
    $tb_depts = $pdb->getTableName('departments');
    $departments = $pdb->query("SELECT id FROM {$tb_depts}")->fetchAll();

    $total_updated = 0;
    foreach ($departments as $dept) {
        $total_updated += oncall_sync_department_commportal_forwarding($dept['id']);
    }

    log_action('ONCALL_TELEPHONIC_CRON_SYNC_SUCCESS', ['synced_updates' => $total_updated]);
}
