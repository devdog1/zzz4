<?php
// generate.php - 365-day rotation generator
require_once 'header.php';
require_once 'models.php';

$message = '';
$error = '';

$departments = get_all_departments();

// Handle selection of department
$dept_id = isset($_REQUEST['department_id']) ? (int)$_REQUEST['department_id'] : null;
$dept_users = [];
$is_authorized = false;

if ($dept_id) {
    if (can_manage_department($dept_id)) {
        $is_authorized = true;
        $dept_users = get_department_users($dept_id);
    } else {
        $error = 'Unauthorized: Only the designated Manager for this department or a Global Administrator can generate its schedule.';
    }
}

// Handle generation form submission
if (isset($_POST['generate'])) {
    $start_date = $_POST['start_date'] ?? '';
    $included_users = $_POST['include_users'] ?? [];
    $user_orders = $_POST['user_order'] ?? [];

    if (!$is_authorized) {
        $error = 'Unauthorized action.';
    } elseif (empty($start_date)) {
        $error = 'Please select a rotation start date.';
    } elseif (empty($included_users)) {
        $error = 'Please select at least one member for the rotation.';
    } else {
        // Build sorted array of users based on order
        $ordered_members = [];
        foreach ($included_users as $u_id) {
            $order = isset($user_orders[$u_id]) ? (int)$user_orders[$u_id] : 0;
            $ordered_members[] = [
                'user_id' => (int)$u_id,
                'order' => $order
            ];
        }

        // Sort by order
        usort($ordered_members, function($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        $final_user_ids = array_column($ordered_members, 'user_id');

        // Parse custom shifts template from input
        $shifts_template = [];
        $raw_shifts = $_POST['shift'] ?? [];
        foreach ($raw_shifts as $s) {
            if (!empty($s['start_time']) && !empty($s['end_time'])) {
                $shifts_template[] = [
                    'start_day' => (int)$s['start_day'],
                    'start_time' => $s['start_time'],
                    'end_day' => (int)$s['end_day'],
                    'end_time' => $s['end_time']
                ];
            }
        }

        try {
            generate_365_day_schedule($dept_id, $final_user_ids, $start_date, $shifts_template);
            $message = "365-day on-call schedule generated successfully! 52 weeks of custom rotation shifts have been created starting from the week of the selected start date.";
        } catch (Exception $e) {
            $error = "Failed to generate schedule: " . $e->getMessage();
        }
    }
}
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-arrows-spin text-primary me-2"></i>Generate On-Call Rotation</h1>
        <p class="text-muted">Generate a 365-day / 52-week 24/7 on-call schedule. Rotations run Monday 5:00 PM to Monday 5:00 PM.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
        <div class="mt-2">
            <a href="calendar.php?department_id=<?= $dept_id ?>" class="btn btn-sm btn-success"><i class="fa-solid fa-calendar me-1"></i> View Calendar</a>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Step 1: Select Department -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white">
                <i class="fa-solid fa-sitemap me-2 text-primary"></i>1. Select Department
            </div>
            <div class="card-body">
                <form method="GET" action="generate.php" id="deptForm">
                    <div class="mb-3">
                        <label for="department_id" class="form-label fw-semibold">Target Department</label>
                        <select name="department_id" id="department_id" class="form-select" onchange="document.getElementById('deptForm').submit();" required>
                            <option value="">-- Choose Department --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>" <?= ($dept_id == $dept['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Step 2: Configure Rotation Details -->
    <?php if ($dept_id && $is_authorized): ?>
        <div class="col-lg-8">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <i class="fa-solid fa-gears me-2"></i>2. Configure Rotation & Generate
                </div>
                <div class="card-body">
                    <?php if (empty($dept_users)): ?>
                        <div class="text-center p-4">
                            <h5 class="text-danger">No users assigned to this department!</h5>
                            <p class="text-muted small">You cannot generate a schedule because there are no users in this department.</p>
                            <a href="departments.php?manage_id=<?= $dept_id ?>" class="btn btn-sm btn-outline-danger mt-2">
                                <i class="fa-solid fa-users me-1"></i> Assign Users to Department
                            </a>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="department_id" value="<?= $dept_id ?>">

                            <div class="row mb-4">
                                <div class="col-md-5 border-end">
                                    <label for="start_date" class="form-label fw-semibold">Rotation Start Week (Monday)</label>
                                    <input type="date" class="form-control" name="start_date" id="start_date" value="<?= date('Y-m-d') ?>" required>
                                    <div class="form-text text-muted small">
                                        The 52-week (365-day) schedule will align and start from the Monday of the selected date's week.
                                    </div>
                                </div>
                                <div class="col-md-7 ps-md-4">
                                    <label class="form-label fw-semibold text-primary"><i class="fa-solid fa-clock me-1"></i>Weekly Shifts Template</label>
                                    <div class="form-text text-muted small mb-2">Define 1 or more custom shifts per week. Rows with blank times will be ignored.</div>

                                    <?php for ($s = 0; $s < 4; $s++): ?>
                                        <div class="row g-1 align-items-center mb-1">
                                            <div class="col-4">
                                                <select name="shift[<?= $s ?>][start_day]" class="form-select form-select-sm">
                                                    <option value="1" <?= $s == 0 ? 'selected' : '' ?>>Monday</option>
                                                    <option value="2">Tuesday</option>
                                                    <option value="3">Wednesday</option>
                                                    <option value="4">Thursday</option>
                                                    <option value="5">Friday</option>
                                                    <option value="6">Saturday</option>
                                                    <option value="7">Sunday</option>
                                                </select>
                                            </div>
                                            <div class="col-2">
                                                <input type="time" name="shift[<?= $s ?>][start_time]" class="form-control form-control-sm" value="<?= $s == 0 ? '17:00' : '' ?>">
                                            </div>
                                            <div class="col-1 text-center small text-muted">to</div>
                                            <div class="col-3">
                                                <select name="shift[<?= $s ?>][end_day]" class="form-select form-select-sm">
                                                    <option value="1" <?= $s == 0 ? 'selected' : '' ?>>Monday</option>
                                                    <option value="2">Tuesday</option>
                                                    <option value="3">Wednesday</option>
                                                    <option value="4">Thursday</option>
                                                    <option value="5">Friday</option>
                                                    <option value="6">Saturday</option>
                                                    <option value="7">Sunday</option>
                                                </select>
                                            </div>
                                            <div class="col-2">
                                                <input type="time" name="shift[<?= $s ?>][end_time]" class="form-control form-control-sm" value="<?= $s == 0 ? '17:00' : '' ?>">
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <h5 class="h6 fw-bold mb-3">Select Members and Set Rotation Order</h5>
                            <p class="text-muted small mb-3">
                                Check the box for each member that should be part of the on-call pool, and specify their order number (e.g. 1, 2, 3...) to determine who goes first, second, etc.
                            </p>

                            <div class="table-responsive mb-4">
                                <table class="table table-bordered table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 10%;">Include?</th>
                                            <th>Member Name</th>
                                            <th>Username</th>
                                            <th style="width: 25%;">Rotation Order</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $idx = 1;
                                        foreach ($dept_users as $user):
                                        ?>
                                            <tr>
                                                <td class="text-center">
                                                    <input class="form-check-input" type="checkbox" name="include_users[]" value="<?= $user['id'] ?>" id="include_<?= $user['id'] ?>" checked>
                                                </td>
                                                <td>
                                                    <label class="form-check-label fw-semibold" for="include_<?= $user['id'] ?>">
                                                        <?= htmlspecialchars($user['name'] . ' ' . $user['surname']) ?>
                                                    </label>
                                                </td>
                                                <td>
                                                    <code>@<?= htmlspecialchars($user['username']) ?></code>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm" name="user_order[<?= $user['id'] ?>]" value="<?= $idx++ ?>" min="1" step="1" required>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="alert alert-warning small">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                                <strong>Warning:</strong> Generating a new schedule will overwrite any existing base 365-day schedule slots for this department. Any manual overrides you've defined will still be preserved and applied on top of the new rotation!
                            </div>

                            <button type="submit" name="generate" class="btn btn-success btn-lg w-100">
                                <i class="fa-solid fa-arrows-spin me-2"></i>Generate 365-Day Schedule
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-lg-8">
            <div class="alert alert-light border text-center p-5">
                <i class="fa-solid fa-circle-info text-muted mb-3" style="font-size: 2.5rem;"></i>
                <h5>Select an Authorized Department</h5>
                <p class="text-muted">Please select a department from the panel on the left where you are the designated on-call group manager or an administrator.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
