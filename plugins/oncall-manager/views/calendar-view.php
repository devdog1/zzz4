<?php
// views/calendar-view.php - On-Call Calendar Rotation View

function oncall_render_calendar_page() {
    $departments = oncall_get_all_departments();
    $selected_dept = $_GET['department_id'] ?? ($departments[0]['id'] ?? 1);
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">On-Call Calendar Rotation</h1>
            <p class="text-muted mb-0">Interactive rotation visualizer powered by FullCalendar engine</p>
        </div>
        <div>
            <?php
            $current_user_id = $_SESSION['user_id'] ?? null;
            $user_ical_token = $current_user_id ? oncall_get_user_ical_token($current_user_id) : null;
            $dept_ical_token = $selected_dept ? oncall_get_department_ical_token($selected_dept) : null;

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

            $user_ical_url = $user_ical_token ? "{$scheme}://{$host}/index.php?route=oncall_ical_feed&token={$user_ical_token}" : '';
            $team_ical_url = $dept_ical_token ? "{$scheme}://{$host}/index.php?route=oncall_ical_feed&token={$dept_ical_token}" : '';
            ?>
            <?php if ($user_ical_url || $team_ical_url): ?>
                <button type="button" class="btn btn-outline-secondary me-2" data-bs-toggle="modal" data-bs-target="#icalFeedModal">
                    <i class="bi bi-calendar-event me-1"></i> iCal Feeds
                </button>
            <?php endif; ?>
            <?php if (has_permission('manage_schedule')): ?>
                <a href="<?php echo url_for('oncall_generate'); ?>" class="btn btn-outline-primary me-2">
                    <i class="bi bi-magic me-1"></i> Shift Generator
                </a>
                <a href="<?php echo url_for('oncall_overrides'); ?>" class="btn btn-primary">
                    <i class="bi bi-calendar-plus me-1"></i> Add Manual Override
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="<?php echo url_for('oncall_calendar'); ?>" class="row g-3 align-items-center">
                <input type="hidden" name="route" value="oncall_calendar">
                <div class="col-auto">
                    <label for="department_id" class="col-form-label fw-bold">Select Department:</label>
                </div>
                <div class="col-auto">
                    <select name="department_id" id="department_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo ($selected_dept == $dept['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?> <?php echo !empty($dept['noc_mode']) ? '(NOC Active)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div id="calendar" style="min-height: 650px;"></div>
        </div>
    </div>

    <?php if ($user_ical_url || $team_ical_url): ?>
    <!-- Modal for iCal Subscription Feeds -->
    <div class="modal fade" id="icalFeedModal" tabindex="-1" aria-labelledby="icalFeedModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="icalFeedModalLabel">
                        <i class="bi bi-shield-lock text-primary me-2"></i>Private iCalendar (.ics) Subscription Feeds
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-4">
                        Subscribe to your personal or department team schedule in Microsoft Outlook, Apple Calendar, or Google Calendar using the private UUID feed links below. External calendar clients can subscribe directly without requiring browser login.
                    </p>

                    <?php if ($user_ical_url): ?>
                        <div class="mb-4">
                            <label for="userIcalUrlInput" class="form-label fw-bold text-dark">
                                <i class="bi bi-person-fill text-primary me-1"></i> Personal On-Call Schedule Feed:
                            </label>
                            <div class="input-group">
                                <input type="text" id="userIcalUrlInput" class="form-control font-monospace text-dark bg-light" value="<?php echo htmlspecialchars($user_ical_url); ?>" readonly>
                                <button class="btn btn-primary" type="button" onclick="copyIcalFeedUrl('userIcalUrlInput', 'Personal')">
                                    <i class="bi bi-clipboard me-1"></i> Copy Link
                                </button>
                            </div>
                            <div class="form-text text-muted">
                                Includes all your assigned on-call shifts across all departments.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($team_ical_url): ?>
                        <div class="mb-3">
                            <label for="teamIcalUrlInput" class="form-label fw-bold text-dark">
                                <i class="bi bi-people-fill text-success me-1"></i> Team/Department On-Call Schedule Feed:
                            </label>
                            <div class="input-group">
                                <input type="text" id="teamIcalUrlInput" class="form-control font-monospace text-dark bg-light" value="<?php echo htmlspecialchars($team_ical_url); ?>" readonly>
                                <button class="btn btn-success" type="button" onclick="copyIcalFeedUrl('teamIcalUrlInput', 'Team')">
                                    <i class="bi bi-clipboard me-1"></i> Copy Link
                                </button>
                            </div>
                            <div class="form-text text-muted">
                                Includes complete rotation shifts for the currently selected department.
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-warning small mb-0 mt-3">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>Security Notice:</strong> Keep these private URLs secure. Anyone with these links can subscribe to the calendar feed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    function copyIcalFeedUrl(inputId, label) {
        var copyText = document.getElementById(inputId);
        if (!copyText) return;
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
        alert(label + " iCal feed URL copied to clipboard!");
    }
    </script>
    <?php endif; ?>

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        if (!calendarEl) return;

        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            timeZone: 'local',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
            },
            buttonText: {
                today: 'Today',
                month: 'Month',
                week: 'Week',
                day: 'Day',
                list: 'List'
            },
            events: '<?php echo url_for('oncall_api_events') . '&department_id=' . (int)$selected_dept; ?>',
            eventDidMount: function(info) {
                if (info.event.extendedProps.description) {
                    info.el.setAttribute('title', info.event.extendedProps.description);
                }
            },
            eventTimeFormat: {
                hour: '2-digit',
                minute: '2-digit',
                meridiem: 'short'
            }
        });
        calendar.render();
    });
    </script>
    <?php
}
