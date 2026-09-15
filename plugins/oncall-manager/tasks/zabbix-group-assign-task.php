<?php
// tasks/zabbix-group-assign-task.php - Background Zabbix group auto assignment task handler

require_once __DIR__ . '/../models/oncall-models.php';

function oncall_task_zabbix_group_assign() {
    oncall_sync_all_departments_zabbix_groups();
}
