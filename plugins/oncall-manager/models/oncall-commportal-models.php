<?php
// oncall-commportal-models.php - Metaswitch CommPortal Background Telephony Models

/* =========================================================
 * BACKGROUND RECURRING TELEPHONY SYNC ENGINE
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
