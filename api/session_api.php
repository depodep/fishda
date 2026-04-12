<?php
// ============================================================
//  session_api.php — UPDATED
//  Changes:
//   1. get_live_data: returns ctrl_status + cooldown_remaining
//      so dashboard can show COOLDOWN phase properly
//   2. log_reading: fan = heat source (ON during Heating),
//      'Drying' phase added (fan ON at target temp)
// ============================================================
ini_set('display_errors', 0);
error_reporting(0);
if (ob_get_level()) ob_clean();
header('Content-Type: application/json');

require_once '../database/dbcon.php';

// Include dynamic scheduler functions
require_once 'dynamic_scheduler.php';
session_start();

function sendResponse($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$esp_actions = ['log_reading', 'fetch_controls', 'poll_session_status'];

// Shared secret between ESP firmware and server.
// Change this value in both session_api.php and arduinocoe.cpp.
const ESP_ACCESS_CODE = 'APS-ESP-2026';
const ESP_DEVICE_UNIQUE_CODE = 'ESP8266-UNIT-001';

function validateEspRequest($dbh) {
    $access_code = trim($_POST['access_code'] ?? $_GET['access_code'] ?? '');
    $model_unit = trim(
        $_POST['model_unit']
        ?? $_GET['model_unit']
        ?? $_POST['proto_model']
        ?? $_GET['proto_model']
        ?? ''
    );
    $model_code  = trim(
        $_POST['model_code']
        ?? $_GET['model_code']
        ?? $_POST['model_unit_code']
        ?? $_GET['model_unit_code']
        ?? $_POST['proto_code']
        ?? $_GET['proto_code']
        ?? ''
    );
    $device_unique_code = trim(
        $_POST['device_unique_code']
        ?? $_GET['device_unique_code']
        ?? $_POST['unique_code']
        ?? $_GET['unique_code']
        ?? ''
    );

    if ($access_code === '' || $model_unit === '' || $model_code === '' || $device_unique_code === '') {
        sendResponse('error', 'ESP identity is incomplete.');
    }

    if (!hash_equals(ESP_ACCESS_CODE, $access_code)) {
        sendResponse('error', 'ESP access code mismatch.');
    }

    if (!hash_equals(ESP_DEVICE_UNIQUE_CODE, $device_unique_code)) {
        sendResponse('error', 'ESP unique code mismatch.');
    }

    $stmt = $dbh->prepare(
        "SELECT id, model_name, given_code, status
         FROM tbl_prototypes
         WHERE model_name=:m AND given_code=:c
         LIMIT 1"
    );
    $stmt->execute([':m' => $model_unit, ':c' => $model_code]);
    $proto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proto) {
        sendResponse('error', 'Prototype identity mismatch.');
    }

    if ((string)$proto['status'] !== '1') {
        sendResponse('error', 'Prototype access is disabled by administrator.');
    }

    $dbh->prepare("UPDATE tbl_prototypes SET updated_at=NOW() WHERE id=:id")
        ->execute([':id' => $proto['id']]);

    return $proto;
}

function resolveSessionUserIdFromPrototype($dbh, $proto_id) {
    $session_user_id = intval($_SESSION['user_id'] ?? 0);
    if ($session_user_id > 0) {
        return $session_user_id;
    }

    // When no session user, use system operator or first active user
    // proto_id is for device identification, not user lookup
    $row = $dbh->query("SELECT id FROM tblusers WHERE username='system_operator' AND status=1 LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $row = $dbh->query("SELECT id FROM tblusers WHERE status=1 ORDER BY id ASC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
    }

    if ($row && isset($row['id'])) {
        $_SESSION['user_id'] = (int)$row['id'];
        return (int)$row['id'];
    }

    return 0;
}

$proto_id = intval($_SESSION['proto_id'] ?? $_GET['proto_id'] ?? $_POST['proto_id'] ?? 0);
$session_user_id = intval($_SESSION['user_id'] ?? 0);
$is_admin = isset($_SESSION['user_id']) && (($_SESSION['permission'] ?? 'user') === 'admin');

if (!in_array($action, $esp_actions) && !isset($_SESSION['user_id']) && $proto_id <= 0 && $session_user_id <= 0) {
    sendResponse('error', 'Unauthorized. Please login.');
}

if (in_array($action, $esp_actions)) {
    validateEspRequest($dbh);
}

$user_id = $is_admin
    ? intval($_SESSION['user_id'] ?? 0)
    : resolveSessionUserIdFromPrototype($dbh, $proto_id);

