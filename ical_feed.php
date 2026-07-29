<?php
// ical_feed.php - Standard-compliant iCalendar (.ics) feed for MS Outlook integration with local timezone support
require_once 'models.php';

// Accept userid or user_id parameter
$user_id = isset($_GET['userid']) ? (int)$_GET['userid'] : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : null);

if (!$user_id) {
    header("HTTP/1.1 400 Bad Request");
    echo "Error: Missing userid parameter.";
    exit;
}

$user = get_user_by_id($user_id);
if (!$user) {
    header("HTTP/1.1 404 Not Found");
    echo "Error: User not found.";
    exit;
}

// Set standard iCalendar headers
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="oncall_schedule_' . $user_id . '.ics"');

// Set start and end times for the feed (past 60 days to 365 days in future)
$start_str = date('Y-m-d H:i:s', time() - (60 * 24 * 3600));
$end_str = date('Y-m-d H:i:s', time() + (365 * 24 * 3600));

// Fetch final segments for user
$segments = get_final_schedule_for_user($user_id, $start_str, $end_str);

// Helper function to format unix timestamps to iCal UTC format (for DTSTAMP)
function format_ical_date_utc($timestamp) {
    return gmdate('Ymd\THis\Z', $timestamp);
}

// Helper function to format unix timestamps to local Winnipeg timezone (without Z suffix)
function format_ical_date_local($timestamp) {
    return date('Ymd\THis', $timestamp);
}

// iCalendar document template with local Winnipeg timezone
?>
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//On-Call Schedule Manager//EN
CALSCALE:GREGORIAN
METHOD:PUBLISH
X-WR-CALNAME:On-Call Schedule - <?= htmlspecialchars($user['name'] . ' ' . $user['surname']) ?>

X-WR-TIMEZONE:America/Winnipeg
<?php foreach ($segments as $index => $seg): ?>
BEGIN:VEVENT
UID:oncall_shift_<?= $user_id ?>_<?= $seg['department_id'] ?>_<?= $seg['start'] ?>@oncall_manager
DTSTAMP:<?= format_ical_date_utc(time()) ?>

DTSTART;TZID=America/Winnipeg:<?= format_ical_date_local($seg['start']) ?>

DTEND;TZID=America/Winnipeg:<?= format_ical_date_local($seg['end']) ?>

SUMMARY:On-Call [<?= htmlspecialchars($seg['department_name']) ?>]
DESCRIPTION:On-call coverage duty for the <?= htmlspecialchars($seg['department_name']) ?> group. Type: <?= $seg['is_override'] ? 'Manual Override' : 'Normal Rotation' ?>. Reason/Description: <?= htmlspecialchars($seg['description']) ?>.
END:VEVENT
<?php endforeach; ?>
END:VCALENDAR
