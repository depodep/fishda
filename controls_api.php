<?php
// ============================================================
//  controls_api.php
//  Handles drying control actions from the web dashboard.
//  Also provides fetch_controls for the ESP8266 (no auth needed).
//  Added: get_live_sensor — returns the latest sensor_readings row
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

function resolveControlUserIdFromPrototype($dbh, $proto_id) {
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

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$is_admin = isset($_SESSION['sid']) && (($_SESSION['permission'] ?? 'user') === 'admin');
$proto_id = intval($_SESSION['proto_id'] ?? 0);
$resolved_user_id = $is_admin
    ? intval($_SESSION['sid'] ?? 0)
    : resolveControlUserIdFromPrototype($dbh, $proto_id);

// Actions that require a logged-in user
$auth_required = ['set_targets', 'stop_drying'];
if (in_array($action, $auth_required) && !$is_admin && !$proto_id) {
    sendResponse('error', 'Unauthorized. Please login.');
}

switch ($action) {

    // ── Start drying from dashboard controls panel ────────────
    case 'set_targets':
        $t = floatval($_POST['target_temp'] ?? 50);
        $h = floatval($_POST['target_hum']  ?? 30);
        $uid = $resolved_user_id;
        if ($uid <= 0) {
            sendResponse('error', 'No mapped account found for this prototype.');
        }

        try {
            // Close any running sessions for this user
            $dbh->prepare(
                "UPDATE drying_sessions SET status='Interrupted', end_time=NOW()
                 WHERE user_id=:uid AND status='Running'"
            )->execute([':uid' => $uid]);

            // Create new session
            $dbh->prepare(
                "INSERT INTO drying_sessions (user_id, set_temp, set_humidity, status, start_time)
                 VALUES (:uid, :t, :h, 'Running', NOW())"
            )->execute([':uid' => $uid, ':t' => $t, ':h' => $h]);
            $session_id = $dbh->lastInsertId();

            // Sync drying_controls row
            $dbh->prepare(
                "UPDATE drying_controls SET target_temp=:t, target_humidity=:h,
                 status='RUNNING', start_time=NOW() WHERE id=1"
            )->execute([':t' => $t, ':h' => $h]);

            sendResponse('success', 'Drying started.', [
                'session_id' => (int)$session_id,
                'status'     => 'RUNNING',
                'target_temp'=> $t,
                'target_hum' => $h,
            ]);
        } catch (Exception $e) {
            sendResponse('error', 'Failed: ' . $e->getMessage());
        }
        break;

    // ── Stop drying from dashboard ────────────────────────────
    case 'stop_drying':
        $uid = $resolved_user_id;
        if (!$is_admin && $uid <= 0) {
            sendResponse('error', 'No mapped account found for this prototype.');
        }
        try {
            if ($is_admin) {
                // Admin stops whichever session is running
                $res = $dbh->query(
                    "UPDATE drying_sessions SET status='Completed', end_time=NOW() WHERE status='Running'"
                );
            } else {
                $res = $dbh->prepare(
                    "UPDATE drying_sessions SET status='Completed', end_time=NOW()
                     WHERE user_id=:uid AND status='Running'"
                );
                $res->execute([':uid' => $uid]);
            }
            $dbh->query("UPDATE drying_controls SET status='STOPPED', start_time=NULL WHERE id=1");
            sendResponse('success', 'Drying stopped.');
        } catch (Exception $e) {
            sendResponse('error', 'Stop failed: ' . $e->getMessage());
        }
        break;

    // ── ESP8266 polls this to know targets / start status ─────
    case 'fetch_controls':
        try {
            $row = $dbh->query(
                "SELECT status, target_temp, target_humidity FROM drying_controls WHERE id=1"
            )->fetch(PDO::FETCH_ASSOC);
            sendResponse('success', 'Controls fetched.', $row);
        } catch (Exception $e) {
            sendResponse('error', 'Failed.');
        }
        break;

    // ── Dashboard polls this for live gauge display ────────────
    case 'get_live_sensor':
        try {
            $row = $dbh->query(
                "SELECT temperature, humidity, session_id, timestamp
                 FROM sensor_readings ORDER BY timestamp DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                sendResponse('success', 'No readings yet.', [
                    'temperature' => null,
                    'humidity'    => null,
                    'session_id'  => null,
                    'timestamp'   => null,
                ]);
            }

            // Also get active session info
            $sess = $dbh->query(
                "SELECT session_id, set_temp, set_humidity, status
                 FROM drying_sessions WHERE status='Running' ORDER BY start_time DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            sendResponse('success', 'Live sensor data.', [
                'temperature'  => floatval($row['temperature']),
                'humidity'     => floatval($row['humidity']),
                'timestamp'    => $row['timestamp'],
                'session_id'   => $row['session_id'],
                'session'      => $sess ?: null,
            ]);
        } catch (Exception $e) {
            sendResponse('error', 'Failed: ' . $e->getMessage());
        }
        break;

    // ── Get last N sensor readings (for chart on dashboard) ───
    case 'get_sensor_history':
        $limit = min(100, intval($_GET['limit'] ?? 20));
        $sid   = intval($_GET['session_id'] ?? 0);
        try {
            if ($sid > 0) {
                $stmt = $dbh->prepare(
                    "SELECT temperature, humidity, timestamp
                     FROM sensor_readings WHERE session_id=:sid
                     ORDER BY timestamp DESC LIMIT :lim"
                );
                $stmt->bindValue(':sid', $sid, PDO::PARAM_INT);
            } else {
                $stmt = $dbh->prepare(
                    "SELECT temperature, humidity, timestamp
                     FROM sensor_readings ORDER BY timestamp DESC LIMIT :lim"
                );
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
            sendResponse('success', 'History fetched.', $rows);
        } catch (Exception $e) {
            sendResponse('error', 'Failed: ' . $e->getMessage());
        }
        break;

    default:
        sendResponse('error', 'Invalid action: ' . htmlspecialchars($action ?? ''));
}
?>