switch ($action) {

    // ============================================================
    //  START SESSION
    // ============================================================
    case 'start_session':
        $set_temp = max(30, min(70, floatval($_POST['set_temp'] ?? 45)));
        $set_hum  = max(10, min(80, floatval($_POST['set_humidity'] ?? 25)));
        $duration_hours = floatval($_POST['duration_hours'] ?? 0);
        if ($duration_hours > 0) {
            $duration_hours = max(0.5, min(24, $duration_hours));
        } else {
            $duration_hours = 0;
        }
        $schedule_id = intval($_POST['schedule_id'] ?? 0) ?: null; // Track if session is from a schedule
        $session_notes = $duration_hours > 0 ? json_encode(['manual_duration_hours' => $duration_hours]) : null;

        // Get Fishda Bot user_id for automated sessions
        $uid_row = $dbh->query("SELECT id FROM tblusers WHERE username='fishda_bot' AND status=1 LIMIT 1")
                       ->fetch(PDO::FETCH_ASSOC)
                    ?: $dbh->query("SELECT id FROM tblusers WHERE permission='admin' AND status=1 ORDER BY id ASC LIMIT 1")
                       ->fetch(PDO::FETCH_ASSOC)
                    ?: $dbh->query("SELECT id FROM tblusers WHERE status=1 ORDER BY id ASC LIMIT 1")
                       ->fetch(PDO::FETCH_ASSOC);

        if (!$uid_row) {
            sendResponse('error', 'No user account available.');
        }
        $default_user_id = (int)$uid_row['id'];

        // Check if prototype is online before starting session
        try {
            $protoCheck = $dbh->prepare(
                "SELECT status, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS seconds_since_seen
                 FROM tbl_prototypes WHERE id = :pid LIMIT 1"
            );
            $protoCheck->execute([':pid' => $proto_id]);
            $protoData = $protoCheck->fetch(PDO::FETCH_ASSOC);

            if ($protoData) {
                $isActive = ((string)$protoData['status'] === '1');
                $secondsSince = intval($protoData['seconds_since_seen'] ?? 999999);
                $isOnline = $isActive && $secondsSince <= 30;

                if (!$isOnline) {
                    sendResponse('error', 'Cannot start session. The prototype device is currently offline. Please check the device connection.');
                }
            }
        } catch (Exception $e) {
            // If check fails, log but allow (fallback)
        }

        try {
            $dbh->prepare("UPDATE drying_sessions SET status='Interrupted', end_time=NOW() WHERE status='Running'")->execute([]);
            
            // Get prototype ID for the session
            $proto_row = $dbh->query("SELECT id FROM tbl_prototypes WHERE status = 1 LIMIT 1")->fetch();
            $proto_id = $proto_row ? $proto_row['id'] : 1;
            
            $stmt = $dbh->prepare("INSERT INTO drying_sessions (user_id,proto_id,schedule_id,set_temp,set_humidity,status,start_time,notes) VALUES(:uid,:pid,:sid,:t,:h,'Running',NOW(),:notes)");
            $stmt->execute([':uid'=>$default_user_id,':pid'=>$proto_id,':sid'=>$schedule_id,':t'=>$set_temp,':h'=>$set_hum,':notes'=>$session_notes]);
            $session_id = $dbh->lastInsertId();
            // Sync drying_controls — clear any leftover cooldown
            $dbh->prepare("UPDATE drying_controls SET target_temp=:t,target_humidity=:h,status='RUNNING',start_time=NOW(),cooldown_until=NULL WHERE id=1")
                ->execute([':t'=>$set_temp,':h'=>$set_hum]);
            sendResponse('success','Prototype drying session started.',[
                'session_id'   => (int)$session_id,
                'set_temp'     => $set_temp,
                'set_humidity' => $set_hum,
                'duration_hours' => $duration_hours > 0 ? $duration_hours : null,
                'status'       => 'Running'
            ]);
        } catch(Exception $e){ sendResponse('error','Failed to start: '.$e->getMessage()); }
        break;

    // ============================================================
    //  STOP SESSION
    // ============================================================
    case 'stop_session':
        $session_id  = intval($_POST['session_id'] ?? 0);
        $save_reason = $_POST['save_reason'] ?? 'Manual';
        try {
            $where  = $is_admin ? "WHERE session_id=:sid AND status='Running'" : "WHERE session_id=:sid AND user_id=:uid AND status='Running'";
            $params = $is_admin ? [':sid'=>$session_id] : [':sid'=>$session_id,':uid'=>$user_id];
            $dbh->prepare("UPDATE drying_sessions SET status='Completed',end_time=NOW() $where")->execute($params);
            $dbh->query("UPDATE drying_controls SET status='STOPPED',start_time=NULL,cooldown_until=NULL WHERE id=1");

            // Auto-save drying record
            $sess = $dbh->prepare(
                "SELECT ds.user_id, u.username, ds.set_temp, ds.set_humidity,
                    TIMEDIFF(COALESCE(ds.end_time,NOW()),ds.start_time) AS duration,
                    ROUND(AVG(dl.recorded_temp),2)     AS avg_temp,
                    ROUND(AVG(dl.recorded_humidity),2) AS avg_hum,
                    COUNT(dl.log_id)                   AS total_logs
                 FROM drying_sessions ds
                 JOIN tblusers u ON u.id=ds.user_id
                 LEFT JOIN drying_logs dl ON dl.session_id=ds.session_id
                 WHERE ds.session_id=:sid
                 GROUP BY ds.session_id"
            );
            $sess->execute([':sid'=>$session_id]);
            $s = $sess->fetch(PDO::FETCH_ASSOC);

            if ($s) {
                $rec_status = ($save_reason === 'TargetReached') ? 'Completed & Dried' : 'Completed';
                $dbh->prepare(
                    "INSERT INTO drying_records (batch_id, duration, energy, temp_avg, hum_avg, status, user_id, session_id)
                     VALUES (:b, :d, 0, :t, :h, :st, :uid, :sid)"
                )->execute([
                    ':b'   => $s['username'],
                    ':d'   => $s['duration'] ?? '00:00:00',
                    ':t'   => $s['avg_temp'] ?? 0,
                    ':h'   => $s['avg_hum']  ?? 0,
                    ':st'  => $rec_status,
                    ':uid' => $s['user_id'],
                    ':sid' => $session_id,
                ]);
            }
            sendResponse('success','Prototype session completed and saved.',['save_reason'=>$save_reason]);
        } catch(Exception $e){ sendResponse('error','Stop failed: '.$e->getMessage()); }
        break;

    // ============================================================
    //  LOG READING — from ESP8266
    //  UPDATED: FAN = heat source (ON during Heating + Drying)
    // ============================================================
    case 'log_reading':
        // Validate scheduled sessions before processing log
        // validateScheduledSessions($dbh); // Disabled: schedule now handled in sensor_api.php

        $session_id        = intval($_POST['session_id'] ?? 0);
        $recorded_temp     = floatval($_POST['temp'] ?? 0);
        $recorded_humidity = floatval($_POST['humidity'] ?? 0);

        // If session_id=0, this is idle state - just cache the sensor data
        if ($session_id <= 0) {
            // Log idle reading to drying_logs for live display
            try {
                $dbh->prepare(
                    "INSERT INTO drying_logs
                     (session_id, recorded_temp, recorded_humidity, heater_state, exhaust_state, fan_state, phase, timestamp)
                     VALUES (0, :temp, :hum, 0, 0, 0, 'Idle', NOW())"
                )->execute([':temp' => $recorded_temp, ':hum' => $recorded_humidity]);
            } catch (Exception $e) { /* non-fatal */ }

            sendResponse('success', 'Sensor data cached (idle state).', [
                'temp'      => $recorded_temp,
                'humidity'  => $recorded_humidity,
                'phase'     => 'Idle',
                'session_id' => 0
            ]);
        }

        $sessStmt = $dbh->prepare("SELECT set_temp,set_humidity FROM drying_sessions WHERE session_id=:sid AND status='Running' LIMIT 1");
        $sessStmt->execute([':sid'=>$session_id]);
        $session = $sessStmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) sendResponse('error','No active session with ID '.$session_id);

        $target_temp = floatval($session['set_temp']);
        $target_hum  = floatval($session['set_humidity']);

        // Read global cooldown state maintained by sensor_api.php
        $ctrl_status        = 'RUNNING';
        $cooldown_until     = null;
        $cooldown_remaining = 0;
        try {
            $ctrl = $dbh->query("SELECT status, cooldown_until FROM drying_controls WHERE id=1")
                        ->fetch(PDO::FETCH_ASSOC);
            if ($ctrl) {
                $ctrl_status    = $ctrl['status']         ?? 'RUNNING';
                $cooldown_until = $ctrl['cooldown_until'] ?? null;
                if ($cooldown_until) {
                    $cd_ts = strtotime($cooldown_until);
                    if ($cd_ts !== false) {
                        $cooldown_remaining = max(0, $cd_ts - time());
                    }
                }
            }
        } catch (Exception $e) {
            // If controls row is missing, fall back to normal behaviour
            $ctrl_status        = 'RUNNING';
            $cooldown_until     = null;
            $cooldown_remaining = 0;
        }

        $OVERHEAT_THRESHOLD  = $target_temp + 2;
        $CRITICAL_THRESHOLD  = $target_temp + 8;
        $AUTOSTOP_THRESHOLD  = $target_temp + 15;
        $UNDERHEAT_THRESHOLD = $target_temp - 1;

        $heater_state  = 0;
        $fan1_state    = 0;
        $fan2_state    = 0;
        $phase         = 'Idle';
        $alert         = null;
        $auto_stopped  = false;
        $fish_ready    = false;
        $command       = 'RUN';

        $skipNormalLogic = false;

        // Enforce 5-minute cooldown and temperature-based resume
        if ($ctrl_status === 'COOLDOWN') {
            // While within the minimum cooldown window OR temperature is still above target,
            // keep everything OFF and do not resume the session.
            if ($cooldown_remaining > 0 || $recorded_temp > $target_temp) {
                $phase          = 'Cooldown';
                $fan1_state     = 0;
                $fan2_state     = 0;
                $command        = 'COOLDOWN';
                $skipNormalLogic = true;
            } else {
                // Cooldown finished AND temperature has dropped back to/below the set point
                // → allow normal control to resume.
                try {
                    $dbh->prepare("UPDATE drying_controls SET status='RUNNING', cooldown_until=NULL WHERE id=1")
                        ->execute();
                } catch (Exception $e) {
                    // Non-fatal — device will still follow temperature logic below
                }
                $ctrl_status        = 'RUNNING';
                $cooldown_until     = null;
                $cooldown_remaining = 0;
            }
        }

        if (!$skipNormalLogic) {
            if ($recorded_temp >= $AUTOSTOP_THRESHOLD) {
                $dbh->prepare("UPDATE drying_sessions SET status='Interrupted',end_time=NOW() WHERE session_id=:sid")
                    ->execute([':sid'=>$session_id]);
                $dbh->query("UPDATE drying_controls SET status='STOPPED',start_time=NULL,cooldown_until=NULL WHERE id=1");
                $phase        = 'Cooldown';
                $fan1_state   = 0;
                $fan2_state   = 0;
                $auto_stopped = true;
                $command      = 'STOP';
                $alert = [
                    'level'  => 'emergency',
                    'title'  => '🚨 EMERGENCY AUTO-STOP',
                    'message'=> "Temp {$recorded_temp}°C — System auto-stopped!",
                    'temp'   => $recorded_temp,
                    'target' => $target_temp,
                ];
            } elseif ($recorded_temp >= $CRITICAL_THRESHOLD) {
                $phase      = 'Cooldown';
                $fan1_state = 0;
                $fan2_state = 0;
                $command    = 'COOLDOWN';
                $alert = [
                    'level'  => 'critical',
                    'title'  => '⚠️ CRITICAL OVERHEAT',
                    'message'=> "Temp {$recorded_temp}°C critical!",
                    'temp'   => $recorded_temp,
                    'target' => $target_temp,
                ];
            } elseif ($recorded_temp >= $OVERHEAT_THRESHOLD) {
                $phase      = 'Cooldown';
                $fan1_state = 0;
                $fan2_state = 0;
                $command    = 'COOLDOWN';
                $alert = [
                    'level'  => 'warning',
                    'title'  => '🌡️ Overheating',
                    'message'=> "Temp {$recorded_temp}°C exceeds target {$target_temp}°C.",
                    'temp'   => $recorded_temp,
                    'target' => $target_temp,
                ];
            } elseif ($recorded_temp <= $UNDERHEAT_THRESHOLD) {
                // Fans ON to heat up
                $fan1_state = 1;
                $fan2_state = 1;
                $phase      = 'Heating';
            } else {
                // At target range — fans still ON for circulation/drying
                $fan1_state = 1;
                $fan2_state = 1;
                $phase      = 'Drying';
            }
        }

        if (!$auto_stopped) {
            $logStmt = $dbh->prepare(
                "INSERT INTO drying_logs (session_id,recorded_temp,recorded_humidity,heater_state,exhaust_state,fan_state,phase,timestamp)
                 VALUES(:sid,:temp,:hum,:heater,:exhaust,:fan,:phase,NOW())"
            );
            $logStmt->execute([
                ':sid'    => $session_id,
                ':temp'   => $recorded_temp,
                ':hum'    => $recorded_humidity,
                ':heater' => 0,
                ':exhaust'=> 0,
                ':fan'    => ($fan1_state || $fan2_state),
                ':phase'  => $phase,
            ]);
        }

        // Fish ready check
        $recentLogs = $dbh->prepare("SELECT recorded_temp, recorded_humidity FROM drying_logs WHERE session_id=:sid ORDER BY timestamp DESC LIMIT 5");
        $recentLogs->execute([':sid'=>$session_id]);
        $recent = $recentLogs->fetchAll(PDO::FETCH_ASSOC);
        if (count($recent) >= 5 && !$auto_stopped) {
            $allReady = true;
            foreach ($recent as $log) {
                $t = floatval($log['recorded_temp']);
                $h = floatval($log['recorded_humidity']);
                if ($t < $target_temp - 2 || $t > $target_temp + 2 || $h > $target_hum + 5) {
                    $allReady = false; break;
                }
            }
            $fish_ready = $allReady;
        }

        sendResponse('success', 'Prototype log recorded.', [
            'phase'               => $phase,
            'fan1'                => $fan1_state,
            'fan2'                => $fan2_state,
            'temp'                => $recorded_temp,
            'humidity'            => $recorded_humidity,
            'auto_stopped'        => $auto_stopped,
            'fish_ready'          => $fish_ready,
            'alert'               => $alert,
            'command'             => $command,
            'cooldown_remaining'  => $cooldown_remaining,
        ]);
        break;

    // ============================================================
    //  POLL SESSION STATUS — for ESP8266 & prototype dashboard
    // ============================================================
    case 'poll_session_status':
        $session_id = intval($_GET['session_id'] ?? 0);
        try {
            if ($session_id > 0) {
                $stmt = $dbh->prepare("SELECT session_id, status, set_temp, set_humidity FROM drying_sessions WHERE session_id=:sid LIMIT 1");
                $stmt->execute([':sid'=>$session_id]);
            } else {
                // For ESP requests (validated by validateEspRequest), always return ANY running session
                // This matches the behavior of get_live_data used by the web UI
                $stmt = $dbh->query("SELECT session_id,status,set_temp,set_humidity FROM drying_sessions WHERE status='Running' ORDER BY start_time DESC LIMIT 1");
            }
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            sendResponse('success', 'Status fetched.', $data ?: []);
        } catch(Exception $e){ sendResponse('error', $e->getMessage()); }
        break;

    // ============================================================
    //  GET LIVE DATA — dashboard polls this every 3 seconds
    //  UPDATED: returns ctrl_status + cooldown_remaining
    // ============================================================
    case 'get_live_data':
        try {
            // For proto_id requests, just find the most recent running session
            // No user_id resolution needed
            if ($proto_id > 0) {
                $session = $dbh->query(
                    "SELECT ds.session_id, ds.set_temp, ds.set_humidity, ds.start_time, ds.user_id,
                            ds.schedule_id, ds.notes,
                            bs.title AS schedule_title, bs.sched_date, bs.sched_time, bs.duration_hours, bs.auto_started
                     FROM drying_sessions ds
                     LEFT JOIN batch_schedules bs ON bs.id = ds.schedule_id
                     WHERE ds.status = 'Running'
                     ORDER BY ds.start_time DESC LIMIT 1"
                )->fetch(PDO::FETCH_ASSOC);
            } else {
                // For user_id requests, use the dynamic scheduler
                $session = getCurrentSessionStatus($dbh, $user_id);
            }

            // Always check device last-seen to detect OFFLINE state
            $liveHeartbeat = $dbh->query(
                "SELECT TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS age_seconds
                 FROM live_sensor_cache WHERE id = 1 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            $ageSeconds   = $liveHeartbeat ? (int)($liveHeartbeat['age_seconds'] ?? 999999) : 999999;
            $deviceOnline = ($ageSeconds >= 0 && $ageSeconds < 30);

            // If there is a running session but device is OFFLINE → auto-stop session
            if ($session && !$deviceOnline) {
                $autoStopSid = (int)$session['session_id'];
                try {
                    $dbh->beginTransaction();

                    // Mark drying session as Interrupted
                    $dbh->prepare(
                        "UPDATE drying_sessions
                         SET status='Interrupted', end_time=NOW(), notes=CONCAT(COALESCE(notes,''),' [AUTO-STOP: device offline]')
                         WHERE session_id=:sid AND status='Running'"
                    )->execute([':sid' => $autoStopSid]);

                    // Reset controls to IDLE
                    $dbh->prepare(
                        "UPDATE drying_controls SET status='IDLE', cooldown_until=NULL WHERE id=1"
                    )->execute();

                    $dbh->commit();
                } catch (Exception $e) {
                    $dbh->rollBack();
                }

                sendResponse('success', 'Session auto-stopped: device offline.', [
                    'recorded_temp'      => null,
                    'recorded_humidity'  => null,
                    'phase'              => 'Idle',
                    'heater_state'       => 0,
                    'fan_state'          => 0,
                    'exhaust_state'      => 0,
                    'session_id'         => null,
                    'set_temp'           => null,
                    'set_humidity'       => null,
                    'start_time'         => null,
                    'ctrl_status'        => 'IDLE',
                    'cooldown_remaining' => 0,
                    'device_online'      => false,
                    'offline_reason'     => 'device_offline_timeout',
                ]);
            }

            // If no active session, get FRESH sensor reading from live_sensor_cache (not old logs)
            if (!$session) {
                // Use database time for comparison to avoid timezone issues
                $liveCache = $dbh->query(
                    "SELECT temperature, humidity, phase, heater1_state, heater2_state, fan1_state, fan2_state, exhaust_state, timestamp,
                            TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS age_seconds
                     FROM live_sensor_cache
                     WHERE id = 1
                     LIMIT 1"
                )->fetch(PDO::FETCH_ASSOC);

                // Check if data is stale (device offline if >30 seconds old)
                $isOnline = false;
                if ($liveCache && isset($liveCache['age_seconds'])) {
                    $age = (int)$liveCache['age_seconds'];
                    // Device is online if data is fresh (within 30 seconds)
                    $isOnline = ($age >= 0 && $age < 30);
                }

                // Always show sensor data if it exists, even if stale (device will be marked offline)
                if ($liveCache && $liveCache['temperature'] !== null) {
                    sendResponse('success', $isOnline ? 'Idle state - showing live sensor data.' : 'Idle state - showing stale sensor data (device may be offline).', [
                        'recorded_temp'      => floatval($liveCache['temperature']),
                        'recorded_humidity'  => floatval($liveCache['humidity']),
                        'phase'              => $liveCache['phase'] ?? 'Idle',
                        'heater_state'       => (int)($liveCache['heater1_state'] ?? 0),
                        'fan_state'          => (int)($liveCache['fan1_state'] ?? 0),
                        'fan1_state'         => (int)($liveCache['fan1_state'] ?? 0),
                        'fan2_state'         => (int)($liveCache['fan2_state'] ?? 0),
                        'exhaust_state'      => (int)($liveCache['exhaust_state'] ?? 0),
                        'session_id'         => null,
                        'set_temp'           => null,
                        'set_humidity'       => null,
                        'start_time'         => null,
                        'ctrl_status'        => 'IDLE',
                        'cooldown_remaining' => 0,
                        'device_online'      => $isOnline,
                        'data_age_seconds'   => $liveCache['age_seconds'] ?? null,
                    ]);
                } else {
                    // No sensor data OR device offline (stale data)
                    sendResponse('success', 'Device offline or no sensor data.', [
                        'recorded_temp'      => null,
                        'recorded_humidity'  => null,
                        'phase'              => 'Idle',
                        'heater_state'       => 0,
                        'fan_state'          => 0,
                        'exhaust_state'      => 0,
                        'session_id'         => null,
                        'set_temp'           => null,
                        'set_humidity'       => null,
                        'start_time'         => null,
                        'ctrl_status'        => 'IDLE',
                        'cooldown_remaining' => 0,
                        'device_online'      => false,
                    ]);
                }
            }

            $sid = $session['session_id'];
            $manualDurationHours = null;
            if (!empty($session['notes'])) {
                $decoded = json_decode((string)$session['notes'], true);
                if (is_array($decoded) && isset($decoded['manual_duration_hours'])) {
                    $manualDurationHours = floatval($decoded['manual_duration_hours']);
                }
            }
            $sessionDurationHours = $session['duration_hours'] ? floatval($session['duration_hours']) : $manualDurationHours;

            // ── Fetch controls row for cooldown state and cycle count ──
            $ctrl_query = "SELECT status AS ctrl_status, cooldown_until";
            
            // Check if cycle_count column exists before querying
            try {
                $dbh->query("SELECT cycle_count FROM drying_controls LIMIT 1");
                $ctrl_query .= ", cycle_count, last_cycle_start";
            } catch (Exception $e) {
                // cycle_count column doesn't exist yet - skip it
            }
            
            $ctrl_query .= " FROM drying_controls WHERE id=1";
            $ctrl = $dbh->query($ctrl_query)->fetch(PDO::FETCH_ASSOC);
            
            $ctrl_status        = $ctrl['ctrl_status']    ?? 'RUNNING';
            $cooldown_until     = $ctrl['cooldown_until'] ?? null;
            $cycle_count        = $ctrl['cycle_count']    ?? 0;
            $last_cycle_start   = $ctrl['last_cycle_start'] ?? null;
            $cooldown_remaining = 0;
            if ($ctrl_status === 'COOLDOWN' && $cooldown_until) {
                $cooldown_remaining = max(0, strtotime($cooldown_until) - time());
            }

            // Latest log entry
            $logStmt = $dbh->prepare(
                "SELECT recorded_temp, recorded_humidity,
                        phase, heater_state, exhaust_state, fan_state,
                        timestamp
                 FROM drying_logs
                 WHERE session_id = :sid
                 ORDER BY timestamp DESC LIMIT 1"
            );
            $logStmt->execute([':sid' => $sid]);
            $log = $logStmt->fetch(PDO::FETCH_ASSOC);

            if (!$log) {
                sendResponse('success', 'Prototype session running, awaiting first reading.', [
                    'recorded_temp'      => null,
                    'recorded_humidity'  => null,
                    'phase'              => 'Idle',
                    'heater_state'       => 0,
                    'exhaust_state'      => 0,
                    'fan_state'          => 0,
                    'session_id'         => (int)$sid,
                    'set_temp'           => floatval($session['set_temp']),
                    'set_humidity'       => floatval($session['set_humidity']),
                    'start_time'         => $session['start_time'],
                    'ctrl_status'        => $ctrl_status,
                    'cooldown_remaining' => $cooldown_remaining,
                    'is_scheduled'       => !empty($session['schedule_id']),
                    'schedule_id'        => $session['schedule_id'] ? (int)$session['schedule_id'] : null,
                    'schedule_title'     => $session['schedule_title'] ?? null,
                    'schedule_date'      => $session['sched_date'] ?? null,
                    'schedule_time'      => $session['sched_time'] ?? null,
                    'duration_hours'     => $sessionDurationHours,
                    'auto_started'       => $session['auto_started'] ? (int)$session['auto_started'] : 0,
                ]);
            }

            $log['heater_state']       = (int)$log['heater_state'];
            $log['exhaust_state']      = (int)$log['exhaust_state'];
            $log['fan_state']          = (int)$log['fan_state'];
            $log['recorded_temp']      = floatval($log['recorded_temp']);
            $log['recorded_humidity']  = floatval($log['recorded_humidity']);
            $log['session_id']         = (int)$sid;
            $log['set_temp']           = floatval($session['set_temp']);
            $log['set_humidity']       = floatval($session['set_humidity']);
            $log['start_time']         = $session['start_time'];
            $log['ctrl_status']        = $ctrl_status;
            $log['cooldown_remaining'] = $cooldown_remaining;
            $log['cycle_count']        = (int)$cycle_count;
            $log['last_cycle_start']   = $last_cycle_start;
            
            // Add schedule information
            $log['is_scheduled']       = !empty($session['schedule_id']);
            $log['schedule_id']        = $session['schedule_id'] ? (int)$session['schedule_id'] : null;
            $log['schedule_title']     = $session['schedule_title'] ?? null;
            $log['schedule_date']      = $session['sched_date'] ?? null;
            $log['schedule_time']      = $session['sched_time'] ?? null;
            $log['duration_hours']     = $sessionDurationHours;
            $log['auto_started']       = $session['auto_started'] ? (int)$session['auto_started'] : 0;

            // Fish-ready check
            $recentLogs = $dbh->prepare("SELECT recorded_temp, recorded_humidity FROM drying_logs WHERE session_id=:sid ORDER BY timestamp DESC LIMIT 5");
            $recentLogs->execute([':sid'=>$sid]);
            $recent = $recentLogs->fetchAll(PDO::FETCH_ASSOC);
            $fish_ready = false;
            if (count($recent) >= 5) {
                $allReady = true;
                foreach ($recent as $r) {
                    $t = floatval($r['recorded_temp']);
                    $h = floatval($r['recorded_humidity']);
                    if ($t < $log['set_temp'] - 2 || $t > $log['set_temp'] + 2 || $h > $log['set_humidity'] + 5) {
                        $allReady = false; break;
                    }
                }
                $fish_ready = $allReady;
            }
            $log['fish_ready'] = $fish_ready;

            $recentWindow = $dbh->prepare(
                "SELECT recorded_temp, recorded_humidity, phase, heater_state, exhaust_state, fan_state, timestamp
                 FROM drying_logs
                 WHERE session_id = :sid
                 ORDER BY timestamp DESC
                 LIMIT 5"
            );
            $recentWindow->execute([':sid' => $sid]);
            $recentLogsData = $recentWindow->fetchAll(PDO::FETCH_ASSOC);
            
            // Fallback to sensor_readings if drying_logs is empty
            if (empty($recentLogsData)) {
                $recentWindow = $dbh->prepare(
                    "SELECT temperature AS recorded_temp, humidity AS recorded_humidity, 
                            NULL AS phase, NULL AS heater_state, NULL AS exhaust_state, 
                            NULL AS fan_state, timestamp
                     FROM sensor_readings
                     WHERE session_id = :sid
                     ORDER BY timestamp DESC
                     LIMIT 5"
                );
                $recentWindow->execute([':sid' => $sid]);
                $recentLogsData = $recentWindow->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $log['recent_logs'] = array_reverse($recentLogsData);

            sendResponse('success', 'Live data fetched.', $log);

        } catch (Exception $e) {
            sendResponse('error', 'Failed: ' . $e->getMessage());
        }
        break;

    // ============================================================
    //  GET PROTOTYPE STATUS — online/offline heartbeat
    // ============================================================
    case 'get_prototype_status':
        try {
            $proto_id = intval($_GET['proto_id'] ?? $_SESSION['proto_id'] ?? 0);
            if ($proto_id <= 0) {
                sendResponse('error', 'Prototype id is required.');
            }

            $stmt = $dbh->prepare(
                "SELECT id, model_name, given_code, status, updated_at,
                        TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS seconds_since_seen
                 FROM tbl_prototypes
                 WHERE id = :id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $proto_id]);
            $proto = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$proto) {
                sendResponse('error', 'Prototype not found.');
            }

            $secondsSinceSeen = intval($proto['seconds_since_seen'] ?? 999999);
            $isActive = ((string)$proto['status'] === '1');
            $isOnline = $isActive && $secondsSinceSeen <= 30;  // Updated to 30 seconds

            sendResponse('success', 'Prototype status fetched.', [
                'id'                   => (int)$proto['id'],
                'model_name'           => $proto['model_name'],
                'given_code'           => $proto['given_code'],
                'status'               => (int)$proto['status'],
                'prototype_active'     => $isActive,
                'updated_at'           => $proto['updated_at'],
                'seconds_since_seen'   => $secondsSinceSeen,
                'prototype_online'     => $isOnline,
            ]);
        } catch(Exception $e){ sendResponse('error', $e->getMessage()); }
        break;

    // ============================================================
    //  GET LIVE ALERTS
    // ============================================================
    case 'get_live_alerts':
        try {
            $stmt = $dbh->query(
                "SELECT ds.session_id, u.username, ds.set_temp,
                    (SELECT recorded_temp FROM drying_logs WHERE session_id=ds.session_id ORDER BY timestamp DESC LIMIT 1) AS latest_temp
                 FROM drying_sessions ds
                 JOIN tblusers u ON u.id=ds.user_id
                 WHERE ds.status='Running'
                 HAVING latest_temp IS NOT NULL AND latest_temp >= (ds.set_temp + 2)"
            );
            sendResponse('success','Alerts fetched.',$stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e){ sendResponse('success','No alerts.',[]); }
        break;

    // ============================================================
    //  GET ALL SESSIONS (Admin)
    // ============================================================
    case 'get_all_sessions_admin':
        if (!$is_admin) sendResponse('error','Admin only.');
        try {
            $stmt = $dbh->query(
                "SELECT ds.session_id, u.username, u.id AS user_id,
                    ds.start_time, ds.end_time,
                    ds.set_temp, ds.set_humidity, ds.status,
                    TIMEDIFF(COALESCE(ds.end_time,NOW()),ds.start_time) AS duration,
                    ROUND(AVG(dl.recorded_temp),2)     AS avg_temp,
                    ROUND(AVG(dl.recorded_humidity),2) AS avg_hum,
                    COUNT(dl.log_id) AS total_logs
                 FROM drying_sessions ds
                 JOIN tblusers u ON u.id=ds.user_id
                 LEFT JOIN drying_logs dl ON dl.session_id=ds.session_id
                 GROUP BY ds.session_id
                 ORDER BY ds.start_time DESC"
            );
            sendResponse('success','Prototype sessions fetched.',$stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e){ sendResponse('error',$e->getMessage()); }
        break;

    // ============================================================
    //  MY SESSIONS (Prototype)
    // ============================================================
    case 'get_my_sessions':
        try {
            // Get sessions with prototype-based access control
            // Admin: sees all sessions | Unit users: only their prototype's sessions
            $whereClause = '';
            $params = [];
            
            if (($_SESSION['permission'] ?? 'user') === 'admin') {
                // Admin sees ALL sessions
                $whereClause = '';
            } else {
                // Unit users only see sessions from their prototype
                $proto_id = intval($_SESSION['proto_id'] ?? $_GET['proto_id'] ?? $_POST['proto_id'] ?? 0);
                if ($proto_id > 0) {
                    $whereClause = 'WHERE ds.proto_id = :proto_id';
                    $params[':proto_id'] = $proto_id;
                } else {
                    // If no proto_id, show no sessions
                    $whereClause = 'WHERE 1=0';
                }
            }
            
            $stmt = $dbh->prepare(
                "SELECT ds.session_id, ds.start_time, ds.end_time, ds.set_temp, ds.set_humidity, ds.status, ds.schedule_id, ds.proto_id,
                    TIMEDIFF(COALESCE(ds.end_time,NOW()),ds.start_time) AS duration,
                    ROUND(AVG(dl.recorded_temp),2)     AS avg_temp,
                    ROUND(AVG(dl.recorded_humidity),2) AS avg_hum,
                    u.username, u.permission,
                    CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info,
                    p.model_name, p.given_code,
                    -- Show device info for ALL sessions
                    CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS display_name,
                    -- Status logic: Manual = Completed, Scheduled + Interrupted = Terminated
                    CASE 
                        WHEN ds.status = 'Running' THEN 'Running'
                        WHEN ds.schedule_id IS NOT NULL AND ds.status = 'Interrupted' THEN 'Terminated'
                        WHEN ds.status IN ('Completed', 'Interrupted') THEN 'Completed'
                        ELSE ds.status
                    END AS display_status
                 FROM drying_sessions ds
                 LEFT JOIN drying_logs dl ON dl.session_id=ds.session_id
                 LEFT JOIN tblusers u ON u.id = ds.user_id
                 LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
                 $whereClause
                 GROUP BY ds.session_id
                 ORDER BY ds.start_time DESC"
            );
            $stmt->execute($params);
            sendResponse('success','Prototype sessions fetched.',$stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e){ sendResponse('error','Failed.'); }
        break;

    // ============================================================
    //  GET SESSION LOGS
    // ============================================================
    case 'get_session_detail':
    case 'get_session_logs':
        $session_id = intval($_GET['session_id'] ?? 0);
        try {
            $check = $dbh->prepare("SELECT session_id FROM drying_sessions WHERE session_id=:sid AND (user_id=:uid OR :perm='admin') LIMIT 1");
            $check->execute([':sid'=>$session_id,':uid'=>$user_id,':perm'=>$_SESSION['permission']??'user']);
            if (!$check->fetch()) sendResponse('error','Access denied.');
            $stmt = $dbh->prepare("SELECT recorded_temp,recorded_humidity,phase,heater_state,exhaust_state,fan_state,timestamp FROM drying_logs WHERE session_id=:sid ORDER BY timestamp ASC");
            $stmt->execute([':sid'=>$session_id]);
            sendResponse('success','Logs fetched.',$stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e){ sendResponse('error','Failed.'); }
        break;

    // ============================================================
    //  USER CRUD — Admin only
    // ============================================================
    case 'get_all_users':
        if (!$is_admin) sendResponse('error','Admin only.');
        try {
            $stmt = $dbh->query(
                "SELECT u.id, u.username, u.permission, u.status,
                    COUNT(DISTINCT ds.session_id) AS total_sessions,
                    MAX(ds.start_time) AS last_active
                 FROM tblusers u
                 LEFT JOIN drying_sessions ds ON ds.user_id=u.id
                 GROUP BY u.id ORDER BY u.id DESC"
            );
            sendResponse('success','Users fetched.',$stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e){ sendResponse('error',$e->getMessage()); }
        break;

    case 'add_user':
        if (!$is_admin) sendResponse('error','Admin only.');
        $uname  = htmlspecialchars(trim($_POST['username'] ?? ''));
        $pass   = $_POST['password'] ?? '';
        $perm   = in_array($_POST['permission']??'user',['admin','user']) ? $_POST['permission'] : 'user';
        $status = intval($_POST['status'] ?? 1);
        if (!$uname || !$pass) sendResponse('error','Username and password required.');
        try {
            $chk = $dbh->prepare("SELECT id FROM tblusers WHERE username=:u LIMIT 1");
            $chk->execute([':u'=>$uname]);
            if ($chk->fetch()) sendResponse('error','Username already exists.');
            $dbh->prepare("INSERT INTO tblusers (username,password,permission,status) VALUES(:u,:p,:perm,:s)")
                ->execute([':u'=>$uname,':p'=>password_hash($pass,PASSWORD_DEFAULT),':perm'=>$perm,':s'=>$status]);
            sendResponse('success','User created.',['id'=>$dbh->lastInsertId()]);
        } catch(Exception $e){ sendResponse('error',$e->getMessage()); }
        break;

    case 'update_user':
        if (!$is_admin) sendResponse('error','Admin only.');
        $uid   = intval($_POST['user_id'] ?? 0);
        $uname = htmlspecialchars(trim($_POST['username'] ?? ''));
        $perm  = in_array($_POST['permission']??'user',['admin','user']) ? $_POST['permission'] : 'user';
        $stat  = intval($_POST['status'] ?? 1);
        $pass  = $_POST['password'] ?? '';
        try {
            if ($pass) {
                $dbh->prepare("UPDATE tblusers SET username=:u,permission=:p,status=:s,password=:pw WHERE id=:id")
                    ->execute([':u'=>$uname,':p'=>$perm,':s'=>$stat,':pw'=>password_hash($pass,PASSWORD_DEFAULT),':id'=>$uid]);
            } else {
                $dbh->prepare("UPDATE tblusers SET username=:u,permission=:p,status=:s WHERE id=:id")
                    ->execute([':u'=>$uname,':p'=>$perm,':s'=>$stat,':id'=>$uid]);
            }
            sendResponse('success','User updated.');
        } catch(Exception $e){ sendResponse('error',$e->getMessage()); }
        break;

    case 'delete_user':
        if (!$is_admin) sendResponse('error','Admin only.');
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid === $user_id) sendResponse('error','Cannot delete your own account.');
        try {
            $dbh->prepare("DELETE FROM tblusers WHERE id=:id")->execute([':id'=>$uid]);
            sendResponse('success','User deleted.');
        } catch(Exception $e){ sendResponse('error',$e->getMessage()); }
        break;

    case 'toggle_status':
        if (!$is_admin) sendResponse('error','Admin only.');
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid === $user_id) sendResponse('error','Cannot disable your own account.');
        try {
            $row = $dbh->prepare("SELECT status FROM tblusers WHERE id=:id");
            $row->execute([':id'=>$uid]);
            $cur = $row->fetch(PDO::FETCH_ASSOC);
            $new = ($cur['status'] == 1) ? 0 : 1;
            $dbh->prepare("UPDATE tblusers SET status=:s WHERE id=:id")->execute([':s'=>$new,':id'=>$uid]);
            sendResponse('success', $new ? 'User enabled.' : 'User disabled.', ['new_status'=>$new]);
        } catch(Exception $e){ sendResponse('error',$e->getMessage()); }
        break;

    // ============================================================
    //  GET MY RECORDS
    // ============================================================
    case 'get_my_records':
        try {
            $stmt = $dbh->prepare(
                "SELECT dr.id, dr.batch_id, dr.duration, dr.energy,
                        dr.temp_avg, dr.hum_avg, dr.status, dr.timestamp,
                        dr.session_id
                 FROM drying_records dr
                 WHERE dr.user_id = :uid
                 ORDER BY dr.timestamp DESC"
            );
            $stmt->execute([':uid' => $user_id]);
            sendResponse('success', 'Records fetched.', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e){
            sendResponse('error', 'Failed: ' . $e->getMessage());
        }
        break;

    // ============================================================
    //  GET ALL RECORDS — admin
    // ============================================================
    case 'get_all_records_admin':
        if (!$is_admin) sendResponse('error','Admin only.');
        try {
            $stmt = $dbh->query(
                "SELECT dr.id, dr.batch_id, dr.duration, dr.energy,
                        dr.temp_avg, dr.hum_avg, dr.status, dr.timestamp,
                        u.username
                 FROM drying_records dr
                 LEFT JOIN tblusers u ON u.id = dr.user_id
                 ORDER BY dr.timestamp DESC"
            );
            sendResponse('success','All records fetched.',$stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e){ sendResponse('error',$e->getMessage()); }
        break;

    // ============================================================
    //  GET TODAY'S SESSION STATISTICS
    // ============================================================
    case 'get_today_stats':
        try {
            $today = date('Y-m-d');
            
            // Get today's sessions with durations
            $stmt = $dbh->prepare(
                "SELECT 
                    session_id,
                    start_time,
                    end_time,
                    TIMEDIFF(COALESCE(end_time, NOW()), start_time) AS duration,
                    TIME_TO_SEC(TIMEDIFF(COALESCE(end_time, NOW()), start_time)) AS duration_sec
                 FROM drying_sessions 
                 WHERE DATE(start_time) = :today AND user_id = :uid"
            );
            $stmt->execute([':today' => $today, ':uid' => $user_id]);
            $todaySessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $totalToday = count($todaySessions);
            $totalDurationSec = 0;
            $shortestSec = PHP_INT_MAX;
            $longestSec = 0;
            
            foreach ($todaySessions as $s) {
                $sec = (int)$s['duration_sec'];
                $totalDurationSec += $sec;
                if ($sec < $shortestSec) $shortestSec = $sec;
                if ($sec > $longestSec) $longestSec = $sec;
            }
            
            // Calculate average
            $avgDurationSec = $totalToday > 0 ? round($totalDurationSec / $totalToday) : 0;
            
            // Format durations as HH:MM:SS
            $formatDuration = function($sec) {
                if ($sec <= 0 || $sec == PHP_INT_MAX) return '—';
                $h = floor($sec / 3600);
                $m = floor(($sec % 3600) / 60);
                $s = $sec % 60;
                return sprintf('%02d:%02d:%02d', $h, $m, $s);
            };
            
            // Get all-time total sessions for this user
            $totalAll = $dbh->prepare("SELECT COUNT(*) FROM drying_sessions WHERE user_id = :uid");
            $totalAll->execute([':uid' => $user_id]);
            $totalAllCount = (int)$totalAll->fetchColumn();
            
            sendResponse('success', 'Today stats fetched.', [
                'today_count'     => $totalToday,
                'today_avg'       => $formatDuration($avgDurationSec),
                'today_shortest'  => $formatDuration($shortestSec),
                'today_longest'   => $formatDuration($longestSec),
                'total_all'       => $totalAllCount,
            ]);
        } catch(Exception $e) { 
            sendResponse('error', $e->getMessage()); 
        }
        break;

    default:
        sendResponse('error','Invalid action: '.$action);
}
?>