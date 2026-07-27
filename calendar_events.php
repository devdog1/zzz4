<?php
// calendar_events.php - JSON endpoint for FullCalendar events
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once 'models.php';

$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : null;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

// FullCalendar passes start and end as GET parameters in ISO8601 format
$start_iso = $_GET['start'] ?? '';
$end_iso = $_GET['end'] ?? '';

if (empty($start_iso) || empty($end_iso)) {
    // Default to a 365-day range around today if not supplied
    $start_time_str = date('Y-m-d H:i:s', strtotime('-180 days'));
    $end_time_str = date('Y-m-d H:i:s', strtotime('+185 days'));
} else {
    $start_time_str = date('Y-m-d H:i:s', strtotime($start_iso));
    $end_time_str = date('Y-m-d H:i:s', strtotime($end_iso));
}

$events = [];

if ($department_id) {
    // Fetch department schedule
    $segments = get_final_schedule_for_department($department_id, $start_time_str, $end_time_str);
    foreach ($segments as $seg) {
        $name = $seg['name'] . ' ' . $seg['surname'];
        $title = $name . ' (@' . $seg['username'] . ')';
        if ($seg['is_override']) {
            $title .= ' [Override]';
        }

        $events[] = [
            'id' => 'dept_' . $department_id . '_' . $seg['start'] . '_' . $seg['user_id'],
            'title' => $title,
            'start' => date('c', $seg['start']),
            'end' => date('c', $seg['end']),
            'backgroundColor' => $seg['is_override'] ? '#ffc107' : '#198754',
            'borderColor' => $seg['is_override'] ? '#ffc107' : '#198754',
            'textColor' => $seg['is_override'] ? '#000000' : '#ffffff',
            'extendedProps' => [
                'user' => $name,
                'username' => $seg['username'],
                'type' => $seg['is_override'] ? 'Manual Override' : 'Base Schedule',
                'description' => $seg['description']
            ]
        ];
    }
} elseif ($user_id) {
    // Fetch user schedule (potentially across multiple departments)
    $segments = get_final_schedule_for_user($user_id, $start_time_str, $end_time_str);
    foreach ($segments as $seg) {
        $dept_name = $seg['department_name'] ?? 'On-Call';
        $title = '[' . $dept_name . '] On-Call Shift';
        if ($seg['is_override']) {
            $title .= ' (Override)';
        }

        $events[] = [
            'id' => 'user_' . $user_id . '_' . $seg['start'],
            'title' => $title,
            'start' => date('c', $seg['start']),
            'end' => date('c', $seg['end']),
            'backgroundColor' => $seg['is_override'] ? '#fd7e14' : '#0d6efd',
            'borderColor' => $seg['is_override'] ? '#fd7e14' : '#0d6efd',
            'textColor' => '#ffffff',
            'extendedProps' => [
                'user' => $seg['name'] . ' ' . $seg['surname'],
                'username' => $seg['username'],
                'department' => $dept_name,
                'type' => $seg['is_override'] ? 'Manual Override' : 'Base Schedule',
                'description' => $seg['description']
            ]
        ];
    }
}

echo json_encode($events);
exit;
