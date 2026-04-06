<?php
// ============================================================
//  sensor_api.php  — UPDATED v2
//  Changes:
//   1. FAN = heat source → ON during Heating phase (not just cooldown)
//   2. After target reached: 5-min COOLDOWN phase (exhaust+fan OFF, session stays open)
//   3. After cooldown ends: session auto-restarts (fan back ON to re-heat)
//   4. drying_controls gains 'COOLDOWN' status tracked via cooldown_until column
//   5. REAL-TIME DISPLAY: Updates live_sensor_cache FIRST for instant display
//   6. CONDITIONAL STORAGE: Stores to sensor_readings/drying_logs ONLY when session is active
// ============================================================
ini_set('display_errors', 0);
error_reporting(0);
if (ob_get_level()) ob_clean();
header('Content-Type: application/json');

require_once '../database/dbcon.php';

// ── Add cooldown_until column if it doesn't exist yet ────────
// Run this once; safe to leave in (ALTER IGNORE not needed — caught by try/catch)
try {
    $dbh->exec("ALTER TABLE drying_controls ADD COLUMN IF NOT EXISTS cooldown_until DATETIME DEFAULT NULL");
} catch (Exception $e) { /* column may already exist */ }

function sendResponse($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

// --- Accept POST or GET ---
$temp     = floatval($_POST['temp']     ?? $_GET['temp']     ?? 0);
$humidity = floatval($_POST['humidity'] ?? $_GET['humidity'] ?? 0);

if ($temp <= 0 || $humidity <= 0) {
    sendResponse('error', 'Invalid sensor values.');
}

// ── 1. Update live cache FIRST (for instant display) ─────────
try {
    // Update prototype heartbeat — keeps device marked as ONLINE
    $dbh->query("UPDATE tbl_prototypes SET updated_at=NOW() WHERE id=1");

    // Always update the live cache — this is what dashboard displays
    $dbh->prepare(
        "UPDATE live_sensor_cache
         SET temperature = :t, humidity = :h,
             fan1_state = :f1, fan2_state = :f2,
             heater1_state = :h1, heater2_state = :h2,
             exhaust_state = :e, phase = :p,
             timestamp = NOW()
         WHERE id = 1"
    )->execute([
        ':t' => $temp, ':h' => $humidity,
        ':f1' => $fan1_state, ':f2' => $fan2_state,
        ':h1' => $heater1_state, ':h2' => $heater2_state,
        ':e' => $exhaust_state, ':p' => $phase
    ]);
} catch (Exception $e) {
    // Non-fatal — continue to check session
}

// ── 2. Check for active session ───────────────────────────────
try {
    $sessStmt = $dbh->query(
        "SELECT ds.session_id, ds.set_temp, ds.set_humidity, ds.start_time, ds.schedule_id,
                bs.duration_hours,
                TIMESTAMPDIFF(MINUTE, ds.start_time, NOW()) AS elapsed_minutes
         FROM drying_sessions ds
         LEFT JOIN batch_schedules bs ON ds.schedule_id = bs.id
         WHERE ds.status='Running'
         ORDER BY ds.start_time DESC LIMIT 1"
    );
    $activeSession = $sessStmt->fetch(PDO::FETCH_ASSOC);
    $session_id    = $activeSession ? (int)$activeSession['session_id'] : null;

    // ── 3. Save to sensor_readings ONLY if session is active ─────
    if ($activeSession) {
        $dbh->prepare(
            "INSERT INTO sensor_readings (temperature, humidity, session_id, timestamp)
             VALUES (:t, :h, :sid, NOW())"
        )->execute([':t' => $temp, ':h' => $humidity, ':sid' => $session_id]);
    }

} catch (Exception $e) {
    sendResponse('error', 'DB operation failed: ' . $e->getMessage());
}

// ── 2b. If session is running & scheduled, auto-stop when duration reached ──
if ($activeSession && !empty($activeSession['schedule_id']) && $activeSession['duration_hours'] !== null) {
    $elapsedMinutes  = (int)($activeSession['elapsed_minutes'] ?? 0);
    $durationMinutes = (int)round(floatval($activeSession['duration_hours']) * 60);

    if ($durationMinutes > 0 && $elapsedMinutes >= $durationMinutes) {
        try {
            $dbh->beginTransaction();

            // 1. Mark session as completed
            $stopStmt = $dbh->prepare("UPDATE drying_sessions SET status='Completed', end_time=NOW() WHERE session_id=:sid");
            $stopStmt->execute([':sid' => $session_id]);

            // 2. Mark schedule as done
            $schedUpd = $dbh->prepare("UPDATE batch_schedules SET status='Done', last_checked=NOW() WHERE id=:sid");
            $schedUpd->execute([':sid' => $activeSession['schedule_id']]);

            // 3. Reset drying_controls to IDLE
            $ctrlUpd = $dbh->prepare("UPDATE drying_controls SET status='IDLE', cooldown_until=NULL WHERE id=1");
            $ctrlUpd->execute();

            $dbh->commit();
        } catch (Exception $e) {
            $dbh->rollBack();
            error_log('Auto-stop schedule failed: ' . $e->getMessage());
        }

        // Tell device to stop — session finished by duration
        sendResponse('success', '⏹ Scheduled duration reached — session auto-stopped.', [
            'command'      => 'STOP',
            'heater'       => 0,
            'heater1'      => 0,
            'heater2'      => 0,
            'exhaust'      => 0,
            'fan'          => 0,
            'fan1'         => 0,
            'fan2'         => 0,
            'phase'        => 'Completed',
            'session_id'   => $session_id,
            'target_temp'  => floatval($activeSession['set_temp']),
            'target_hum'   => floatval($activeSession['set_humidity']),
            'duration_hours' => floatval($activeSession['duration_hours']),
            'auto_stopped' => true,
        ]);
    }
}

// ── 4. No active session → Check for scheduled batch to auto-start ──────
if (!$activeSession) {
    // Check if there's a scheduled batch that should start now
    try {
        $schedCheck = $dbh->query("
            SELECT id, user_id, title, sched_date, sched_time, 
                   set_temp, set_humidity, duration_hours, notes
            FROM batch_schedules
            WHERE status = 'Scheduled'
            AND CONCAT(sched_date, ' ', sched_time) <= NOW()
            ORDER BY CONCAT(sched_date, ' ', sched_time) ASC
            LIMIT 1
        ");
        $pendingSchedule = $schedCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($pendingSchedule) {
            // Auto-start this scheduled session!
            $dbh->beginTransaction();
            
            // 1. Create new drying session
            $sessionStmt = $dbh->prepare("
                INSERT INTO drying_sessions (user_id, set_temp, set_humidity, status, start_time, schedule_id, notes)
                VALUES (:uid, :temp, :hum, 'Running', NOW(), :sched_id, :notes)
            ");
            $sessionStmt->execute([
                ':uid' => $pendingSchedule['user_id'],
                ':temp' => $pendingSchedule['set_temp'],
                ':hum' => $pendingSchedule['set_humidity'],
                ':sched_id' => $pendingSchedule['id'],
                ':notes' => 'Auto-started from schedule: ' . $pendingSchedule['title']
            ]);
            $newSessionId = $dbh->lastInsertId();
            
            // 2. Update drying_controls with target parameters
            $dbh->prepare("
                UPDATE drying_controls 
                SET target_temp = :temp, target_humidity = :hum, 
                    status = 'RUNNING', start_time = NOW(), cooldown_until = NULL
                WHERE id = 1
            ")->execute([
                ':temp' => $pendingSchedule['set_temp'],
                ':hum' => $pendingSchedule['set_humidity']
            ]);
            
            // 3. Mark schedule as Running
            $dbh->prepare("
                UPDATE batch_schedules 
                SET status = 'Running', last_checked = NOW(), auto_started = 1
                WHERE id = :sid
            ")->execute([':sid' => $pendingSchedule['id']]);
            
            $dbh->commit();
            
            // Return RUN command to Arduino
            sendResponse('success', '✅ Auto-started scheduled session: ' . $pendingSchedule['title'], [
                'command'      => 'RUN',
                'heater'       => 1,
                'heater1'      => 1,
                'heater2'      => 1,
                'exhaust'      => 0,
                'fan'          => 1,
                'fan1'         => 1,
                'fan2'         => 1,
                'phase'        => 'Heating',
                'session_id'   => $newSessionId,
                'target_temp'  => floatval($pendingSchedule['set_temp']),
                'target_hum'   => floatval($pendingSchedule['set_humidity']),
                'duration_hours' => floatval($pendingSchedule['duration_hours']),
                'auto_started' => true,
                'schedule_title' => $pendingSchedule['title']
            ]);
        }
    } catch (Exception $e) {
        // Schedule check failed - continue with idle response
        error_log("Schedule auto-start error: " . $e->getMessage());
    }
    
    // No active session and no pending schedule
    sendResponse('success', '✅ Device online (heartbeat received) - No active session. Standby.', [
        'command'      => 'STOP',
        'heater'       => 0,
        'exhaust'      => 0,
        'fan'          => 0,
        'phase'        => 'Idle',
        'session_id'   => 0,
        'target_temp'  => 0,
        'target_hum'   => 0,
    ]);
}

// ── 5. Session is Running — get controls row ──────────────────
$target_temp = floatval($activeSession['set_temp']);
$target_hum  = floatval($activeSession['set_humidity']);
$sid         = (int)$activeSession['session_id'];

// Fetch the drying_controls row (has cooldown_until)
$ctrlRow = $dbh->query("SELECT status, cooldown_until FROM drying_controls WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$ctrl_status      = $ctrlRow['status']         ?? 'RUNNING';
$cooldown_until   = $ctrlRow['cooldown_until'] ?? null;

// ── 4. Check if we are in COOLDOWN phase ─────────────────────
$now_ts = time();

if ($ctrl_status === 'COOLDOWN') {
    $cooldown_remaining = 0;
    if ($cooldown_until) {
        $cooldown_ts = strtotime($cooldown_until);
        if ($cooldown_ts !== false) {
            $cooldown_remaining = max(0, $cooldown_ts - $now_ts);
        }
    }

    // While still inside the minimum cooldown window OR temperature is still above target,
    // keep everything OFF and do not resume heating.
    if ($cooldown_remaining > 0 || $temp > $target_temp) {
        // Log cooldown reading
        try {
            $dbh->prepare(
                "INSERT INTO drying_logs
                 (session_id, recorded_temp, recorded_humidity, heater_state, exhaust_state, fan_state, phase, timestamp)
                 VALUES (:sid, :temp, :hum, 0, 0, 0, 'Cooldown', NOW())"
            )->execute([':sid' => $sid, ':temp' => $temp, ':hum' => $humidity]);
        } catch (Exception $e) {}

        sendResponse('success', '✅ Device online (heartbeat received) - Cooldown phase.', [
            'command'            => 'COOLDOWN',
            'heater'             => 0,
            'exhaust'            => 0,
            'fan'                => 0,

            // Dual device controls - ALL OFF during cooldown
            'heater1'            => 0,
            'heater2'            => 0,
            'fan1'               => 0,
            'fan2'               => 0,

            'phase'              => 'Cooldown',
            'session_id'         => $sid,
            'target_temp'        => $target_temp,
            'target_hum'         => $target_hum,
            'cooldown_remaining' => $cooldown_remaining,
            'auto_stopped'       => false,
            'fish_ready'         => false,
            'alert'              => null,
        ]);
    }

    // Cooldown time has finished AND temperature has dropped back to/below the set point
    // → allow normal control to resume.
    try {
        $dbh->prepare(
            "UPDATE drying_controls SET status='RUNNING', cooldown_until=NULL WHERE id=1"
        )->execute();
    } catch (Exception $e) {
        // Non-fatal — device will still follow temperature thresholds below
    }
    $ctrl_status = 'RUNNING';
}

// ── 5. Normal control thresholds ─────────────────────────────
$OVERHEAT_THRESHOLD  = $target_temp + 2;
$CRITICAL_THRESHOLD  = $target_temp + 8;
$AUTOSTOP_THRESHOLD  = $target_temp + 15;
$UNDERHEAT_THRESHOLD = $target_temp - 1;

$heater_state  = 0;
$exhaust_state = 0;
$fan_state     = 0;

// Dual device variables
$heater1_state = 0;
$heater2_state = 0;
$fan1_state    = 0;
$fan2_state    = 0;

$phase         = 'Idle';
$alert         = null;
$auto_stopped  = false;
$fish_ready    = false;
$command       = 'RUN';

// ── 6. Control logic ─────────────────────────────────────────
if ($temp >= $AUTOSTOP_THRESHOLD) {
    // Emergency auto-stop
    $dbh->prepare(
        "UPDATE drying_sessions SET status='Interrupted', end_time=NOW() WHERE session_id=:sid"
    )->execute([':sid' => $sid]);
    $dbh->query("UPDATE drying_controls SET status='STOPPED', start_time=NULL, cooldown_until=NULL WHERE id=1");

    _saveRecord($dbh, $sid, 'AutoStopped');

    $phase = 'Exhaust'; 
    $exhaust_state = 1; 
    // Turn off all heating devices immediately
    $fan1_state = 0; $fan2_state = 0;
    $heater1_state = 0; $heater2_state = 0;
    $auto_stopped = true; 
    $command = 'STOP';
    $alert = [
        'level'   => 'emergency',
        'title'   => 'EMERGENCY AUTO-STOP',
        'message' => "Temp {$temp}°C exceeded safety limit! System stopped.",
    ];

 } elseif ($temp >= $CRITICAL_THRESHOLD) {
    // Critical overheat — exhaust only, all heating devices OFF
    $exhaust_state = 1; 
    $fan1_state = 0; $fan2_state = 0;
    $heater1_state = 0; $heater2_state = 0;
    $phase = 'Exhaust';
    $alert = ['level' => 'critical', 'title' => 'CRITICAL OVERHEAT', 'message' => "Temp {$temp}°C is critical!"];

} elseif ($temp >= $OVERHEAT_THRESHOLD) {
    // Mild overheat — exhaust, fans OFF
    $exhaust_state = 1; 
    $fan1_state = 0; $fan2_state = 0;
    $heater1_state = 0; $heater2_state = 0;
    $phase = 'Exhaust';
    $alert = ['level' => 'warning', 'title' => 'Overheating', 'message' => "Temp {$temp}°C exceeds target {$target_temp}°C."];

} elseif ($temp <= $UNDERHEAT_THRESHOLD) {
    // ── HEATING: Both fans + heaters ON for maximum heat ──────
    $fan1_state = 1; $fan2_state = 1;     // Both fans for circulation
    $heater1_state = 1; $heater2_state = 1; // Both heaters for heat
    $exhaust_state = 0;
    $phase = 'Heating';

} else {
    // Temperature in target range — maintain with gentle circulation
    $fan1_state = 1; $fan2_state = 0;     // One fan for gentle airflow
    $heater1_state = 0; $heater2_state = 0; // No heating needed
    $exhaust_state = 0;
    $phase = 'Drying';

    // ── Check if TEMPERATURE target reached — trigger COOLDOWN ──────────
    // Temperature usually stabilizes first; humidity may take longer
    if ($temp >= $target_temp - 1 && $temp <= $target_temp + 1) {
        // Temperature reached! Start 5-minute cooldown
        $cooldown_until = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        $dbh->prepare("UPDATE drying_controls SET status='COOLDOWN', cooldown_until=:until WHERE id=1")
            ->execute([':until' => $cooldown_until]);

        $phase = 'Cooldown';
        $fan1_state = 0;
        $fan2_state = 0;
        $command = 'COOLDOWN';
    }
}

// Update legacy variables for backward compatibility
$fan_state = ($fan1_state || $fan2_state) ? 1 : 0;
$heater_state = ($heater1_state || $heater2_state) ? 1 : 0;

// ── 7. Log the reading into drying_logs ──────────────────────
if (!$auto_stopped) {
    try {
        $dbh->prepare(
            "INSERT INTO drying_logs
             (session_id, recorded_temp, recorded_humidity, heater_state, heater1_state, heater2_state, 
              exhaust_state, fan_state, fan1_state, fan2_state, phase, timestamp)
             VALUES (:sid, :temp, :hum, :heater, :heater1, :heater2, :exhaust, :fan, :fan1, :fan2, :phase, NOW())"
        )->execute([
            ':sid'     => $sid,
            ':temp'    => $temp,
            ':hum'     => $humidity,
            ':heater'  => $heater_state,
            ':heater1' => $heater1_state,
            ':heater2' => $heater2_state,
            ':exhaust' => $exhaust_state,
            ':fan'     => $fan_state,
            ':fan1'    => $fan1_state,
            ':fan2'    => $fan2_state,
            ':phase'   => $phase,
        ]);
    } catch (Exception $e) { /* non-fatal */ }
}

// ── 8. Simple cycling behavior: target temp reached → cooldown ─
// Only check when NOT already in cooldown or auto-stopped
if (!$auto_stopped && $ctrl_status !== 'COOLDOWN') {
    // Simple trigger: when temperature reaches or exceeds target
    if ($temp >= $target_temp) {
        // ── Target reached → enter 5-minute COOLDOWN ──────────
        // Session stays 'Running' — it will restart heating after cooldown
        $cooldown_end = date('Y-m-d H:i:s', $now_ts + 300); // 5 minutes
        
        // Update controls with cycle tracking (if column exists)
        try {
            $dbh->prepare(
                "UPDATE drying_controls SET status='COOLDOWN', cooldown_until=:cu, cycle_count=cycle_count+1, last_cycle_start=NOW() WHERE id=1"
            )->execute([':cu' => $cooldown_end]);
        } catch (Exception $e) {
            // Fallback if cycle_count column doesn't exist
            $dbh->prepare(
                "UPDATE drying_controls SET status='COOLDOWN', cooldown_until=:cu WHERE id=1"
            )->execute([':cu' => $cooldown_end]);
        }

        // Save an intermediate record (not final — session keeps running)
        _saveRecord($dbh, $sid, 'TargetReached');

        // All relays OFF during cooldown
        $fan1_state = 0; $fan2_state = 0; 
        $heater1_state = 0; $heater2_state = 0; 
        $exhaust_state = 0;
        $phase = 'Cooldown';
        $command = 'COOLDOWN';
        $fish_ready = true;

        $alert = [
            'level'   => 'info',
            'title'   => '🎯 Target Reached!',
            'message' => "Temp {$temp}°C reached target {$target_temp}°C — 5-min cooldown started. Will resume heating after.",
        ];
    }
}

sendResponse('success', '✅ Device online (heartbeat received) - Reading processed.', [
    'command'      => $command,
    'heater'       => $heater_state,      // Legacy compatibility
    'exhaust'      => $exhaust_state,
    'fan'          => $fan_state,         // Legacy compatibility
    
    // Dual device controls for new Arduino firmware
    'heater1'      => $heater1_state,
    'heater2'      => $heater2_state,
    'fan1'         => $fan1_state,
    'fan2'         => $fan2_state,
    
    'phase'        => $phase,
    'session_id'   => $sid,
    'target_temp'  => $target_temp,
    'target_hum'   => $target_hum,
    'auto_stopped' => $auto_stopped,
    'fish_ready'   => $fish_ready,
    'alert'        => $alert,
]);

// ── Helper: save summary record when session ends or target hit ─
function _saveRecord($dbh, $sid, $reason) {
    try {
        $sess = $dbh->prepare(
            "SELECT ds.user_id, u.username,
                TIMEDIFF(COALESCE(ds.end_time,NOW()), ds.start_time) AS duration,
                ROUND(AVG(dl.recorded_temp),2)     AS avg_temp,
                ROUND(AVG(dl.recorded_humidity),2) AS avg_hum
             FROM drying_sessions ds
             JOIN tblusers u ON u.id = ds.user_id
             LEFT JOIN drying_logs dl ON dl.session_id = ds.session_id
             WHERE ds.session_id = :sid
             GROUP BY ds.session_id"
        );
        $sess->execute([':sid' => $sid]);
        $s = $sess->fetch(PDO::FETCH_ASSOC);
        if ($s) {
            if ($reason === 'TargetReached') {
                $rec_status = 'Completed & Dried';
            } elseif ($reason === 'AutoStopped') {
                $rec_status = 'AutoStopped';
            } else {
                $rec_status = 'Completed';
            }
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
                ':sid' => $sid,
            ]);
        }
    } catch (Exception $e) { /* non-fatal */ }
}
?>