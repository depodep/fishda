<?php
// ============================================================
//  schedule_api.php
//  Handles: schedule CRUD, auto-save on session end,
//           fetch calendar events for FullCalendar
// ============================================================
ini_set('display_errors', 0);
error_reporting(0);
if (ob_get_level()) ob_clean();
header('Content-Type: application/json');

require_once '../database/dbcon.php';
session_start();

function resp($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

if (!isset($_SESSION['sid'])) resp('error', 'Unauthorized.');

$action  = $_POST['action'] ?? $_GET['action'] ?? null;
$user_id = $_SESSION['sid'];
$is_admin = ($_SESSION['permission'] ?? 'user') === 'admin';

switch ($action) {

    // -------------------------------------------------------
    //  CREATE a scheduled batch (prototype owner or admin)
    // -------------------------------------------------------
    case 'create_schedule':
        $title       = htmlspecialchars(trim($_POST['title'] ?? 'Tilapia Batch'));
        $sched_date  = $_POST['sched_date']  ?? null;   // YYYY-MM-DD
        $sched_time  = $_POST['sched_time']  ?? '08:00'; // HH:MM
        $set_temp    = floatval($_POST['set_temp']  ?? 45);
        $set_hum     = floatval($_POST['set_hum']   ?? 25);
        $notes       = htmlspecialchars(trim($_POST['notes'] ?? ''));

        if (!$sched_date) resp('error', 'Schedule date is required.');

        try {
            $stmt = $dbh->prepare(
                "INSERT INTO batch_schedules
                    (user_id, title, sched_date, sched_time, set_temp, set_humidity, notes, status)
                 VALUES
                    (:uid, :title, :date, :time, :temp, :hum, :notes, 'Scheduled')"
            );
            $stmt->execute([
                ':uid'   => $user_id,
                ':title' => $title,
                ':date'  => $sched_date,
                ':time'  => $sched_time,
                ':temp'  => $set_temp,
                ':hum'   => $set_hum,
                ':notes' => $notes,
            ]);
            resp('success', 'Prototype batch scheduled.', ['schedule_id' => $dbh->lastInsertId()]);
        } catch (Exception $e) {
            resp('error', 'Failed to save schedule: ' . $e->getMessage());
        }
        break;

    // -------------------------------------------------------
    //  DELETE a schedule
    // -------------------------------------------------------
    case 'delete_schedule':
        $sched_id = intval($_POST['schedule_id'] ?? 0);
        try {
            $where = $is_admin ? 'WHERE id=:sid' : 'WHERE id=:sid AND user_id=:uid';
            $params = $is_admin ? [':sid' => $sched_id] : [':sid' => $sched_id, ':uid' => $user_id];
            $dbh->prepare("DELETE FROM batch_schedules $where")->execute($params);
            resp('success', 'Prototype schedule deleted.');
        } catch (Exception $e) {
            resp('error', 'Delete failed.');
        }
        break;

    // -------------------------------------------------------
    //  GET CALENDAR EVENTS  (FullCalendar format)
    //  Returns: scheduled batches + completed sessions
    // -------------------------------------------------------
    case 'get_calendar_events':
        try {
            $events = [];

            // --- Schedules ---
            $where  = $is_admin ? '' : 'WHERE bs.user_id = :uid';
            $params = $is_admin ? [] : [':uid' => $user_id];
            $stmt = $dbh->prepare(
                "SELECT bs.id, bs.title, bs.sched_date, bs.sched_time,
                        bs.set_temp, bs.set_humidity, bs.notes, bs.status,
                        u.username
                 FROM batch_schedules bs
                 JOIN tblusers u ON u.id = bs.user_id
                 $where
                 ORDER BY bs.sched_date ASC"
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $colorMap = ['Scheduled' => '#3b82f6', 'Running' => '#f59e0b', 'Done' => '#10b981', 'Cancelled' => '#ef4444'];
                $events[] = [
                    'id'              => 'sched_' . $row['id'],
                    'title'           => '📅 ' . $row['title'],
                    'start'           => $row['sched_date'] . 'T' . $row['sched_time'],
                    'backgroundColor' => $colorMap[$row['status']] ?? '#3b82f6',
                    'borderColor'     => $colorMap[$row['status']] ?? '#3b82f6',
                    'textColor'       => '#ffffff',
                    'extendedProps'   => [
                        'type'         => 'schedule',
                        'schedule_id'  => $row['id'],
                        'username'     => $row['username'],
                        'set_temp'     => $row['set_temp'],
                        'set_humidity' => $row['set_humidity'],
                        'notes'        => $row['notes'],
                        'status'       => $row['status'],
                    ],
                ];
            }

            // --- Completed / Interrupted Sessions ---
            $whereS  = $is_admin ? "WHERE ds.status IN ('Completed','Interrupted')" : "WHERE ds.user_id=:uid AND ds.status IN ('Completed','Interrupted')";
            $paramsS = $is_admin ? [] : [':uid' => $user_id];
            $stmtS = $dbh->prepare(
                "SELECT ds.session_id, ds.start_time, ds.end_time,
                        ds.set_temp, ds.set_humidity, ds.status,
                        u.username
                 FROM drying_sessions ds
                 JOIN tblusers u ON u.id = ds.user_id
                 $whereS
                 ORDER BY ds.start_time DESC
                 LIMIT 200"
            );
            $stmtS->execute($paramsS);
            foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $color = $row['status'] === 'Completed' ? '#10b981' : '#f97316';
                $events[] = [
                    'id'              => 'sess_' . $row['session_id'],
                    'title'           => ($row['status'] === 'Completed' ? '✅ ' : '⚠️ ') . $row['username'],
                    'start'           => $row['start_time'],
                    'end'             => $row['end_time'],
                    'backgroundColor' => $color,
                    'borderColor'     => $color,
                    'textColor'       => '#ffffff',
                    'extendedProps'   => [
                        'type'         => 'session',
                        'session_id'   => $row['session_id'],
                        'username'     => $row['username'],
                        'set_temp'     => $row['set_temp'],
                        'set_humidity' => $row['set_humidity'],
                        'status'       => $row['status'],
                        'end_time'     => $row['end_time'],
                    ],
                ];
            }

            resp('success', 'Prototype events fetched.', $events);
        } catch (Exception $e) {
            resp('error', 'Failed to fetch events: ' . $e->getMessage());
        }
        break;

    // -------------------------------------------------------
    //  GET MY SCHEDULES (list view for prototype owner)
    // -------------------------------------------------------
    case 'get_my_schedules':
        try {
            $stmt = $dbh->prepare(
                "SELECT id, title, sched_date, sched_time, set_temp, set_humidity, notes, status
                 FROM batch_schedules WHERE user_id=:uid ORDER BY sched_date DESC"
            );
            $stmt->execute([':uid' => $user_id]);
            resp('success', 'Prototype schedules fetched.', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            resp('error', 'Fetch failed.');
        }
        break;

    // -------------------------------------------------------
    //  ADMIN: GET ALL SCHEDULES
    // -------------------------------------------------------
    case 'get_all_schedules':
        if (!$is_admin) resp('error', 'Admin only.');
        try {
            $stmt = $dbh->query(
                "SELECT bs.id, bs.title, bs.sched_date, bs.sched_time,
                        bs.set_temp, bs.set_humidity, bs.notes, bs.status,
                        u.username
                 FROM batch_schedules bs
                 JOIN tblusers u ON u.id = bs.user_id
                 ORDER BY bs.sched_date DESC"
            );
            resp('success', 'All prototype schedules fetched.', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            resp('error', 'Fetch failed.');
        }
        break;

    // -------------------------------------------------------
    //  AUTO-SAVE: mark schedule as Done when session completes
    // -------------------------------------------------------
    case 'mark_schedule_done':
        $sched_id = intval($_POST['schedule_id'] ?? 0);
        try {
            $stmt = $dbh->prepare(
                "UPDATE batch_schedules SET status='Done' WHERE id=:sid AND user_id=:uid"
            );
            $stmt->execute([':sid' => $sched_id, ':uid' => $user_id]);
            resp('success', 'Prototype schedule marked as done.');
        } catch (Exception $e) {
            resp('error', 'Update failed.');
        }
        break;

    default:
        resp('error', 'Invalid action.');
}