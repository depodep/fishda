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
date_default_timezone_set('Asia/Manila');

require_once '../database/dbcon.php';
session_start();

function resp($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

function ensureSchedulePrototypeColumn($dbh) {
    try {
        $dbh->exec("ALTER TABLE batch_schedules ADD COLUMN IF NOT EXISTS proto_id INT NULL AFTER user_id");
    } catch (Exception $e) {}
    try {
        $dbh->exec("CREATE INDEX IF NOT EXISTS idx_batch_schedules_proto_id ON batch_schedules (proto_id)");
    } catch (Exception $e) {}
}

function parseScheduleDurationHours($raw) {
    $value = trim((string)$raw);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
        [$hours, $minutes] = array_map('intval', explode(':', $value, 2));
        if ($minutes < 0 || $minutes > 59) {
            return null;
        }
        return $hours + ($minutes / 60);
    }

    $parsed = floatval($value);
    return $parsed > 0 ? $parsed : null;
}

function isScheduleInPast($schedDate, $schedTime) {
    $scheduledTs = strtotime(trim((string)$schedDate) . ' ' . trim((string)$schedTime));
    if ($scheduledTs === false) {
        return true;
    }
    return $scheduledTs < time();
}

function findScheduleConflict($dbh, $protoId, $userId, $schedDate, $schedTime, $durationHours, $excludeId = null) {
    $minutes = max(1, (int)round(((float)$durationHours) * 60));
    $sql = "SELECT id, title, sched_date, sched_time, COALESCE(duration_hours, 2.0) AS duration_hours
            FROM batch_schedules
            WHERE status IN ('Scheduled','Running')
              AND ((:pid > 0 AND proto_id = :pid_match) OR (:pid <= 0 AND user_id = :uid))
              AND (:exclude_id IS NULL OR id <> :exclude_id)
              AND TIMESTAMP(:new_date, :new_time) < DATE_ADD(TIMESTAMP(sched_date, sched_time), INTERVAL ROUND(COALESCE(duration_hours, 2.0) * 60) MINUTE)
              AND TIMESTAMP(sched_date, sched_time) < DATE_ADD(TIMESTAMP(:new_date, :new_time), INTERVAL :new_minutes MINUTE)
            ORDER BY sched_date ASC, sched_time ASC
            LIMIT 1";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':pid' => (int)$protoId,
        ':pid_match' => (int)$protoId,
        ':uid' => (int)$userId,
        ':exclude_id' => $excludeId,
        ':new_date' => $schedDate,
        ':new_time' => $schedTime,
        ':new_minutes' => $minutes,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Support both user-based and prototype-based auth
$user_id = $_SESSION['user_id'] ?? null;
$proto_id = $_SESSION['proto_id'] ?? null;

// If no user_id but we have proto_id, get or create a default user for scheduling
if (!$user_id && $proto_id) {
    try {
        // Use fishda_bot or first admin as fallback user for prototype schedules
        $uid_row = $dbh->query("SELECT id FROM tblusers WHERE username='fishda_bot' AND status=1 LIMIT 1")
                       ->fetch(PDO::FETCH_ASSOC)
                    ?: $dbh->query("SELECT id FROM tblusers WHERE permission='admin' AND status=1 ORDER BY id ASC LIMIT 1")
                       ->fetch(PDO::FETCH_ASSOC)
                    ?: $dbh->query("SELECT id FROM tblusers WHERE status=1 ORDER BY id ASC LIMIT 1")
                       ->fetch(PDO::FETCH_ASSOC);
        if ($uid_row) {
            $user_id = (int)$uid_row['id'];
        }
    } catch (Exception $e) {}
}

if (!$user_id && !$proto_id) resp('error', 'Unauthorized.');

$action  = $_POST['action'] ?? $_GET['action'] ?? null;
$is_admin = ($_SESSION['permission'] ?? 'user') === 'admin';

switch ($action) {

    // -------------------------------------------------------
    //  CREATE a scheduled batch (prototype owner or admin)
    // -------------------------------------------------------
    case 'create_schedule':
        ensureSchedulePrototypeColumn($dbh);
        $title       = htmlspecialchars(trim($_POST['title'] ?? 'Tilapia Batch'));
        $sched_date  = $_POST['sched_date']  ?? null;   // YYYY-MM-DD
        $sched_time  = $_POST['sched_time']  ?? '08:00'; // HH:MM
        $set_temp    = floatval($_POST['set_temp']  ?? 45);
        $set_hum     = floatval($_POST['set_hum']   ?? 25);
        $fish_count  = intval($_POST['fish_count'] ?? 0);
        if ($fish_count < 1) resp('error', 'Fish count must be at least 1.');
        $duration    = parseScheduleDurationHours($_POST['duration_hours'] ?? 2.0); // Default 2 hours
        $notes       = htmlspecialchars(trim($_POST['notes'] ?? ''));
        $sched_proto_id = intval($_POST['proto_id'] ?? $_GET['proto_id'] ?? $proto_id ?? 0);

        if (!$sched_date) resp('error', 'Schedule date is required.');
        if (!$duration) resp('error', 'Duration is required.');
        if (isScheduleInPast($sched_date, $sched_time)) {
            resp('error', 'Schedule date and time cannot be in the past.');
        }

        try {
            // Ensure duration_hours column exists (migration)
            try {
                $dbh->exec("ALTER TABLE batch_schedules ADD COLUMN IF NOT EXISTS duration_hours DECIMAL(4,1) DEFAULT 2.0 AFTER set_humidity");
            } catch (Exception $e) { /* column may already exist */ }

            $conflict = findScheduleConflict($dbh, $sched_proto_id, (int)$user_id, $sched_date, $sched_time, $duration, null);
            if ($conflict) {
                resp('error', 'Schedule conflict with "' . $conflict['title'] . '" at ' . $conflict['sched_date'] . ' ' . substr((string)$conflict['sched_time'], 0, 5) . '.');
            }
            
            $stmt = $dbh->prepare(
                "INSERT INTO batch_schedules
                    (user_id, proto_id, title, sched_date, sched_time, set_temp, set_humidity, duration_hours, fish_count, notes, status)
                 VALUES
                    (:uid, :pid, :title, :date, :time, :temp, :hum, :duration, :fc, :notes, 'Scheduled')"
            );
            $stmt->execute([
                ':uid'      => $user_id,
                ':pid'      => $sched_proto_id > 0 ? $sched_proto_id : null,
                ':title'    => $title,
                ':date'     => $sched_date,
                ':time'     => $sched_time,
                ':temp'     => $set_temp,
                ':hum'      => $set_hum,
                ':duration' => $duration,
                ':fc'       => $fish_count,
                ':notes'    => $notes,
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
            $req_proto_id = intval($_POST['proto_id'] ?? $_GET['proto_id'] ?? $_SESSION['proto_id'] ?? $proto_id ?? 0);
            $where = $is_admin ? 'WHERE id=:sid' : 'WHERE id=:sid AND (proto_id=:pid OR user_id=:uid OR proto_id IS NULL)';
            $params = $is_admin
                ? [':sid' => $sched_id]
                : [':sid' => $sched_id, ':uid' => $user_id, ':pid' => $req_proto_id];
            $stmt = $dbh->prepare("DELETE FROM batch_schedules $where");
            $stmt->execute($params);
            if ($stmt->rowCount() < 1) {
                resp('error', 'Schedule not found or access denied.');
            }
            resp('success', 'Prototype schedule removed.');
        } catch (Exception $e) {
            resp('error', 'Delete failed.');
        }
        break;

    // -------------------------------------------------------
    //  UPDATE a schedule
    // -------------------------------------------------------
    case 'update_schedule':
        ensureSchedulePrototypeColumn($dbh);
        $sched_id    = intval($_POST['schedule_id'] ?? 0);
        $title       = htmlspecialchars(trim($_POST['title'] ?? 'Tilapia Batch'));
        $sched_date  = $_POST['sched_date'] ?? null;
        $sched_time  = $_POST['sched_time'] ?? '08:00';
        $set_temp    = floatval($_POST['set_temp'] ?? 45);
        $set_hum     = floatval($_POST['set_hum'] ?? 25);
        $fish_count  = intval($_POST['fish_count'] ?? 0);
        if ($fish_count < 1) resp('error', 'Fish count must be at least 1.');
        $duration    = parseScheduleDurationHours($_POST['duration_hours'] ?? 2.0);
        $notes       = htmlspecialchars(trim($_POST['notes'] ?? ''));
        $sched_proto_id = intval($_POST['proto_id'] ?? $_GET['proto_id'] ?? $_SESSION['proto_id'] ?? $proto_id ?? 0);

        if ($sched_id <= 0 || !$sched_date) resp('error', 'Invalid schedule payload.');
        if (!$duration) resp('error', 'Duration is required.');
        if (isScheduleInPast($sched_date, $sched_time)) {
            resp('error', 'Schedule date and time cannot be in the past.');
        }

        try {
            try {
                $dbh->exec("ALTER TABLE batch_schedules ADD COLUMN IF NOT EXISTS duration_hours DECIMAL(4,1) DEFAULT 2.0 AFTER set_humidity");
            } catch (Exception $e) {}

            $conflict = findScheduleConflict($dbh, $sched_proto_id, (int)$user_id, $sched_date, $sched_time, $duration, $sched_id);
            if ($conflict) {
                resp('error', 'Schedule conflict with "' . $conflict['title'] . '" at ' . $conflict['sched_date'] . ' ' . substr((string)$conflict['sched_time'], 0, 5) . '.');
            }

            $where = $is_admin ? 'WHERE id=:sid' : 'WHERE id=:sid AND (user_id=:uid OR proto_id=:pid OR proto_id IS NULL)';
            $sql = "UPDATE batch_schedules
                    SET proto_id=:pid2, title=:title, sched_date=:date, sched_time=:time,
                        set_temp=:temp, set_humidity=:hum, duration_hours=:duration, fish_count=:fc, notes=:notes,
                        status='Scheduled'
                    $where";
            $stmt = $dbh->prepare($sql);
            $params = [
                ':sid'      => $sched_id,
                ':pid2'     => $sched_proto_id > 0 ? $sched_proto_id : null,
                ':title'    => $title,
                ':date'     => $sched_date,
                ':time'     => $sched_time,
                ':temp'     => $set_temp,
                ':hum'      => $set_hum,
                ':duration' => $duration,
                ':fc'       => $fish_count,
                ':notes'    => $notes,
            ];
            if (!$is_admin) {
                $params[':uid'] = $user_id;
                $params[':pid'] = intval($_SESSION['proto_id'] ?? $proto_id ?? 0);
            }
            $stmt->execute($params);
            if ($stmt->rowCount() < 1) {
                $checkSql = $is_admin
                    ? "SELECT id FROM batch_schedules WHERE id=:sid LIMIT 1"
                    : "SELECT id FROM batch_schedules WHERE id=:sid AND (user_id=:uid OR proto_id=:pid OR proto_id IS NULL) LIMIT 1";
                $checkStmt = $dbh->prepare($checkSql);
                $checkParams = [':sid' => $sched_id];
                if (!$is_admin) {
                    $checkParams[':uid'] = $user_id;
                    $checkParams[':pid'] = intval($_SESSION['proto_id'] ?? $proto_id ?? 0);
                }
                $checkStmt->execute($checkParams);
                if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
                    resp('error', 'Schedule not found or access denied.');
                }
            }
            resp('success', 'Prototype schedule updated.');
        } catch (Exception $e) {
            resp('error', 'Update failed: ' . $e->getMessage());
        }
        break;

    // -------------------------------------------------------
    //  GET CALENDAR EVENTS  (FullCalendar format)
    //  Returns: scheduled batches + completed sessions
    // -------------------------------------------------------
    case 'get_calendar_events':
        try {
            ensureSchedulePrototypeColumn($dbh);
            $events = [];

            // Debug: Force session start and admin mode
            session_start();
            $force_admin = true; // FORCE SHOW ALL FOR DEBUGGING
            $is_admin = $force_admin || (($_SESSION['permission'] ?? 'user') === 'admin');
            
            // --- Schedules ---
            $stmt = $dbh->prepare(
                "SELECT bs.id, bs.title, bs.sched_date, bs.sched_time,
                        bs.set_temp, bs.set_humidity, bs.notes, bs.status,
                        COALESCE(CONCAT(p.model_name, ' (', p.given_code, ')'), u.username) AS prototype_label
                 FROM batch_schedules bs
                 JOIN tblusers u ON u.id = bs.user_id
                  LEFT JOIN tbl_prototypes p ON p.id = bs.proto_id
                 ORDER BY bs.sched_date ASC"
            );
            $stmt->execute([]);
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
                        'prototype_label' => $row['prototype_label'],
                        'set_temp'     => $row['set_temp'],
                        'set_humidity' => $row['set_humidity'],
                        'notes'        => $row['notes'],
                        'status'       => $row['status'],
                    ],
                ];
            }

            // --- Completed/Interrupted Sessions (SHOW ALL DRYING RECORDS) ---
            $whereClause = "WHERE ds.status IN ('Completed','Interrupted')";
            $params = [];
            
            // TEMPORARY: Remove all filtering to show ALL drying records on calendar
            // This ensures all completed sessions appear on calendar for all users
            
            $stmtS = $dbh->prepare(
                "SELECT ds.session_id, ds.start_time, ds.end_time,
                        ds.set_temp, ds.set_humidity, ds.status,
                        u.username, u.permission,
                        CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info,
                        COALESCE(p.model_name, 'Fishda') AS model_name,
                        COALESCE(p.given_code, 'FD2026') AS unit_code
                 FROM drying_sessions ds
                 JOIN tblusers u ON u.id = ds.user_id
                 LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
                 $whereClause
                 ORDER BY ds.start_time DESC
                 LIMIT 200"
            );
            $stmtS->execute($params);
            foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $color = $row['status'] === 'Completed' ? '#10b981' : '#f97316';
                $icon = $row['status'] === 'Completed' ? '✅' : '⚠️';
                $title = "$icon {$row['device_info']}";
                
                $events[] = [
                    'id'              => 'sess_' . $row['session_id'],
                    'title'           => $title,
                    'start'           => $row['start_time'],
                    'end'             => $row['end_time'] ?: $row['start_time'], // Use start time if no end time
                    'backgroundColor' => $color,
                    'borderColor'     => $color,
                    'textColor'       => '#ffffff',
                    'extendedProps'   => [
                        'type'         => 'session',
                        'session_id'   => $row['session_id'],
                        'username'     => $row['username'],
                        'device_info'  => $row['device_info'],
                        'model_name'   => $row['model_name'],
                        'unit_code'    => $row['unit_code'],
                        'set_temp'     => $row['set_temp'],
                        'set_humidity' => $row['set_humidity'],
                        'status'       => $row['status'],
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
            ensureSchedulePrototypeColumn($dbh);
            // Prototype-based filtering: Admin sees all, Unit users see only their prototype's schedules
            $whereClause = "WHERE bs.status IN ('Scheduled', 'Running')";
            $params = [];
            
            if (($_SESSION['permission'] ?? 'user') !== 'admin') {
                // Unit users only see schedules for their prototype
                $proto_id = intval($_SESSION['proto_id'] ?? $_GET['proto_id'] ?? $_POST['proto_id'] ?? 0);
                if ($proto_id > 0) {
                    $whereClause .= " AND (bs.proto_id = :proto_id OR bs.proto_id IS NULL)";
                    $params[':proto_id'] = $proto_id;
                } else {
                    // If no proto_id, show no schedules
                    $whereClause .= " AND 1=0";
                }
            }

            // Only show schedules whose end time is in the future, or are currently running
            $whereClause .= " AND (bs.status = 'Running' OR TIMESTAMP(bs.sched_date, bs.sched_time) >= NOW() OR DATE_ADD(TIMESTAMP(bs.sched_date, bs.sched_time), INTERVAL ROUND(COALESCE(bs.duration_hours,2.0)*60) MINUTE) >= NOW())";

            $stmt = $dbh->prepare(
                "SELECT bs.id, bs.title, bs.sched_date, bs.sched_time, 
                        bs.set_temp, bs.set_humidity, bs.fish_count, bs.duration_hours, bs.notes, bs.status,
                        CASE WHEN ds.session_id IS NOT NULL THEN 1 ELSE 0 END AS has_running_session,
                        CASE 
                            WHEN bs.status IN ('Done','Cancelled') THEN bs.status
                            WHEN ds.session_id IS NOT NULL THEN 'Running'
                            ELSE 'Scheduled'
                        END as display_status
                 FROM batch_schedules bs
                 LEFT JOIN drying_sessions ds ON ds.schedule_id = bs.id AND ds.status = 'Running'
                 $whereClause
                 ORDER BY bs.sched_date ASC, bs.sched_time ASC"
            );
            $stmt->execute($params);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Update the status field to reflect the display_status  
            foreach ($schedules as &$schedule) {
                $schedule['status'] = $schedule['display_status'];
                unset($schedule['display_status']);
                unset($schedule['has_running_session']);
            }
            
            resp('success', 'Upcoming prototype schedules fetched.', $schedules);
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
            ensureSchedulePrototypeColumn($dbh);
            $stmt = $dbh->query(
                "SELECT bs.id, bs.title, bs.sched_date, bs.sched_time,
                        bs.set_temp, bs.set_humidity, bs.duration_hours, bs.notes, bs.status,
                        CASE WHEN ds.session_id IS NOT NULL THEN 1 ELSE 0 END AS has_running_session,
                        CASE
                            WHEN bs.status IN ('Done','Cancelled') THEN bs.status
                            WHEN ds.session_id IS NOT NULL THEN 'Running'
                            ELSE 'Scheduled'
                        END AS display_status,
                        COALESCE(CONCAT(p.model_name, ' (', p.given_code, ')'), CONCAT('Prototype #', bs.proto_id), u.username, 'Unassigned Prototype') AS prototype_label
                 FROM batch_schedules bs
                 LEFT JOIN drying_sessions ds ON ds.schedule_id = bs.id AND ds.status = 'Running'
                 LEFT JOIN tblusers u ON u.id = bs.user_id
                 LEFT JOIN tbl_prototypes p ON p.id = bs.proto_id
                 ORDER BY bs.sched_date ASC, bs.sched_time ASC"
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