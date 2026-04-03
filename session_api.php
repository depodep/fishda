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

require_once 'dbcon.php';
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

    if ($proto_id > 0) {
        $chk = $dbh->prepare("SELECT id FROM tblusers WHERE id=:id AND status=1 LIMIT 1");
        $chk->execute([':id' => $proto_id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $_SESSION['user_id'] = (int)$row['id'];
            return (int)$row['id'];
        }
    }

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

$proto_id = intval($_SESSION['proto_id'] ?? 0);
$session_user_id = intval($_SESSION['user_id'] ?? 0);
$is_admin = isset($_SESSION['sid']) && (($_SESSION['permission'] ?? 'user') === 'admin');

if (!in_array($action, $esp_actions) && !isset($_SESSION['sid']) && $proto_id <= 0 && $session_user_id <= 0) {
    sendResponse('error', 'Unauthorized. Please login.');
}

if (in_array($action, $esp_actions)) {
    validateEspRequest($dbh);
}

$user_id = $is_admin
    ? intval($_SESSION['sid'] ?? 0)
    : resolveSessionUserIdFromPrototype($dbh, $proto_id);

switch ($action) {

    // ============================================================
    //  START SESSION
    // ============================================================
    case 'start_session':
        $set_temp = max(30, min(70, floatval($_POST['set_temp'] ?? 45)));
        $set_hum  = max(10, min(80, floatval($_POST['set_humidity'] ?? 25)));
        if ($user_id <= 0) {
            sendResponse('error', 'No mapped account found for this prototype.');
        }
        try {
            $dbh->prepare("UPDATE drying_sessions SET status='Interrupted', end_time=NOW() WHERE user_id=:uid AND status='Running'")->execute([':uid'=>$user_id]);
            $stmt = $dbh->prepare("INSERT INTO drying_sessions (user_id,set_temp,set_humidity,status,start_time) VALUES(:uid,:t,:h,'Running',NOW())");
            $stmt->execute([':uid'=>$user_id,':t'=>$set_temp,':h'=>$set_hum]);
            $session_id = $dbh->lastInsertId();
            // Sync drying_controls — clear any leftover cooldown
            $dbh->prepare("UPDATE drying_controls SET target_temp=:t,target_humidity=:h,status='RUNNING',start_time=NOW(),cooldown_until=NULL WHERE id=1")
                ->execute([':t'=>$set_temp,':h'=>$set_hum]);
            sendResponse('success','Prototype drying session started.',[
                'session_id'   => (int)$session_id,
                'set_temp'     => $set_temp,
                'set_humidity' => $set_hum,
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
        $session_id        = intval($_POST['session_id'] ?? 0);
        $recorded_temp     = floatval($_POST['temp'] ?? 0);
        $recorded_humidity = floatval($_POST['humidity'] ?? 0);

        $sessStmt = $dbh->prepare("SELECT set_temp,set_humidity FROM drying_sessions WHERE session_id=:sid AND status='Running' LIMIT 1");
        $sessStmt->execute([':sid'=>$session_id]);
        $session = $sessStmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) sendResponse('error','No active session with ID '.$session_id);

        $target_temp = floatval($session['set_temp']);
        $target_hum  = floatval($session['set_humidity']);

        $OVERHEAT_THRESHOLD  = $target_temp + 2;
        $CRITICAL_THRESHOLD  = $target_temp + 8;
        $AUTOSTOP_THRESHOLD  = $target_temp + 15;
        $UNDERHEAT_THRESHOLD = $target_temp - 1;

        $heater_state  = 0;
        $exhaust_state = 0;
        $fan_state     = 0;
        $phase         = 'Idle';
        $alert         = null;
        $auto_stopped  = false;
        $fish_ready    = false;

        if ($recorded_temp >= $AUTOSTOP_THRESHOLD) {
            $dbh->prepare("UPDATE drying_sessions SET status='Interrupted',end_time=NOW() WHERE session_id=:sid")->execute([':sid'=>$session_id]);
            $dbh->query("UPDATE drying_controls SET status='STOPPED',start_time=NULL,cooldown_until=NULL WHERE id=1");
            $phase = 'Exhaust'; $exhaust_state = 1; $auto_stopped = true;
            $alert = ['level'=>'emergency','title'=>'🚨 EMERGENCY AUTO-STOP','message'=>"Temp {$recorded_temp}°C — System auto-stopped!",'temp'=>$recorded_temp,'target'=>$target_temp];
        } elseif ($recorded_temp >= $CRITICAL_THRESHOLD) {
            $exhaust_state = 1; $phase = 'Exhaust';
            $alert = ['level'=>'critical','title'=>'⚠️ CRITICAL OVERHEAT','message'=>"Temp {$recorded_temp}°C critical!",'temp'=>$recorded_temp,'target'=>$target_temp];
        } elseif ($recorded_temp >= $OVERHEAT_THRESHOLD) {
            $exhaust_state = 1; $phase = 'Exhaust';
            $alert = ['level'=>'warning','title'=>'🌡️ Overheating','message'=>"Temp {$recorded_temp}°C exceeds target {$target_temp}°C.",'temp'=>$recorded_temp,'target'=>$target_temp];
        } elseif ($recorded_temp <= $UNDERHEAT_THRESHOLD) {
            // FAN is the heat source — ON to heat up
            $fan_state = 1; $phase = 'Heating';
        } else {
            // At target range — fan still ON for airflow/drying circulation
            $fan_state = 1; $phase = 'Drying';
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
                ':heater' => $heater_state,
                ':exhaust'=> $exhaust_state,
                ':fan'    => $fan_state,
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
            'phase'        => $phase,
            'heater'       => $heater_state,
            'exhaust'      => $exhaust_state,
            'fan'          => $fan_state,
            'temp'         => $recorded_temp,
            'humidity'     => $recorded_humidity,
            'auto_stopped' => $auto_stopped,
            'fish_ready'   => $fish_ready,
            'alert'        => $alert,
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
                if ($is_admin) {
                    $stmt = $dbh->query("SELECT session_id,status,set_temp,set_humidity FROM drying_sessions WHERE status='Running' ORDER BY start_time DESC LIMIT 1");
                } elseif ($user_id > 0) {
                    $stmt = $dbh->prepare("SELECT session_id,status,set_temp,set_humidity FROM drying_sessions WHERE user_id=:uid AND status='Running' ORDER BY start_time DESC LIMIT 1");
                    $stmt->execute([':uid'=>$user_id]);
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
            if ($user_id > 0) {
                $stmt = $dbh->prepare(
                    "SELECT session_id, set_temp, set_humidity, start_time
                     FROM drying_sessions
                     WHERE user_id = :uid AND status = 'Running'
                     ORDER BY start_time DESC LIMIT 1"
                );
                $stmt->execute([':uid' => $user_id]);
            } else {
                $stmt = $dbh->query(
                    "SELECT session_id, set_temp, set_humidity, start_time
                     FROM drying_sessions
                     WHERE status = 'Running'
                     ORDER BY start_time DESC LIMIT 1"
                );
            }
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                sendResponse('error', 'No active session found.');
            }

            $sid = $session['session_id'];

            // ── Fetch controls row for cooldown state ──────────────
            $ctrl = $dbh->query("SELECT status AS ctrl_status, cooldown_until FROM drying_controls WHERE id=1")->fetch(PDO::FETCH_ASSOC);
            $ctrl_status        = $ctrl['ctrl_status']    ?? 'RUNNING';
            $cooldown_until     = $ctrl['cooldown_until'] ?? null;
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
            $log['recent_logs'] = array_reverse($recentWindow->fetchAll(PDO::FETCH_ASSOC));

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
            $isOnline = $isActive && $secondsSinceSeen <= 20;

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
            $stmt = $dbh->prepare(
                "SELECT ds.session_id, ds.start_time, ds.end_time, ds.set_temp, ds.set_humidity, ds.status,
                    TIMEDIFF(COALESCE(ds.end_time,NOW()),ds.start_time) AS duration,
                    ROUND(AVG(dl.recorded_temp),2)     AS avg_temp,
                    ROUND(AVG(dl.recorded_humidity),2) AS avg_hum
                 FROM drying_sessions ds
                 LEFT JOIN drying_logs dl ON dl.session_id=ds.session_id
                 WHERE ds.user_id=:uid
                 GROUP BY ds.session_id
                 ORDER BY ds.start_time DESC"
            );
            $stmt->execute([':uid'=>$user_id]);
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

    default:
        sendResponse('error','Invalid action: '.$action);
}
?>