<?php
// tasks/commportal-sync-task.php - Background telephony sync task handler

require_once __DIR__ . '/../models/oncall-models.php';

function oncall_task_commportal_sync() {
    oncall_sync_commportal_background();
}
