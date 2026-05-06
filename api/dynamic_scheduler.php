<?php
// ============================================================
//  DYNAMIC SESSION VALIDATOR - No task scheduler needed
//  This function is called on every API request to maintain session state
//  based on current time vs scheduled times in the database
// ============================================================

function validateScheduledSessions($dbh) {
    try {
        $now = date('Y-m-d H:i:s');
        
        // 1. CHECK FOR SCHEDULES THAT SHOULD START NOW
        // Note: Using first active prototype since this is a single-device system
        $startCheck = $dbh->prepare("
            SELECT bs.id, bs.user_id, bs.title, bs.sched_date, bs.sched_time,
                   bs.set_temp, bs.set_humidity, bs.duration_hours, COALESCE(bs.fish_count, 0) AS fish_count
            FROM batch_schedules bs
            WHERE bs.status = 'Scheduled' 
            AND CONCAT(bs.sched_date, ' ', bs.sched_time) <= :now
            AND (bs.last_checked IS NULL OR bs.last_checked < DATE_SUB(:now2, INTERVAL 1 MINUTE))
        ");
        $startCheck->execute([':now' => $now, ':now2' => $now]);
        
        // Get the first active prototype
        $protoStmt = $dbh->query("SELECT id FROM tbl_prototypes WHERE status = 1 ORDER BY id ASC LIMIT 1");
        $defaultProto = $protoStmt->fetch(PDO::FETCH_ASSOC);
        $protoId = $defaultProto ? (int)$defaultProto['id'] : 1;
        
        foreach ($startCheck->fetchAll(PDO::FETCH_ASSOC) as $schedule) {
            // Check if prototype is online using live_sensor_cache timestamp
            if (isPrototypeOnline($dbh, $protoId)) {
                // Check if there's no running session already
                $activeCheck = $dbh->prepare(
                    "SELECT session_id FROM drying_sessions 
                     WHERE status = 'Running' LIMIT 1"
                );
                $activeCheck->execute();
                
                if (!$activeCheck->fetch()) {
                    $schedule['proto_id'] = $protoId;
                    startScheduledSession($dbh, $schedule);
                }
            }
            
            // Update last_checked timestamp
            $dbh->prepare("UPDATE batch_schedules SET last_checked = :now WHERE id = :id")
                ->execute([':now' => $now, ':id' => $schedule['id']]);
        }
        
        // 2. CHECK FOR SCHEDULES THAT SHOULD END NOW
        $endCheck = $dbh->prepare("
            SELECT ds.session_id, ds.schedule_id, bs.duration_hours, ds.start_time
            FROM drying_sessions ds
            JOIN batch_schedules bs ON ds.schedule_id = bs.id
            WHERE ds.status = 'Running' 
            AND bs.status = 'Running'
            AND TIMESTAMPDIFF(HOUR, ds.start_time, :now) >= bs.duration_hours
        ");
        $endCheck->execute([':now' => $now]);
        
        foreach ($endCheck->fetchAll(PDO::FETCH_ASSOC) as $session) {
            stopScheduledSession($dbh, $session);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Dynamic Session Validator Error: " . $e->getMessage());
        return false;
    }
}

function isPrototypeOnline($dbh, $proto_id) {
    if (!$proto_id) return false;
    
    try {
        // Check live_sensor_cache timestamp - more reliable than prototype updated_at
        $stmt = $dbh->query(
            "SELECT TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS age_seconds
             FROM live_sensor_cache WHERE id = 1 LIMIT 1"
        );
        $cache = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cache) {
            $age = (int)($cache['age_seconds'] ?? 999999);
            return $age >= 0 && $age < 30;
        }
        
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function startScheduledSession($dbh, $schedule) {
    try {
        $dbh->beginTransaction();
        
        // 1. End any existing running sessions for this user
        $dbh->prepare("
            UPDATE drying_sessions 
            SET status='Interrupted', end_time=NOW() 
            WHERE user_id=:uid AND status='Running'
        ")->execute([':uid' => $schedule['user_id']]);
        
        // 2. Create new session
        $sessionStmt = $dbh->prepare("
            INSERT INTO drying_sessions (user_id, proto_id, set_temp, set_humidity, fish_count, status, start_time, schedule_id)
            VALUES (:uid, :pid, :temp, :hum, :fc, 'Running', NOW(), :sched_id)
        ");
        $sessionStmt->execute([
            ':uid' => $schedule['user_id'],
            ':pid' => $schedule['proto_id'] ?? null,
            ':temp' => $schedule['set_temp'],
            ':hum' => $schedule['set_humidity'],
            ':fc' => (int)($schedule['fish_count'] ?? 0),
            ':sched_id' => $schedule['id']
        ]);
        
        // 3. Update drying_controls
        $dbh->prepare("
            UPDATE drying_controls 
            SET target_temp=:temp, target_humidity=:hum, status='RUNNING', 
                start_time=NOW(), cooldown_until=NULL 
            WHERE id=1
        ")->execute([':temp' => $schedule['set_temp'], ':hum' => $schedule['set_humidity']]);
        
        // 4. Mark schedule as running and auto-started
        $dbh->prepare("
            UPDATE batch_schedules 
            SET status='Running', auto_started=1 
            WHERE id=:sid
        ")->execute([':sid' => $schedule['id']]);
        
        $dbh->commit();
        
        error_log("Dynamic Scheduler: Auto-started schedule #{$schedule['id']} '{$schedule['title']}'");
        return true;
        
    } catch (Exception $e) {
        $dbh->rollback();
        error_log("Failed to auto-start schedule #{$schedule['id']}: " . $e->getMessage());
        return false;
    }
}

function stopScheduledSession($dbh, $session) {
    try {
        $dbh->beginTransaction();
        
        // 1. End the session
        $dbh->prepare("
            UPDATE drying_sessions 
            SET status='Completed', end_time=NOW() 
            WHERE session_id=:sid
        ")->execute([':sid' => $session['session_id']]);
        
        // 2. Stop drying controls
        $dbh->query("
            UPDATE drying_controls 
            SET status='STOPPED', start_time=NULL, cooldown_until=NULL 
            WHERE id=1
        ");
        
        // 3. Mark schedule as done
        $dbh->prepare("
            UPDATE batch_schedules 
            SET status='Done' 
            WHERE id=:sid
        ")->execute([':sid' => $session['schedule_id']]);
        
        $dbh->commit();
        
        error_log("Dynamic Scheduler: Auto-stopped session #{$session['session_id']} (duration completed)");
        return true;
        
    } catch (Exception $e) {
        $dbh->rollback();
        error_log("Failed to auto-stop session #{$session['session_id']}: " . $e->getMessage());
        return false;
    }
}

// Helper function to get current session status including scheduled sessions
function getCurrentSessionStatus($dbh, $user_id) {
    try {
        // First, validate scheduled sessions
        validateScheduledSessions($dbh);
        
        // Then return current status
        if ($user_id > 0) {
            $stmt = $dbh->prepare(
                "SELECT ds.session_id, ds.set_temp, ds.set_humidity, ds.start_time, ds.schedule_id,
                        bs.title AS schedule_title, bs.sched_date, bs.sched_time, bs.duration_hours, bs.auto_started
                 FROM drying_sessions ds
                 LEFT JOIN batch_schedules bs ON ds.schedule_id = bs.id
                 WHERE ds.user_id = :uid AND ds.status = 'Running'
                 ORDER BY ds.start_time DESC LIMIT 1"
            );
            $stmt->execute([':uid' => $user_id]);
        } else {
            $stmt = $dbh->query(
                "SELECT ds.session_id, ds.set_temp, ds.set_humidity, ds.start_time, ds.schedule_id,
                        bs.title AS schedule_title, bs.sched_date, bs.sched_time, bs.duration_hours, bs.auto_started
                 FROM drying_sessions ds
                 LEFT JOIN batch_schedules bs ON ds.schedule_id = bs.id
                 WHERE ds.status = 'Running'
                 ORDER BY ds.start_time DESC LIMIT 1"
            );
        }
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("getCurrentSessionStatus Error: " . $e->getMessage());
        return false;
    }
}
?>