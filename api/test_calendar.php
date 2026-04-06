<?php
// Fixed Calendar API - Show ALL drying records for admin
require_once '../database/dbcon.php';

header('Content-Type: application/json');
session_start();

try {
    $events = [];
    $is_admin = ($_SESSION['permission'] ?? 'user') === 'admin';
    
    echo json_encode([
        'status' => 'debug',
        'user_info' => [
            'user_id' => $_SESSION['user_id'] ?? 'not set',
            'permission' => $_SESSION['permission'] ?? 'not set',
            'is_admin' => $is_admin
        ]
    ]);

    // --- GET ALL SCHEDULES ---
    $stmt = $dbh->prepare(
        "SELECT bs.id, bs.title, bs.sched_date, bs.sched_time,
                bs.set_temp, bs.set_humidity, bs.notes, bs.status,
                u.username
         FROM batch_schedules bs
         JOIN tblusers u ON u.id = bs.user_id
         ORDER BY bs.sched_date ASC"
    );
    $stmt->execute();
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

    // --- GET ALL DRYING SESSIONS ---
    $stmtS = $dbh->prepare(
        "SELECT ds.session_id, ds.start_time, ds.end_time,
                ds.set_temp, ds.set_humidity, ds.status,
                u.username, u.permission,
                CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info
         FROM drying_sessions ds
         JOIN tblusers u ON u.id = ds.user_id
         LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
         ORDER BY ds.start_time DESC
         LIMIT 100"
    );
    $stmtS->execute();
    
    foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Show ALL sessions regardless of status for admin
        $color = '#10b981'; // Default green
        $icon = '🔄'; // Default icon
        
        switch($row['status']) {
            case 'Completed': $color = '#10b981'; $icon = '✅'; break;
            case 'Interrupted': $color = '#f97316'; $icon = '⚠️'; break;
            case 'Running': $color = '#3b82f6'; $icon = '🔄'; break;
            default: $color = '#6b7280'; $icon = '📊'; break;
        }
        
        $title = "$icon {$row['device_info']} ({$row['status']})";
        
        $events[] = [
            'id'              => 'sess_' . $row['session_id'],
            'title'           => $title,
            'start'           => $row['start_time'],
            'end'             => $row['end_time'] ?: $row['start_time'],
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'textColor'       => '#ffffff',
            'extendedProps'   => [
                'type'         => 'session',
                'session_id'   => $row['session_id'],
                'username'     => $row['username'],
                'device_info'  => $row['device_info'],
                'set_temp'     => $row['set_temp'],
                'set_humidity' => $row['set_humidity'],
                'status'       => $row['status'],
            ],
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $events,
        'count' => count($events)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>