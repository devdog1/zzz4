<?php
// cron_sync_zabbix_groups.php - Run every minute via cron to sync active on-call user to mapped Zabbix groups and CommPortal forwarding.

require_once __DIR__ . '/models.php';

// Parse command line options for verbose flag
$verbose = false;
if (isset($argv)) {
    foreach ($argv as $arg) {
        if ($arg === '--verbose' || $arg === '-v') {
            $verbose = true;
        }
    }
}
if ($verbose) {
    $GLOBALS['commportal_verbose'] = true;
    echo "[VERBOSE] Verbose logging enabled. Diagnostic logs will be printed to stdout.\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Starting On-Call Synchronization (Zabbix + CommPortal)...\n";

try {
    $db = get_oncall_db();
    $departments = get_all_departments();
    $now = time();
    $now_str = date('Y-m-d H:i:s', $now);

    // Define a small range around the current time to query the active slot/override
    $start_str = date('Y-m-d H:i:s', $now - 10);
    $end_str = date('Y-m-d H:i:s', $now + 10);

    foreach ($departments as $dept) {
        $dept_id = $dept['id'];

        // Fetch current on-call user for this department
        $segments = get_final_schedule_for_department($dept_id, $start_str, $end_str);
        $active_user = null;
        foreach ($segments as $seg) {
            if ($now >= $seg['start'] && $now <= $seg['end']) {
                $active_user = $seg;
                break;
            }
        }

        if ($active_user) {
            // Retrieve their full record from database
            $u_stmt = $db->prepare("SELECT zabbix_userid, phone FROM users WHERE id = ?");
            $u_stmt->execute([$active_user['user_id']]);
            $u_res = $u_stmt->fetch();

            $z_userid = $u_res ? $u_res['zabbix_userid'] : null;
            $active_user_phone = $u_res ? $u_res['phone'] : null;

            // =========================================================
            // PART A: ZABBIX USER GROUPS SYNCHRONIZATION
            // =========================================================
            if ($z_userid) {
                // Get mapped Zabbix group records for this department
                $stmt = $db->prepare("
                    SELECT zabbix_usrgrp_id, last_oncall_userid
                    FROM department_zabbix_groups
                    WHERE department_id = ?
                ");
                $stmt->execute([$dept_id]);
                $mappings = $stmt->fetchAll();

                foreach ($mappings as $map) {
                    $usrgrp_id = $map['zabbix_usrgrp_id'];
                    $last_z_userid = $map['last_oncall_userid'];

                    if ($z_userid != $last_z_userid) {
                        echo "Syncing Zabbix Group {$usrgrp_id}: On-Call changed from user ID '{$last_z_userid}' to '{$z_userid}'\n";

                        $success = trigger_zabbix_user_group_update($usrgrp_id, $z_userid);
                        if ($success) {
                            // DB log the change explicitly
                            log_action('CRON_SYNC_ZABBIX_GROUP_MEMBER_CHANGED', [
                                'department_id' => $dept_id,
                                'department_name' => $dept['name'],
                                'zabbix_usrgrp_id' => $usrgrp_id,
                                'old_oncall_zabbix_userid' => $last_z_userid,
                                'new_oncall_zabbix_userid' => $z_userid,
                                'oncall_user_id' => $active_user['user_id']
                            ]);

                            // Update cache
                            $up_stmt = $db->prepare("
                                UPDATE department_zabbix_groups
                                SET last_oncall_userid = ?
                                WHERE department_id = ? AND zabbix_usrgrp_id = ?
                            ");
                            $up_stmt->execute([$z_userid, $dept_id, $usrgrp_id]);
                            echo "Successfully updated Zabbix user group {$usrgrp_id} members to user ID {$z_userid}.\n";
                        } else {
                            echo "Error: Failed to call Zabbix API to update user group {$usrgrp_id}.\n";
                        }
                    }
                }
            } else {
                echo "Warning: Active on-call user '{$active_user['name']} {$active_user['surname']}' does not have a linked Zabbix User ID.\n";
            }

            // =========================================================
            // PART B: COMMPORTAL UNCONDITIONAL CALL FORWARDING SYNC
            // =========================================================
            if (!empty($active_user_phone)) {
                $comm_accounts = get_department_commportal_accounts($dept_id);
                foreach ($comm_accounts as $account) {
                    $last_forwarded = $account['last_forwarded_phone'];
                    if ($active_user_phone !== $last_forwarded) {
                        echo "Syncing CommPortal Account {$account['phone_number']}: Forwarding changed from '{$last_forwarded}' to '{$active_user_phone}'\n";

                        try {
                            $cp = new CommPortal($account);
                            $cf = $cp->getUnconditionalCallForwarding();

                            // If forwarding format retrieved successfully, update and set it
                            if ($cf && isset($cf['Meta_Subscriber_UnconditionalCallForwarding'])) {
                                $cf['Meta_Subscriber_UnconditionalCallForwarding']['ForwardingDestination'] = $active_user_phone;
                                $cf['Meta_Subscriber_UnconditionalCallForwarding']['Active'] = true;

                                $success = $cp->setUnconditionalCallForwarding($cf);
                                if ($success) {
                                    // Update database cache
                                    $up_stmt = $db->prepare("
                                        UPDATE commportal_accounts
                                        SET last_forwarded_phone = ?
                                        WHERE id = ?
                                    ");
                                    $up_stmt->execute([$active_user_phone, $account['id']]);

                                    // Log action explicitly
                                    log_action('CRON_SYNC_COMMPORTAL_FORWARDING_CHANGED', [
                                        'department_id' => $dept_id,
                                        'department_name' => $dept['name'],
                                        'commportal_account_id' => $account['id'],
                                        'commportal_phone_number' => $account['phone_number'],
                                        'old_forwarding_destination' => $last_forwarded,
                                        'new_forwarding_destination' => $active_user_phone,
                                        'oncall_user_id' => $active_user['user_id']
                                    ]);

                                    echo "Successfully forwarded CommPortal account {$account['phone_number']} to {$active_user_phone}.\n";
                                } else {
                                    echo "Error: CommPortal API returned failure response during setUnconditionalCallForwarding.\n";
                                }
                            } else {
                                echo "Error: Failed to fetch Meta_Subscriber_UnconditionalCallForwarding or session failed.\n";
                            }
                        } catch (Exception $cp_err) {
                            echo "Error communicating with CommPortal for account {$account['phone_number']}: " . $cp_err->getMessage() . "\n";
                        }
                    }
                }
            } else {
                echo "Notice: Active on-call user '{$active_user['name']} {$active_user['surname']}' does not have a phone number specified. CommPortal forwarding skipped.\n";
            }

        } else {
            echo "No active on-call user scheduled right now for department '{$dept['name']}'.\n";
        }
    }

} catch (Exception $e) {
    echo "Error during synchronization loop: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] On-Call Synchronization finished.\n";
