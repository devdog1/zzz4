<?php
// calendar.php - Calendar Views (Department & User schedules)
require_once 'header.php';
require_once 'models.php';

$departments = get_all_departments();
$users = get_all_users();

// Default values
$view_type = $_GET['view_type'] ?? 'department';
$selected_dept_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : ($departments[0]['id'] ?? null);
$selected_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-calendar-days text-primary me-2"></i>On-Call Calendar</h1>
        <p class="text-muted">Interactive calendar displaying department rotations and individual on-call schedules.</p>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" action="calendar.php" id="filterForm" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <label for="view_type" class="form-label fw-semibold small">View Type</label>
                        <select name="view_type" id="view_type" class="form-select" onchange="toggleFilterFields(this.value);">
                            <option value="department" <?= $view_type === 'department' ? 'selected' : '' ?>>By Department</option>
                            <option value="user" <?= $view_type === 'user' ? 'selected' : '' ?>>By User</option>
                        </select>
                    </div>

                    <div class="col-md-5" id="deptFilterDiv" style="<?= $view_type === 'user' ? 'display: none;' : '' ?>">
                        <label for="department_id" class="form-label fw-semibold small">Select Department</label>
                        <select name="department_id" id="department_id" class="form-select" onchange="document.getElementById('filterForm').submit();">
                            <option value="">-- Choose Department --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>" <?= $selected_dept_id == $dept['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-5" id="userFilterDiv" style="<?= $view_type === 'department' ? 'display: none;' : '' ?>">
                        <label for="user_id" class="form-label fw-semibold small">Select User</label>
                        <select name="user_id" id="user_id" class="form-select" onchange="document.getElementById('filterForm').submit();">
                            <option value="">-- Choose User --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $selected_user_id == $u['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['name'] . ' ' . $u['surname']) ?> (@<?= htmlspecialchars($u['username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4 text-md-end align-self-end">
                        <button type="submit" class="btn btn-secondary w-100"><i class="fa-solid fa-filter me-1"></i> Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Calendar Display Card -->
<div class="card">
    <div class="card-body">
        <?php if ($view_type === 'department' && !$selected_dept_id): ?>
            <div class="alert alert-warning text-center p-4">
                <i class="fa-solid fa-circle-info me-2 fs-4"></i> Please select a department above to see the on-call calendar.
            </div>
        <?php elseif ($view_type === 'user' && !$selected_user_id): ?>
            <div class="alert alert-warning text-center p-4">
                <i class="fa-solid fa-circle-info me-2 fs-4"></i> Please select a user above to see their personal schedule.
            </div>
        <?php else: ?>
            <div id="calendar" style="min-height: 600px;"></div>
        <?php endif; ?>
    </div>
</div>

<!-- Event Details Modal -->
<div class="modal fade" id="eventModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">On-Call Shift Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered">
                    <tr>
                        <th class="w-40 bg-light">Shift Type</th>
                        <td id="modalType"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">User</th>
                        <td id="modalUser"></td>
                    </tr>
                    <tr id="modalDeptRow" style="display:none;">
                        <th class="bg-light">Department</th>
                        <td id="modalDept"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Start</th>
                        <td id="modalStart"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">End</th>
                        <td id="modalEnd"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Details / Reason</th>
                        <td id="modalDesc"></td>
                    </tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleFilterFields(viewType) {
    const deptDiv = document.getElementById('deptFilterDiv');
    const userDiv = document.getElementById('userFilterDiv');
    if (viewType === 'department') {
        deptDiv.style.display = 'block';
        userDiv.style.display = 'none';
    } else {
        deptDiv.style.display = 'none';
        userDiv.style.display = 'block';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    if (!calendarEl) return;

    // Build event URL
    let eventUrl = 'calendar_events.php?';
    <?php if ($view_type === 'department' && $selected_dept_id): ?>
        eventUrl += 'department_id=<?= $selected_dept_id ?>';
    <?php elseif ($view_type === 'user' && $selected_user_id): ?>
        eventUrl += 'user_id=<?= $selected_user_id ?>';
    <?php endif; ?>

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
        },
        events: eventUrl,
        eventClick: function(info) {
            const props = info.event.extendedProps;

            document.getElementById('modalTitle').innerText = info.event.title;
            document.getElementById('modalType').innerHTML = `<span class="badge ${props.type === 'Manual Override' ? 'bg-warning text-dark' : 'bg-success'}">${props.type}</span>`;
            document.getElementById('modalUser').innerText = props.user + ' (@' + props.username + ')';

            if (props.department) {
                document.getElementById('modalDeptRow').style.display = 'table-row';
                document.getElementById('modalDept').innerText = props.department;
            } else {
                document.getElementById('modalDeptRow').style.display = 'none';
            }

            // Formatting dates beautifully
            const startStr = info.event.start.toLocaleString();
            const endStr = info.event.end ? info.event.end.toLocaleString() : 'N/A';
            document.getElementById('modalStart').innerText = startStr;
            document.getElementById('modalEnd').innerText = endStr;

            document.getElementById('modalDesc').innerText = props.description || 'N/A';

            const myModal = new bootstrap.Modal(document.getElementById('eventModal'));
            myModal.show();
        }
    });

    calendar.render();
});
</script>

<?php require_once 'footer.php'; ?>
