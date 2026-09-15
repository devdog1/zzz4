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

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            events: '<?php echo url_for('oncall_api_events') . '&department_id=' . (int)$selected_dept; ?>',
            eventDidMount: function(info) {
                if (info.event.extendedProps.description) {
                    info.el.setAttribute('title', info.event.extendedProps.description);
                }
            }
        });
        calendar.render();
    });
    </script>
    <?php
}
