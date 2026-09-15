<?php
// oncall-zabbix-models.php - Zabbix Integration API and Group Auto-Assignment Models

/* =========================================================
 * ZABBIX RECURRING SYNC ENGINE & GROUP ASSIGNMENTS
 * ========================================================= */

function oncall_sync_zabbix_via_api() {
    $pdb = oncall_get_pdb();
    $tb_map = $pdb->getTableName('zabbix_user_map');
    $db = get_db_connection();

    $api_url = oncall_get_setting('zabbix_api_url', 'http://127.0.0.1/zabbix/api_jsonrpc.php');
    $api_token = oncall_get_setting('zabbix_api_token', '');

    $is_mock = (strpos($api_url, '127.0.0.1') !== false || empty($api_token));
    $zabbix_users = [];

    if ($is_mock) {
        $zabbix_users = [
            [
                'userid' => '101',
                'username' => 'alice',
                'name' => 'Alice',
                'surname' => 'Smith',
                'medias' => [
                    ['mediatypeid' => '4', 'sendto' => '+1-555-9001']
                ]
            ],
            [
                'userid' => '102',
                'username' => 'bob',
                'name' => 'Bob',
                'surname' => 'Jones',
                'medias' => [
                    ['mediatypeid' => '4', 'sendto' => '+1-555-9002']
                ]
            ],
            [
                'userid' => '103',
                'username' => 'charlie',
                'name' => 'Charlie',
                'surname' => 'Brown',
                'medias' => [
                    ['mediatypeid' => '4', 'sendto' => '+1-555-9003']
                ]
            ]
        ];
    } else {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'user.get',
            'params' => [
                'output' => ['userid', 'username', 'name', 'surname'],
                'selectMedias' => 'extend'
            ],
            'auth' => $api_token,
            'id' => 1
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['result'])) {
                $zabbix_users = $data['result'];
            }
        }
    }

    if (empty($zabbix_users)) {
        return 0;
    }

    $synced_count = 0;
    $user_domain = ltrim(oncall_get_setting('zabbix_sync_domain', 'example.com'), '@');

    foreach ($zabbix_users as $zu) {
        $z_username = $zu['username'];
        $z_id = $zu['userid'];

        if (filter_var($z_username, FILTER_VALIDATE_EMAIL)) {
            $email = $z_username;
        } else {
            $email = $z_username . '@' . $user_domain;
        }

        // Require username to be the full email address in the users table
        $username = $email;
        $display_name = trim(($zu['name'] ?? '') . ' ' . ($zu['surname'] ?? '')) ?: $z_username;

        $phone = '';
        if (!empty($zu['medias']) && is_array($zu['medias'])) {
            foreach ($zu['medias'] as $m) {
                if (isset($m['mediatypeid']) && $m['mediatypeid'] == 4) {
                    $phone = $m['sendto'] ?? '';
                    break;
                }
            }
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR username = ?");
        $stmt->execute([$username, $email, $z_username]);
        $local = $stmt->fetch();

        if ($local) {
            $local_user_id = $local['id'];
            $stmt_u = $db->prepare("UPDATE users SET username = ?, email = ?, display_name = ?, phone = ? WHERE id = ?");
            $stmt_u->execute([$username, $email, $display_name, $phone, $local_user_id]);
        } else {
            $stmt_i = $db->prepare("INSERT INTO users (username, email, display_name, phone, auto_provisioned) VALUES (?, ?, ?, ?, 1)");
            $stmt_i->execute([$username, $email, $display_name, $phone]);
            $local_user_id = $db->lastInsertId();
        }

        $pdb->query("
            INSERT INTO {$tb_map} (zabbix_userid, local_user_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE local_user_id = ?
        ", [$z_id, $local_user_id, $local_user_id]);

        $synced_count++;
    }

    log_action('ON_CALL_ZABBIX_API_SYNC_SUCCESS', ['synced_users' => $synced_count]);
    return $synced_count;
}

function oncall_trigger_zabbix_user_group_update($usrgrp_id, $zabbix_userid) {
    $api_url = oncall_get_setting('zabbix_api_url', 'http://127.0.0.1/zabbix/api_jsonrpc.php');
    $api_token = oncall_get_setting('zabbix_api_token', '');

    $payload = [
        'jsonrpc' => '2.0',
        'method' => 'usergroup.update',
        'params' => [
            'usrgrpid' => (string)$usrgrp_id,
            'users' => [
                ['userid' => (string)$zabbix_userid]
            ]
        ],
        'id' => 1
    ];

    $headers = ["Content-Type: application/json"];
    if (!empty($api_token)) {
        $headers[] = "Authorization: Bearer " . $api_token;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);

    log_action('ONCALL_ZABBIX_GROUP_UPDATE', [
        'usrgrpid' => $usrgrp_id,
        'userid' => $zabbix_userid,
        'status' => $result !== false ? 'success' : 'failed'
    ]);

    return $result !== false;
}

function oncall_sync_all_departments_zabbix_groups() {
    $pdb = oncall_get_pdb();
    $tb_zg = $pdb->getTableName('department_zabbix_groups');
    $tb_map = $pdb->getTableName('zabbix_user_map');
    $now = time();

    $groups = $pdb->query("SELECT * FROM {$tb_zg}")->fetchAll();
    if (empty($groups)) {
        return;
    }

    foreach ($groups as $g) {
        $dept_id = $g['department_id'];
        $grp_id = $g['zabbix_usrgrp_id'];
        $last_user = $g['last_oncall_userid'];

        $current = oncall_get_current_on_call($dept_id, $now);
        if (!$current) {
            continue;
        }

        $local_user_id = $current['user_id'];

        $map = $pdb->query("SELECT zabbix_userid FROM {$tb_map} WHERE local_user_id = ?", [$local_user_id])->fetch();
        if (!$map) {
            continue;
        }

        $zabbix_userid = $map['zabbix_userid'];

        if ($zabbix_userid != $last_user) {
            $success = oncall_trigger_zabbix_user_group_update($grp_id, $zabbix_userid);
            if ($success) {
                $pdb->query("
                    UPDATE {$tb_zg}
                    SET last_oncall_userid = ?
                    WHERE department_id = ? AND zabbix_usrgrp_id = ?
                ", [$zabbix_userid, $dept_id, $grp_id]);
            }
        }
    }
}
