# On-Call Schedule Manager Plugin for zzz5 Framework

Enterprise On-Call Rotation, Shift Trade Center, Manual Schedule Overrides, Metaswitch CommPortal Call Forwarding, and Zabbix Integration.

## Architecture & Design

This plugin converts the monolithic On-Call Management system into a modular plugin for the `zzz5` Portal Framework. It leverages `PluginDatabase` for isolated database table prefixing (`plug_oncall_manager_*`), implements dynamic RBAC, registers background scheduled tasks, and exposes inter-plugin shared services for sibling plugins.

### Directory Structure

```text
plugins/oncall-manager/
├── plugin.php                   # Primary entry file & hook orchestrator
├── README.md                    # Plugin documentation
├── CommPortal.php               # Metaswitch CommPortal REST API integration
├── models/                      # Modular system- & function-based domain models
│   ├── oncall-models.php        # Loader aggregating all subsystem model files
│   ├── oncall-core-models.php   # Database prefix helpers, settings API & department logic
│   ├── oncall-schedule-models.php # 365-day rotation generator, clipping algorithm & NOC mode
│   ├── oncall-trades-models.php # Shift trade proposals, counter-swaps & manager approvals
│   ├── oncall-commportal-models.php # CommPortal telephony forwarding synchronization
│   └── oncall-zabbix-models.php # Zabbix user sync & user group auto-assignments
├── views/                       # Route view templates
│   ├── calendar-view.php        # FullCalendar rotation visualizer
│   ├── trades-view.php          # Peer-to-peer shift trade center
│   ├── overrides-view.php       # Manual schedule overrides
│   ├── departments-view.php     # Department & roster management
│   ├── generate-view.php        # 365-day shift rotation generator
│   ├── telephony-view.php       # CommPortal telephony configuration
│   └── settings-view.php        # Zabbix API & NOC business hours configuration
├── tasks/                       # Background task handlers for Scheduler API
│   ├── commportal-sync-task.php
│   ├── zabbix-sync-task.php
│   └── zabbix-group-assign-task.php
└── sql/                         # Database schema scripts
    ├── install.sql              # Automated table provisioning script
    └── uninstall.sql            # Table cleanup script
```

## Plugin Database Tables

All tables are prefixed with `plug_oncall_manager_` via `PluginDatabase`:
- `plug_oncall_manager_departments`
- `plug_oncall_manager_department_users`
- `plug_oncall_manager_schedule_slots`
- `plug_oncall_manager_overrides`
- `plug_oncall_manager_trade_requests`
- `plug_oncall_manager_noc_business_hours`
- `plug_oncall_manager_commportal_accounts`
- `plug_oncall_manager_settings`
- `plug_oncall_manager_zabbix_user_map`
- `plug_oncall_manager_department_zabbix_groups`

## Registered Routes

- `oncall_calendar`: Rotation visualizer with FullCalendar.
- `oncall_trades`: Shift Trade Center for proposals and approvals.
- `oncall_overrides`: Manual schedule overrides manager.
- `oncall_departments`: Department rosters, managers, and Zabbix group mappings.
- `oncall_generate`: 365-day shift rotation auto-generator.
- `oncall_telephony`: Metaswitch CommPortal account management.
- `oncall_settings`: Plugin settings (Zabbix API endpoint & NOC hours).
- `oncall_ical_feed`: Standard-compliant iCalendar (`.ics`) feed for Outlook/Apple Calendar (`index.php?route=oncall_ical_feed&userid=X`).
- `oncall_api_events`: JSON feed endpoint for FullCalendar events.

## Inter-Plugin Shared Services

Sibling plugins in the `zzz5` framework can query the On-Call plugin using `PluginManager`:

```php
// Fetch active on-call user for department ID 1
$oncall_user = PluginManager::getInstance()->callService('get_current_oncall_user', 1);

// Fetch schedule segments for a department
$segments = PluginManager::getInstance()->callService('get_department_schedule', 1, '2026-07-01 00:00:00', '2026-07-31 23:59:59');

// Fetch upcoming shifts for user ID 5
$shifts = PluginManager::getInstance()->callService('get_user_upcoming_shifts', 5, 5);
```

## Scheduled Tasks

The plugin registers 3 recurring tasks with `Scheduler`:
1. `oncall_commportal_sync`: Synchronizes CommPortal call forwarding (Interval: 60s).
2. `oncall_zabbix_sync`: Syncs Zabbix users into local user directory (Interval: 3600s).
3. `oncall_zabbix_group_assign`: Auto-assigns active on-call user to mapped Zabbix groups (Interval: 60s).
