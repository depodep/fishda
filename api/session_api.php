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
date_default_timezone_set('Asia/Manila');

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

function recordOfflineScheduledStartFailure($dbh, $proto_id = 0) {
    try {
        $dbh->beginTransaction();

        $dueSched = $dbh->prepare(
            "SELECT id, user_id, proto_id, title, sched_date, sched_time, set_temp, set_humidity, duration_hours
             FROM batch_schedules
             WHERE status='Scheduled'
               AND CONCAT(sched_date, ' ', sched_time) <= NOW()
               AND (:pid <= 0 OR proto_id = :pid OR proto_id IS NULL)
             ORDER BY CONCAT(sched_date, ' ', sched_time) ASC
             LIMIT 1
             FOR UPDATE"
        );
        $dueSched->execute([':pid' => (int)$proto_id]);
        $sched = $dueSched->fetch(PDO::FETCH_ASSOC);

        if (!$sched) {
            $dbh->rollBack();
            return null;
        }

        $stamp = date('Y-m-d H:i:s');
        $failureNote = '[FAILED_TO_START] Device offline at scheduled start. Recorded at ' . $stamp;

        $updSched = $dbh->prepare(
            "UPDATE batch_schedules
             SET status='Done',
                 notes=TRIM(CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\\n' END, :failure_note))
             WHERE id=:id AND status='Scheduled'"
        );
        $updSched->execute([
            ':id' => (int)$sched['id'],
            ':failure_note' => $failureNote,
        ]);

        if ($updSched->rowCount() < 1) {
            $dbh->rollBack();
            return null;
        }

        $insFailed = $dbh->prepare(
            "INSERT INTO drying_sessions
             (user_id, proto_id, schedule_id, set_temp, set_humidity, status, start_time, end_time, notes)
             VALUES
             (:uid, :pid, :sched_id, :temp, :hum, 'Completed', TIMESTAMP(:sched_date, :sched_time), NOW(), :notes)"
        );
        $insFailed->execute([
            ':uid' => (int)$sched['user_id'],
            ':pid' => (($sched['proto_id'] ?? null) !== null)
                ? (int)$sched['proto_id']
                : ((int)$proto_id > 0 ? (int)$proto_id : null),
            ':sched_id' => (int)$sched['id'],
            ':temp' => (float)$sched['set_temp'],
            ':hum' => (float)$sched['set_humidity'],
            ':sched_date' => $sched['sched_date'],
            ':sched_time' => $sched['sched_time'],
            ':notes' => 'FailedToStart | ' . $failureNote,
        ]);

        $failedSessionId = (int)$dbh->lastInsertId();
        $dbh->commit();

        return [
            'recorded' => true,
            'schedule_id' => (int)$sched['id'],
            'session_id' => $failedSessionId,
            'schedule_title' => (string)$sched['title'],
            'scheduled_start' => (string)$sched['sched_date'] . ' ' . (string)$sched['sched_time'],
        ];
    } catch (Exception $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        return null;
    }
}

$proto_id = intval($_SESSION['proto_id'] ?? $_GET['proto_id'] ?? $_POST['proto_id'] ?? 0);
$session_user_id = intval($_SESSION['user_id'] ?? 0);
$is_admin = isset($_SESSION['user_id']) && (($_SESSION['permission'] ?? 'user') === 'admin');

if (!in_array($action, $esp_actions) && !isset($_SESSION['user_id']) && $proto_id <= 0 && $session_user_id <= 0) {
    sendResponse('error', 'Unauthorized. Please login.');
}

$espProto = null;
if (in_array($action, $esp_actions)) {
    $espProto = validateEspRequest($dbh);
    if ($proto_id <= 0 && is_array($espProto) && isset($espProto['id'])) {
        $proto_id = (int)$espProto['id'];
    }
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
        $fish_count = intval($_POST['fish_count'] ?? 0);
        if ($fish_count < 1) {
            sendResponse('error', 'Fish count must be at least 1.');
        }
        $min_duration_hours = (1 / 60); // 1 minute
        $duration_hours = floatval($_POST['duration_hours'] ?? 0);
        if ($duration_hours > 0) {
            $duration_hours = max($min_duration_hours, min(24, $duration_hours));
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
            // End any existing running sessions for this prototype
            $dbh->prepare("UPDATE drying_sessions SET status='Interrupted', end_time=NOW() WHERE status='Running' AND proto_id=:pid")
                ->execute([':pid' => $proto_id]);
            
            // Use the proto_id from session/request, fallback to 1 if not set
            $session_proto_id = ($proto_id > 0) ? $proto_id : 1;
            
            $stmt = $dbh->prepare("INSERT INTO drying_sessions (user_id,proto_id,schedule_id,set_temp,set_humidity,fish_count,status,start_time,notes) VALUES(:uid,:pid,:sid,:t,:h,:fc,'Running',NOW(),:notes)");
            $stmt->execute([':uid'=>$default_user_id,':pid'=>$session_proto_id,':sid'=>$schedule_id,':t'=>$set_temp,':h'=>$set_hum,':fc'=>$fish_count,':notes'=>$session_notes]);
            $session_id = $dbh->lastInsertId();
            // Sync drying_controls — clear any leftover cooldown
            $dbh->prepare("UPDATE drying_controls SET target_temp=:t,target_humidity=:h,status='RUNNING',start_time=NOW(),cooldown_until=NULL WHERE id=1")
                ->execute([':t'=>$set_temp,':h'=>$set_hum]);
            sendResponse('success','Prototype drying session started.',[
                'session_id'   => (int)$session_id,
                'set_temp'     => $set_temp,
                'set_humidity' => $set_hum,
                'fish_count'   => $fish_count,
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
            $finalStatus = ($save_reason === 'AutoDuration') ? 'Completed' : 'Interrupted';
            $stmt = $dbh->prepare("UPDATE drying_sessions SET status=:st,end_time=NOW() $where");
            $stmt->execute(array_merge([':st' => $finalStatus], $params));
            $dbh->query("UPDATE drying_controls SET status='STOPPED',start_time=NULL,cooldown_until=NULL WHERE id=1");

            $msg = ($finalStatus === 'Interrupted')
                ? 'Prototype session terminated and saved.'
                : 'Prototype session completed and saved.';
            sendResponse('success',$msg,['save_reason'=>$save_reason,'final_status'=>$finalStatus]);
        } catch(Exception $e){ sendResponse('error','Stop failed: '.$e->getMessage()); }
        break;

    // ============================================================
    //  LOG READING — from ESP8266
    //  UPDATED: FAN = heat source (ON during Heating + Drying)
    // ============================================================
    case 'log_reading':
        // Validate scheduled sessions before processing log so due batches can start here.
        validateScheduledSessions($dbh);

        $session_id        = intval($_POST['session_id'] ?? 0);
        $recorded_temp     = floatval($_POST['temp'] ?? 0);
        $recorded_humidity = floatval($_POST['humidity'] ?? 0);

        // Keep live sensor cache fresh for dashboard idle/live readings.
        $upsertLiveCache = function($phase, $fan1, $fan2, $heater1 = 0, $heater2 = 0) use ($dbh, $recorded_temp, $recorded_humidity) {
            try {
                $dbh->prepare(
                    "INSERT INTO live_sensor_cache
                     (id, temperature, humidity, fan1_state, fan2_state, heater1_state, heater2_state, exhaust_state, phase, timestamp)
                     VALUES (1, :temp, :hum, :f1, :f2, :h1, :h2, 0, :phase, NOW())
                     ON DUPLICATE KEY UPDATE
                        temperature=:temp_u,
                        humidity=:hum_u,
                        fan1_state=:f1_u,
                        fan2_state=:f2_u,
                        heater1_state=:h1_u,
                        heater2_state=:h2_u,
                        exhaust_state=0,
                        phase=:phase_u,
                        timestamp=NOW()"
                )->execute([
                    ':temp' => $recorded_temp,
                    ':hum' => $recorded_humidity,
                    ':f1' => (int)$fan1,
                    ':f2' => (int)$fan2,
                    ':h1' => (int)$heater1,
                    ':h2' => (int)$heater2,
                    ':phase' => $phase,
                    ':temp_u' => $recorded_temp,
                    ':hum_u' => $recorded_humidity,
                    ':f1_u' => (int)$fan1,
                    ':f2_u' => (int)$fan2,
                    ':h1_u' => (int)$heater1,
                    ':h2_u' => (int)$heater2,
                    ':phase_u' => $phase,
                ]);
            } catch (Exception $e) {
                // Non-fatal: do not interrupt ESP control loop.
            }
        };

        // If session_id=0, this is idle state - just cache the sensor data
        if ($session_id <= 0) {
            $upsertLiveCache('Idle', 0, 0, 0, 0);

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

        if ($session_id <= 0 && $proto_id > 0) {
            $runningStmt = $dbh->prepare(
                "SELECT session_id
                 FROM drying_sessions
                 WHERE status='Running' AND proto_id=:pid
                 ORDER BY start_time DESC
                 LIMIT 1"
            );
            $runningStmt->execute([':pid' => $proto_id]);
            $runningSession = $runningStmt->fetch(PDO::FETCH_ASSOC);
            if ($runningSession && !empty($runningSession['session_id'])) {
                $session_id = (int)$runningSession['session_id'];
            }
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

            // Target reached in normal drying range: start 5-minute cooldown cycle.
            // ESP already supports command='COOLDOWN', so this server-side update is enough.
            if (!$auto_stopped && $ctrl_status !== 'COOLDOWN' && $phase === 'Drying' && $recorded_temp >= $target_temp) {
                $cooldown_until = date('Y-m-d H:i:s', time() + 300);
                try {
                    $dbh->prepare("UPDATE drying_controls SET status='COOLDOWN', cooldown_until=:until WHERE id=1")
                        ->execute([':until' => $cooldown_until]);
                } catch (Exception $e) {
                    // Non-fatal: keep control response consistent even if DB write fails.
                }

                $ctrl_status = 'COOLDOWN';
                $cooldown_remaining = 300;
                $phase = 'Cooldown';
                $fan1_state = 0;
                $fan2_state = 0;
                $command = 'COOLDOWN';
                $fish_ready = true;
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

        // Compute heater cycle based on controls.start_time: 2min delay, 5min ON / 10min OFF
        $heater1_state = 0;
        $heater2_state = 0;
        try {
            $ctrlRow = $dbh->query("SELECT start_time FROM drying_controls WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $start_time = $ctrlRow['start_time'] ?? null;
            if ($start_time && $command !== 'COOLDOWN' && $phase !== 'Cooldown') {
                $startTs = strtotime($start_time);
                if ($startTs !== false) {
                    $elapsed = time() - $startTs;
                    if ($elapsed >= 120) {
                        $heaterPhaseElapsed = $elapsed - 120;
                        $cycle = 900;
                        $heaterOn = ($heaterPhaseElapsed % $cycle) < 300 ? 1 : 0;
                        $heater1_state = $heater2_state = (int)$heaterOn;
                    }
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        $upsertLiveCache($phase, $fan1_state, $fan2_state, $heater1_state, $heater2_state);

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
            'heater1'             => $heater1_state ?? 0,
            'heater2'             => $heater2_state ?? 0,
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
                // For ESP requests, scope running session to the validated prototype if available.
                if ($proto_id > 0) {
                    $stmt = $dbh->prepare("SELECT session_id,status,set_temp,set_humidity FROM drying_sessions WHERE status='Running' AND proto_id=:pid ORDER BY start_time DESC LIMIT 1");
                    $stmt->execute([':pid' => $proto_id]);
                } else {
                    $stmt = $dbh->query("SELECT session_id,status,set_temp,set_humidity FROM drying_sessions WHERE status='Running' ORDER BY start_time DESC LIMIT 1");
                }
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
            // Allow the current heartbeat to promote any due scheduled batch before reading status.
            validateScheduledSessions($dbh);

            $offlineFailedStart = null;

            // For proto_id requests, find the running session for this prototype
            if ($proto_id > 0) {
                $sessionStmt = $dbh->prepare(
                    "SELECT ds.session_id, ds.set_temp, ds.set_humidity, ds.start_time, ds.user_id,
                            ds.schedule_id, ds.notes,
                            bs.title AS schedule_title, bs.sched_date, bs.sched_time, bs.duration_hours, bs.auto_started
                     FROM drying_sessions ds
                     LEFT JOIN batch_schedules bs ON bs.id = ds.schedule_id
                     WHERE ds.status = 'Running' AND ds.proto_id = :proto_id
                     ORDER BY ds.start_time DESC LIMIT 1"
                );
                $sessionStmt->execute([':proto_id' => $proto_id]);
                $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
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

            if (!$session && !$deviceOnline) {
                $offlineFailedStart = recordOfflineScheduledStartFailure($dbh, $proto_id);
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
                        'failed_to_start'    => $offlineFailedStart,
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
                        'failed_to_start'    => $offlineFailedStart,
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
                // No logs yet - try to get data from live_sensor_cache
                $liveCache = $dbh->query(
                    "SELECT temperature, humidity, phase, heater1_state, fan1_state, exhaust_state, timestamp
                     FROM live_sensor_cache WHERE id = 1 LIMIT 1"
                )->fetch(PDO::FETCH_ASSOC);
                
                sendResponse('success', 'Prototype session running, awaiting first reading.', [
                    'recorded_temp'      => $liveCache ? floatval($liveCache['temperature']) : null,
                    'recorded_humidity'  => $liveCache ? floatval($liveCache['humidity']) : null,
                    'phase'              => $liveCache ? ($liveCache['phase'] ?? 'Idle') : 'Idle',
                    'heater_state'       => $liveCache ? (int)($liveCache['heater1_state'] ?? 0) : 0,
                    'exhaust_state'      => $liveCache ? (int)($liveCache['exhaust_state'] ?? 0) : 0,
                    'fan_state'          => $liveCache ? (int)($liveCache['fan1_state'] ?? 0) : 0,
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

            // Merge the freshest relay states from live_sensor_cache so the dashboard
            // can render individual fan/heater indicators instead of the collapsed log fields.
            try {
                $liveState = $dbh->query(
                    "SELECT fan1_state, fan2_state, heater1_state, heater2_state, phase
                     FROM live_sensor_cache
                     WHERE id = 1
                     LIMIT 1"
                )->fetch(PDO::FETCH_ASSOC);
                if ($liveState) {
                    $log['fan1_state'] = (int)($liveState['fan1_state'] ?? $log['fan_state'] ?? 0);
                    $log['fan2_state'] = (int)($liveState['fan2_state'] ?? $log['fan_state'] ?? 0);
                    $log['heater1_state'] = (int)($liveState['heater1_state'] ?? $log['heater_state'] ?? 0);
                    $log['heater2_state'] = (int)($liveState['heater2_state'] ?? $log['heater_state'] ?? 0);
                    $log['phase'] = $liveState['phase'] ?? $log['phase'];
                } else {
                    $log['fan1_state'] = (int)($log['fan_state'] ?? 0);
                    $log['fan2_state'] = (int)($log['fan_state'] ?? 0);
                    $log['heater1_state'] = (int)($log['heater_state'] ?? 0);
                    $log['heater2_state'] = (int)($log['heater_state'] ?? 0);
                }
            } catch (Exception $e) {
                $log['fan1_state'] = (int)($log['fan_state'] ?? 0);
                $log['fan2_state'] = (int)($log['fan_state'] ?? 0);
                $log['heater1_state'] = (int)($log['heater_state'] ?? 0);
                $log['heater2_state'] = (int)($log['heater_state'] ?? 0);
            }

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
                    ds.set_temp, ds.set_humidity,
                    CASE
                        WHEN COALESCE(ds.notes, '') LIKE '%FailedToStart%' THEN 'FailedToStart'
                        ELSE ds.status
                    END AS status,
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
            
            $sql = "SELECT ds.session_id, ds.start_time, ds.end_time, ds.set_temp, ds.set_humidity, ds.fish_count, ds.status, ds.schedule_id, ds.proto_id,
                    TIMEDIFF(COALESCE(ds.end_time,NOW()),ds.start_time) AS duration,
                    ROUND(AVG(dl.recorded_temp),2)     AS avg_temp,
                    ROUND(AVG(dl.recorded_humidity),2) AS avg_hum,
                    u.username, u.permission,
                    CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info,
                    p.model_name, p.given_code,
                    CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS display_name,
                    CASE 
                        WHEN COALESCE(ds.notes, '') LIKE '%FailedToStart%' THEN 'FailedToStart'
                        WHEN ds.status = 'Running' THEN 'Running'
                        WHEN ds.status = 'Completed' THEN 'Completed'
                        WHEN ds.status = 'Interrupted' THEN 'Terminated'
                        ELSE COALESCE(ds.status, 'Unknown')
                    END AS display_status
                 FROM drying_sessions ds
                 LEFT JOIN drying_logs dl ON dl.session_id=ds.session_id
                 LEFT JOIN tblusers u ON u.id = ds.user_id
                 LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
                 $whereClause
                 GROUP BY ds.session_id
                 ORDER BY ds.start_time DESC";
            
            $stmt = $dbh->prepare($sql);
            $stmt->execute($params);
            sendResponse('success','Prototype sessions fetched.',$stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e){ sendResponse('error','Failed: ' . $e->getMessage()); }
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
                "SELECT ds.session_id AS id,
                        ds.proto_id AS batch_id,
                        TIMEDIFF(COALESCE(ds.end_time,NOW()),ds.start_time) AS duration,
                        0 AS energy,
                        COALESCE(la.temp_avg, 0) AS temp_avg,
                        COALESCE(la.hum_avg, 0) AS hum_avg,
                        CASE
                            WHEN COALESCE(ds.notes, '') LIKE '%FailedToStart%' THEN 'FailedToStart'
                            ELSE ds.status
                        END AS status,
                        COALESCE(ds.end_time, ds.start_time) AS timestamp,
                        ds.session_id
                 FROM drying_sessions ds
                 LEFT JOIN (
                    SELECT session_id,
                           ROUND(AVG(recorded_temp),2) AS temp_avg,
                           ROUND(AVG(recorded_humidity),2) AS hum_avg
                    FROM drying_logs
                    GROUP BY session_id
                 ) la ON la.session_id = ds.session_id
                 WHERE ds.user_id = :uid AND ds.status <> 'Running'
                 ORDER BY COALESCE(ds.end_time, ds.start_time) DESC"
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
                "SELECT ds.session_id AS id,
                        ds.proto_id AS batch_id,
                        TIMEDIFF(COALESCE(ds.end_time,NOW()),ds.start_time) AS duration,
                        0 AS energy,
                        COALESCE(la.temp_avg, 0) AS temp_avg,
                        COALESCE(la.hum_avg, 0) AS hum_avg,
                        CASE
                            WHEN COALESCE(ds.notes, '') LIKE '%FailedToStart%' THEN 'FailedToStart'
                            ELSE ds.status
                        END AS status,
                        COALESCE(ds.end_time, ds.start_time) AS timestamp,
                        COALESCE(p.model_name, 'Unknown Model') AS prototype_model,
                        COALESCE(p.given_code, '') AS prototype_code,
                        COALESCE(CONCAT(p.model_name, ' (', p.given_code, ')'), CONCAT('Prototype #', ds.proto_id), CONCAT('Session #', ds.session_id)) AS prototype_label
                 FROM drying_sessions ds
                 LEFT JOIN (
                    SELECT session_id,
                           ROUND(AVG(recorded_temp),2) AS temp_avg,
                           ROUND(AVG(recorded_humidity),2) AS hum_avg
                    FROM drying_logs
                    GROUP BY session_id
                 ) la ON la.session_id = ds.session_id
                 LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
                 WHERE ds.status <> 'Running'
                 ORDER BY COALESCE(ds.end_time, ds.start_time) DESC"
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