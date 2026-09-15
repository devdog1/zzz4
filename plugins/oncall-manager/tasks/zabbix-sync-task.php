<?php
// tasks/zabbix-sync-task.php - Background Zabbix users sync task handler

require_once __DIR__ . '/../models/oncall-models.php';

function oncall_task_zabbix_sync() {
    oncall_sync_zabbix_via_api();
}
