<?php
// ============================================================
//  admin_sessions.php — Redesigned Admin Dashboard
//  Deep Ocean Professional Theme — Midnight Navy + Arctic Blue
// ============================================================
session_cache_limiter('private_no_expire');
session_start();
include('../database/dbcon.php');

// ── SECURITY: Validate admin session & prevent prototype access ──
if (!isset($_SESSION['username']) || $_SESSION['permission'] !== 'admin') {
  // Clear any stale prototype sessions
  $_SESSION = [];
  header('Location: ../index.php?error=unauthorized');
  exit;
}

if (isset($_SESSION['proto_id']) || isset($_SESSION['model_name'])) {
  // Prototype user trying to access admin dashboard
  header('Location: ../prototype/users_dashboard.php');
  exit;
  exit;
}

$admin = htmlspecialchars($_SESSION['username']);

function normalizePrototypeStatus($raw) {
  if (is_string($raw)) {
    $v = strtolower(trim($raw));
    if ($v === 'active' || $v === 'enabled' || $v === 'enable' || $v === '1' || $v === 'true') {
      return 1;
    }
    if ($v === 'disabled' || $v === 'disable' || $v === 'inactive' || $v === '0' || $v === 'false') {
      return 0;
    }
  }
  return intval($raw) === 1 ? 1 : 0;
}

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $postAction = $_POST['action'];
    switch($postAction) {
      case 'add_prototype':
        $model = trim($_POST['model_name'] ?? '');
        $code  = trim($_POST['given_code'] ?? '');
        $status = normalizePrototypeStatus($_POST['status'] ?? 1);
        if (!$model || !$code) { echo json_encode(['status'=>'error','message'=>'Unit/Model and Model Code are required.']); exit; }
        try {
          $chk = $dbh->prepare("SELECT id FROM tbl_prototypes WHERE model_name=:m AND given_code=:c LIMIT 1");
          $chk->execute([':m'=>$model, ':c'=>$code]);
          if ($chk->fetch()) { echo json_encode(['status'=>'error','message'=>'This Unit/Model and Model Code already exists.']); exit; }
          $dbh->prepare("INSERT INTO tbl_prototypes (model_name,given_code,status) VALUES(:m,:c,:s)")
            ->execute([':m'=>$model, ':c'=>$code, ':s'=>$status]);
          echo json_encode(['status'=>'success','message'=>'Device model registered.']);
        } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
        exit;
      case 'update_prototype':
        $pid   = intval($_POST['proto_id'] ?? 0);
        $model = trim($_POST['model_name'] ?? '');
        $code  = trim($_POST['given_code'] ?? '');
        $status = normalizePrototypeStatus($_POST['status'] ?? 1);
        if ($pid <= 0 || !$model || !$code) { echo json_encode(['status'=>'error','message'=>'Invalid prototype payload.']); exit; }
        try {
          $chk = $dbh->prepare("SELECT id FROM tbl_prototypes WHERE model_name=:m AND given_code=:c AND id<>:id LIMIT 1");
          $chk->execute([':m'=>$model, ':c'=>$code, ':id'=>$pid]);
          if ($chk->fetch()) { echo json_encode(['status'=>'error','message'=>'Another device already uses this Unit/Model + Model Code.']); exit; }
          $upd = $dbh->prepare("UPDATE tbl_prototypes SET model_name=:m,given_code=:c,status=:s WHERE id=:id");
          $upd->execute([':m'=>$model, ':c'=>$code, ':s'=>$status, ':id'=>$pid]);

          if ($upd->rowCount() < 1) {
            $exists = $dbh->prepare("SELECT id FROM tbl_prototypes WHERE id=:id LIMIT 1");
            $exists->execute([':id' => $pid]);
            if (!$exists->fetch(PDO::FETCH_ASSOC)) {
              echo json_encode(['status'=>'error','message'=>'Device model not found.']);
              exit;
            }
          }

          echo json_encode(['status'=>'success','message'=>'Device model updated.','new_status'=>$status]);
        } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
        exit;
      case 'delete_prototype':
        $pid = intval($_POST['proto_id'] ?? 0);
        try {
          $dbh->prepare("DELETE FROM tbl_prototypes WHERE id=:id")->execute([':id'=>$pid]);
          echo json_encode(['status'=>'success','message'=>'Device model deleted.']);
        } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
        exit;
      case 'toggle_prototype_status':
        $pid = intval($_POST['proto_id'] ?? $_POST['id'] ?? 0);
        if ($pid <= 0) { echo json_encode(['status'=>'error','message'=>'Invalid prototype ID.']); exit; }
        try {
          $row = $dbh->prepare("SELECT status FROM tbl_prototypes WHERE id=:id");
          $row->execute([':id'=>$pid]);
          $cur = $row->fetch(PDO::FETCH_ASSOC);
          if (!$cur) { echo json_encode(['status'=>'error','message'=>'Prototype not found.']); exit; }
          $new = (intval($cur['status']) === 1) ? 0 : 1;
          $upd = $dbh->prepare("UPDATE tbl_prototypes SET status=:s WHERE id=:id");
          $upd->execute([':s'=>$new,':id'=>$pid]);
          echo json_encode(['status'=>'success','message'=> $new ? 'Device model enabled.' : 'Device model disabled.','new_status'=>$new]);
        } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
        exit;
      case 'create_schedule_admin':
        echo json_encode(['status'=>'error','message'=>'Schedule management is available only on Prototype Dashboard.']);
        exit;
      case 'update_schedule_admin':
        echo json_encode(['status'=>'error','message'=>'Schedule management is available only on Prototype Dashboard.']);
        exit;
      case 'delete_schedule_admin':
        echo json_encode(['status'=>'error','message'=>'Schedule management is available only on Prototype Dashboard.']);
        exit;
        case 'stop_session':
            $sid = intval($_POST['session_id'] ?? 0);
            try {
                $dbh->prepare("UPDATE drying_sessions SET status='Completed',end_time=NOW() WHERE session_id=:sid AND status='Running'")->execute([':sid'=>$sid]);
                $dbh->query("UPDATE drying_controls SET status='STOPPED',start_time=NULL WHERE id=1");
                echo json_encode(['status'=>'success','message'=>'Session stopped.']);
            } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
            exit;
        case 'mark_inquiry_status':
        $inqId = intval($_POST['inquiry_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'read');
        $allowed = ['pending', 'read', 'replied'];
        if ($inqId <= 0 || !in_array($newStatus, $allowed, true)) {
          echo json_encode(['status' => 'error', 'message' => 'Invalid inquiry update payload.']);
          exit;
        }
        try {
          $dbh->exec(
            "CREATE TABLE IF NOT EXISTS tbl_inquiries (
              id INT AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(150) NOT NULL,
              contact VARCHAR(100) NULL,
              message TEXT NOT NULL,
              status ENUM('pending','read','replied') NOT NULL DEFAULT 'pending',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
          );
          $stmt = $dbh->prepare("UPDATE tbl_inquiries SET status=:st WHERE id=:id");
          $stmt->execute([':st' => $newStatus, ':id' => $inqId]);
          echo json_encode(['status' => 'success', 'message' => 'Inquiry status updated.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
      $dbh->exec("ALTER TABLE batch_schedules ADD COLUMN IF NOT EXISTS proto_id INT NULL AFTER user_id");
    } catch (Exception $e) {}
    switch($_GET['action']){
        case 'get_daily_trends':
            try {
                $stmt = $dbh->query("SELECT DATE(timestamp) AS day, ROUND(AVG(recorded_temp),2) AS avg_temp, ROUND(AVG(recorded_humidity),2) AS avg_hum, COUNT(*) AS readings FROM drying_logs GROUP BY DATE(timestamp) ORDER BY day DESC LIMIT 14");
                echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch(PDOException $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
            break;
        case 'get_session_detail':
            $sid = intval($_GET['session_id']??0);
            try {
        $stmt = $dbh->prepare("SELECT recorded_temp, recorded_humidity, phase, heater_state, exhaust_state, fan_state, timestamp FROM drying_logs WHERE session_id=:sid ORDER BY timestamp ASC");
        $stmt->execute([':sid'=>$sid]);
        echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
      } catch(PDOException $e){ echo json_encode(['status'=>'error']); }
      break;
    case 'get_stats':
      try {
        $stats = $dbh->query("SELECT 
          (SELECT COUNT(*) FROM tbl_prototypes) AS total_devices,
          (SELECT COUNT(*) FROM drying_sessions) AS total_sessions,
          (SELECT COUNT(*) FROM drying_sessions WHERE status='Running') AS active_sessions,
          (SELECT COUNT(*) FROM drying_sessions WHERE status='Completed') AS completed,
          (SELECT COUNT(*) FROM batch_schedules WHERE status='Scheduled') AS pending_schedules,
          (SELECT ROUND(AVG(recorded_temp),1) FROM drying_logs) AS overall_avg_temp"
        )->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'success','data'=>$stats]);
      } catch(PDOException $e){ echo json_encode(['status'=>'error']); }
      break;
    case 'fetch_all_records':
      try {
        $stmt = $dbh->query(
          "SELECT ds.session_id AS id,
              ds.proto_id AS batch_id,
              ds.set_temp,
              ds.set_humidity,
              ds.fish_count,
              TIMEDIFF(COALESCE(ds.end_time, NOW()), ds.start_time) AS duration,
              0 AS energy,
              COALESCE(la.temp_avg, 0) AS temp_avg,
              COALESCE(la.hum_avg, 0) AS hum_avg,
              ds.status,
              COALESCE(ds.end_time, ds.start_time) AS timestamp,
              COALESCE(p.model_name, 'Fishda') AS prototype_model,
              COALESCE(p.given_code, 'FD2026') AS prototype_code,
              COALESCE(CONCAT(p.model_name, ' (', p.given_code, ')'), CONCAT('Prototype #', ds.proto_id), CONCAT('Session #', ds.session_id)) AS prototype_label
           FROM drying_sessions ds
           LEFT JOIN (
             SELECT session_id,
                    ROUND(AVG(recorded_temp), 2) AS temp_avg,
                    ROUND(AVG(recorded_humidity), 2) AS hum_avg
             FROM drying_logs
             GROUP BY session_id
           ) la ON la.session_id = ds.session_id
           LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
           WHERE ds.status <> 'Running'
           ORDER BY COALESCE(ds.end_time, ds.start_time) DESC, ds.session_id DESC"
        );
        echo json_encode(['status'=>'success','records'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
      } catch(PDOException $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
            break;
        case 'get_all_sessions_admin':
            try {
                $stmt = $dbh->query(
          "SELECT ds.session_id, ds.proto_id,
                        ds.start_time, ds.end_time,
                        ds.set_temp, ds.set_humidity, ds.status,
                        TIMEDIFF(COALESCE(ds.end_time,NOW()),ds.start_time) AS duration,
                        ROUND(AVG(dl.recorded_temp),2) AS avg_temp,
                        ROUND(AVG(dl.recorded_humidity),2) AS avg_hum,
            COUNT(dl.log_id) AS total_logs,
              COALESCE(p.model_name, 'Fishda') AS prototype_model,
              COALESCE(p.given_code, 'FD2026') AS prototype_code
           FROM drying_sessions ds
           LEFT JOIN tbl_prototypes p ON p.id=ds.proto_id
                     LEFT JOIN drying_logs dl ON dl.session_id=ds.session_id
                     GROUP BY ds.session_id
                     ORDER BY ds.start_time DESC"
                );
                echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch(PDOException $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
            break;
        case 'get_all_prototypes':
            try {
                $stmt = $dbh->query(
              "SELECT id, model_name, given_code, status
               FROM tbl_prototypes
               ORDER BY id DESC"
                );
                echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch(PDOException $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
            break;
        case 'get_live_alerts':
            try {
                $stmt = $dbh->query(
          "SELECT ds.session_id, ds.proto_id, ds.set_temp,
                        (SELECT recorded_temp FROM drying_logs WHERE session_id=ds.session_id ORDER BY timestamp DESC LIMIT 1) AS latest_temp
                     FROM drying_sessions ds
                     WHERE ds.status='Running'
                     HAVING latest_temp IS NOT NULL AND latest_temp >= (ds.set_temp + 2)"
                );
                echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
      } catch(PDOException $e){ echo json_encode(['status'=>'success','data'=>[]]); }
            break;
        case 'get_calendar_events':
            try {
                $events = [];
                $stmt = $dbh->query(
                    "SELECT bs.id, bs.title, bs.sched_date, bs.sched_time,
            bs.set_temp, bs.set_humidity, bs.notes, bs.status, bs.proto_id,
            CASE
              WHEN bs.status IN ('Done','Cancelled') THEN bs.status
              WHEN bs.sched_date = CURDATE() AND bs.sched_time <= CURTIME() THEN 'Running'
              ELSE 'Scheduled'
            END AS display_status,
              COALESCE(CONCAT(p.model_name, ' (', p.given_code, ')'), CONCAT('Prototype #', bs.proto_id), u.username, 'Unassigned Prototype') AS prototype_label
               FROM batch_schedules bs
             LEFT JOIN tblusers u ON u.id=bs.user_id
               LEFT JOIN tbl_prototypes p ON p.id=bs.proto_id
              WHERE bs.status <> 'Cancelled'
               ORDER BY bs.sched_date ASC"
                );
                $colorMap = ['Scheduled'=>'#0077B6','Running'=>'#f59e0b','Done'=>'#2ec4b6','Cancelled'=>'#ef4444'];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $events[] = [
                        'id'=>'sched_'.$row['id'], 'title'=>'Schedule: '.$row['title'],
                        'start'=>$row['sched_date'].'T'.$row['sched_time'],
                'backgroundColor'=>$colorMap[$row['display_status']]??'#0077B6',
                'borderColor'=>$colorMap[$row['display_status']]??'#0077B6', 'textColor'=>'#ffffff',
                'extendedProps'=>['type'=>'schedule','schedule_id'=>$row['id'],'prototype_label'=>$row['prototype_label'],
                  'proto_id'=>$row['proto_id'],'title'=>$row['title'],'sched_date'=>$row['sched_date'],'sched_time'=>$row['sched_time'],
                  'set_temp'=>$row['set_temp'],'set_humidity'=>$row['set_humidity'],'notes'=>$row['notes'],'status'=>$row['display_status']]
                    ];
                }
                $stmt2 = $dbh->query(
                  "SELECT ds.session_id, ds.start_time, ds.end_time, ds.set_temp, ds.set_humidity, ds.status,
                      COALESCE(CONCAT(p.model_name, ' (', p.given_code, ')'), CONCAT('Prototype #', ds.proto_id)) AS prototype_label
                   FROM drying_sessions ds
                   LEFT JOIN tbl_prototypes p ON p.id=ds.proto_id
                   WHERE ds.status IN ('Completed','Interrupted') ORDER BY ds.start_time DESC LIMIT 200"
                );
                foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $color = $row['status']==='Completed' ? '#2ec4b6' : '#f97316';
                    $events[] = [
                        'id'=>'sess_'.$row['session_id'],
                    'title'=>($row['status']==='Completed'?'Completed: ':'Alert: ').$row['prototype_label'],
                        'start'=>$row['start_time'], 'end'=>$row['end_time'],
                        'backgroundColor'=>$color, 'borderColor'=>$color, 'textColor'=>'#ffffff',
                    'extendedProps'=>['type'=>'session','session_id'=>$row['session_id'],'prototype_label'=>$row['prototype_label'],
                            'set_temp'=>$row['set_temp'],'set_humidity'=>$row['set_humidity'],
                            'status'=>$row['status'],'end_time'=>$row['end_time']]
                    ];
                }
                echo json_encode(['status'=>'success','data'=>$events]);
            } catch(PDOException $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
            break;
        case 'get_all_schedules':
            try {
                $stmt = $dbh->query(
                    "SELECT bs.id, bs.title, bs.sched_date, bs.sched_time,
              bs.set_temp, bs.set_humidity, bs.notes, bs.status, bs.proto_id,
            CASE
              WHEN bs.status IN ('Done','Cancelled') THEN bs.status
              WHEN bs.sched_date = CURDATE() AND bs.sched_time <= CURTIME() THEN 'Running'
              ELSE 'Scheduled'
            END AS display_status,
              COALESCE(CONCAT(p.model_name, ' (', p.given_code, ')'), CONCAT('Prototype #', bs.proto_id), u.username, 'Unassigned Prototype') AS prototype_label
               FROM batch_schedules bs
             LEFT JOIN tblusers u ON u.id=bs.user_id
               LEFT JOIN tbl_prototypes p ON p.id=bs.proto_id
             WHERE bs.status <> 'Cancelled'
             ORDER BY bs.sched_date ASC, bs.sched_time ASC"
                );
                echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch(PDOException $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
            break;
        case 'get_inquiries':
          try {
            $currentDb = (string)$dbh->query("SELECT DATABASE()")->fetchColumn();
            if ($currentDb === '' || !preg_match('/^[A-Za-z0-9_]+$/', $currentDb)) {
              throw new Exception('Unable to resolve active database name.');
            }

            $dbh->exec(
              "CREATE TABLE IF NOT EXISTS `{$currentDb}`.`tbl_inquiries` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                contact VARCHAR(100) NULL,
                message TEXT NOT NULL,
                status ENUM('pending','read','replied') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $sourceDb = $currentDb;
            $maxCount = (int)$dbh->query("SELECT COUNT(*) FROM `{$currentDb}`.`tbl_inquiries`")->fetchColumn();

            if ($maxCount === 0) {
              $schemas = $dbh->query(
                "SELECT table_schema
                 FROM information_schema.tables
                 WHERE table_name = 'tbl_inquiries'"
              )->fetchAll(PDO::FETCH_COLUMN);

              foreach ($schemas as $schema) {
                $schema = (string)$schema;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
                  continue;
                }
                $count = (int)$dbh->query("SELECT COUNT(*) FROM `{$schema}`.`tbl_inquiries`")->fetchColumn();
                if ($count > $maxCount) {
                  $maxCount = $count;
                  $sourceDb = $schema;
                }
              }
            }

            $stmt = $dbh->query("SELECT id, name, contact, message, status, created_at FROM `{$sourceDb}`.`tbl_inquiries` ORDER BY created_at DESC");
            echo json_encode([
              'status' => 'success',
              'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
              'source_db' => $sourceDb,
              'total' => $maxCount
            ]);
          } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
          }
          break;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Smart Fish Drying | Admin Control Center</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css' rel='stylesheet'>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* ═══════════════════════════════════════════════════
   MARINATED & DRIED — Smart Fish Drying Admin Panel
   Warm Terracotta · Teal Ocean · Golden Amber
   Formal, Professional, Industry-Grade
   ═══════════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  /* Sidebar */
  --sidebar-bg:#123c42;
  --sidebar-active-bg:#1a4f57;
  --sidebar-w:272px;
  /* Surfaces */
  --main-bg:#eff6f3;
  --surface:#ffffff;
  --surface-2:#ecf4f2;
  /* Brand palette — fish-drying warm tones */
  --forest:#123c42;
  --forest-mid:#1a4f57;
  --teal:#0f766e;
  --teal-light:#155e75;
  --golden:#b45309;
  --amber:#c26b23;
  --terracotta:#b91c1c;
  --sand:#c18a52;
  --parchment:#f5ede1;
  --seafoam:#0d9488;
  /* Text */
  --text-primary:#102a2d;
  --text-secondary:#2f5358;
  --text-muted:#5f7a80;
  --text-light:#8ba2a7;
  --text-on-dark:#e7f6f4;
  --text-on-dark-muted:#a7d1cb;
  /* Accents */
  --success:#0f766e;
  --danger:#b91c1c;
  --warn:#b45309;
  --violet:#0e7490;
  /* Borders / Shadows */
  --border:#cde1de;
  --border-strong:#9ebdb9;
  --shadow-sm:0 1px 4px rgba(16,42,45,.07);
  --shadow-md:0 4px 16px rgba(16,42,45,.10);
  --shadow-lg:0 8px 32px rgba(16,42,45,.14);
  --shadow-xl:0 16px 56px rgba(16,42,45,.18);
  /* Glow */
  --glow-teal:0 0 24px rgba(15,118,110,.28);
  --glow-golden:0 0 24px rgba(180,83,9,.28);
  --glow-amber:0 0 24px rgba(185,28,28,.22);
}
body{font-family:'Bricolage Grotesque',sans-serif;background:var(--main-bg);color:var(--text-primary);min-height:100vh;overflow-x:hidden;}
/* Warm parchment texture overlay on body */
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3CfeColorMatrix type='saturate' values='0'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");
  opacity:.32;}

/* Theme/font harmonization with landing page */
.brand-title,
.page-title,
.telemetry-value,
.stat-value,
.modal-title,
.toast-title,
.fc-toolbar-title,
.user-avatar {
  font-family:'Bricolage Grotesque',sans-serif !important;
  letter-spacing:0;
}

.mono,
.nav-clock,
.filter-label,
.card-title,
.stat-label,
.page-sub,
.brand-sub,
.admin-badge,
.pill {
  font-family:'JetBrains Mono',monospace !important;
}

/* ── SIDEBAR ─────────────────────────────── */
.sidebar{
  position:fixed;left:0;top:0;width:var(--sidebar-w);height:100vh;z-index:200;
  background:var(--sidebar-bg);
  display:flex;flex-direction:column;overflow:hidden;
  box-shadow:4px 0 40px rgba(0,0,0,.30);
}
/* Deep forest texture + warm smoke effect */
.sidebar::before{
  content:'';position:absolute;inset:0;pointer-events:none;
  background:
    radial-gradient(ellipse 80% 40% at 50% 0%,rgba(42,157,143,.09) 0%,transparent 60%),
    radial-gradient(ellipse 60% 60% at 0% 100%,rgba(233,168,37,.06) 0%,transparent 60%),
    repeating-linear-gradient(0deg,transparent,transparent 48px,rgba(255,255,255,.012) 48px,rgba(255,255,255,.012) 49px);
}
.sidebar-frost{
  position:absolute;right:0;top:0;bottom:0;width:1px;
  background:linear-gradient(180deg,transparent 0%,rgba(42,157,143,.30) 30%,rgba(233,168,37,.15) 70%,transparent 100%);
}
.sidebar-brand{
  padding:22px 22px 16px;
  border-bottom:1px solid rgba(255,255,255,.07);
  position:relative;z-index:1;
}
.brand-logo{
  width:44px;height:44px;border-radius:10px;
  border:2px solid rgba(42,157,143,.45);
  box-shadow:var(--glow-teal);object-fit:cover;
}
.brand-title{font-family:'Playfair Display',serif;font-size:13.5px;font-weight:700;color:#EDE0C8;letter-spacing:.02em;line-height:1.2;}
.brand-sub{font-size:9.5px;font-weight:600;color:var(--text-on-dark-muted);letter-spacing:.12em;text-transform:uppercase;margin-top:2px;}
.admin-badge{
  display:inline-flex;align-items:center;gap:5px;
  background:rgba(233,168,37,.15);border:1px solid rgba(233,168,37,.28);
  border-radius:6px;padding:3px 10px;font-size:9px;font-weight:700;
  color:#EFC84A;letter-spacing:.07em;text-transform:uppercase;margin-top:8px;
}
.nav-clock{
  font-family:'Source Code Pro',monospace;font-size:11px;color:var(--teal-light);
  background:rgba(42,157,143,.08);border:1px solid rgba(42,157,143,.18);
  border-radius:6px;padding:7px 14px;text-align:center;
  margin:12px 16px 4px;letter-spacing:.10em;
}
.nav-section{
  padding:14px 22px 5px;font-size:9px;font-weight:700;
  color:rgba(255,255,255,.22);letter-spacing:.18em;text-transform:uppercase;
}
.nav-item{
  display:flex;align-items:center;gap:11px;padding:10px 22px;
  font-size:12.5px;font-weight:600;color:var(--text-on-dark-muted);
  cursor:pointer;border-left:3px solid transparent;
  transition:all .18s ease;text-decoration:none;position:relative;
  font-family:'Mulish',sans-serif;
}
.nav-item:hover{
  background:rgba(255,255,255,.04);color:var(--text-on-dark);
  border-left-color:rgba(42,157,143,.4);
}
.nav-item.active{
  background:linear-gradient(90deg,rgba(42,157,143,.13),rgba(42,157,143,.04));
  color:#5ECFBE;border-left-color:var(--teal-light);
}
.nav-item i{width:16px;text-align:center;font-size:13px;}
.nav-badge{
  background:var(--terracotta);color:#fff;font-size:9px;font-weight:900;
  border-radius:5px;padding:1px 7px;min-width:18px;text-align:center;margin-left:auto;
}
.sidebar-footer{
  margin-top:auto;padding:14px 16px;
  border-top:1px solid rgba(255,255,255,.07);
  position:relative;z-index:1;
}
.user-card{
  background:rgba(42,157,143,.07);border:1px solid rgba(42,157,143,.16);
  border-radius:12px;padding:12px;
}
.user-avatar{
  width:36px;height:36px;border-radius:8px;
  background:linear-gradient(135deg,var(--golden),var(--amber));
  display:flex;align-items:center;justify-content:center;
  font-family:'Playfair Display',serif;font-weight:800;font-size:15px;color:#fff;
}
.btn-logout{
  background:rgba(193,68,14,.1);border:1px solid rgba(193,68,14,.22);
  color:#E07050;border-radius:7px;padding:6px 12px;font-size:10px;
  font-weight:700;cursor:pointer;transition:.18s;width:100%;text-align:center;margin-top:8px;
  font-family:'Mulish',sans-serif;letter-spacing:.04em;text-transform:uppercase;
}
.btn-logout:hover{background:rgba(193,68,14,.2);}

/* ── MAIN AREA ───────────────────────────── */
.main{margin-left:var(--sidebar-w);min-height:100vh;background:var(--main-bg);position:relative;z-index:1;}

/* Smart Filter Bar */
.filter-bar{
  background:var(--surface);border-bottom:2px solid var(--border);
  padding:12px 32px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  position:sticky;top:0;z-index:100;box-shadow:var(--shadow-sm);
}
.filter-select{
  background:var(--surface-2);border:1.5px solid var(--border);border-radius:7px;
  padding:7px 12px;font-size:12px;font-weight:600;color:var(--text-primary);
  font-family:'Mulish',sans-serif;outline:none;cursor:pointer;
  transition:border-color .18s;min-width:140px;
}
.filter-select:focus{border-color:var(--teal);}
.filter-date{
  background:var(--surface-2);border:1.5px solid var(--border);border-radius:7px;
  padding:7px 12px;font-size:12px;color:var(--text-primary);font-family:'Mulish',sans-serif;
  outline:none;transition:border-color .18s;
}
.filter-date:focus{border-color:var(--teal);}
.filter-label{font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.10em;white-space:nowrap;}
.filter-divider{width:1px;height:22px;background:var(--border);flex-shrink:0;}
.filter-search{
  background:var(--surface-2);border:1.5px solid var(--border);border-radius:7px;
  padding:7px 14px 7px 34px;font-size:12px;color:var(--text-primary);
  font-family:'Mulish',sans-serif;outline:none;transition:border-color .18s;min-width:190px;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238C7355' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:10px center;
}
.filter-search:focus{border-color:var(--teal);}
.filter-live{
  display:flex;align-items:center;gap:6px;margin-left:auto;
  background:rgba(42,157,143,.08);border:1px solid rgba(42,157,143,.22);
  border-radius:6px;padding:5px 12px;
  font-size:11px;font-weight:700;color:var(--teal);letter-spacing:.04em;text-transform:uppercase;
}
.live-dot{width:7px;height:7px;border-radius:50%;background:var(--teal);box-shadow:0 0 8px var(--teal);animation:livePulse 1.6s ease-in-out infinite;}
@keyframes livePulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.85)}}

/* ── CONTENT WRAPPER ─────────────────────── */
.content-wrap{padding:28px 32px;}

/* ── PAGE HEADER ─────────────────────────── */
.page-header{margin-bottom:24px;}
.page-title{font-family:'Playfair Display',serif;font-size:24px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em;}
.page-sub{font-size:12.5px;color:var(--text-muted);margin-top:3px;font-weight:500;}

/* Decorative section rule */
.page-title::after{content:'';display:block;width:40px;height:3px;background:linear-gradient(90deg,var(--teal),var(--golden));border-radius:99px;margin-top:7px;}

/* ── STAT CARDS ──────────────────────────── */
.stat-card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:14px;padding:20px 22px;position:relative;overflow:hidden;
  box-shadow:var(--shadow-sm);transition:box-shadow .2s,transform .2s;
  border-top:3px solid var(--border);
}
.stat-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.stat-icon{
  width:44px;height:44px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:14px;
}
.stat-value{font-family:'Playfair Display',serif;font-size:32px;font-weight:700;line-height:1;color:var(--text-primary);}
.stat-label{font-size:10.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.10em;margin-top:4px;}
.stat-accent{position:absolute;bottom:0;right:0;width:70px;height:70px;border-radius:50%;filter:blur(28px);opacity:.14;}
.stat-trend{font-size:11px;font-weight:700;margin-top:6px;display:flex;align-items:center;gap:4px;}

/* ── GLASS CARD (sections) ───────────────── */
.glass-card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:14px;box-shadow:var(--shadow-sm);overflow:hidden;
}
.card-head{
  padding:18px 22px 0;display:flex;align-items:center;
  justify-content:space-between;margin-bottom:16px;
}
.card-title{
  font-size:11px;font-weight:700;text-transform:uppercase;
  letter-spacing:.12em;color:var(--text-muted);
  display:flex;align-items:center;gap:8px;
}
.card-title-dot{width:6px;height:6px;border-radius:2px;}

/* Telemetry Cards */
.telemetry-card{
  background:linear-gradient(145deg,#243228,#1C2B1F);
  border:1px solid rgba(42,157,143,.18);border-radius:14px;
  padding:22px;color:#fff;box-shadow:var(--shadow-md);
  position:relative;overflow:hidden;
}
.telemetry-card::after{
  content:'';position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(42,157,143,.09) 0%,transparent 70%);
}
/* Hatched fish-scale pattern on telemetry cards */
.telemetry-card::before{
  content:'';position:absolute;inset:0;pointer-events:none;opacity:.04;
  background-image:radial-gradient(circle at 50% 50%,rgba(233,168,37,.5) 1px,transparent 1px);
  background-size:18px 18px;
}
.telemetry-value{font-family:'Playfair Display',serif;font-size:38px;font-weight:700;line-height:1;color:#fff;margin-top:8px;}
.telemetry-unit{font-size:16px;font-weight:400;color:rgba(237,224,200,.5);}
.telemetry-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--text-on-dark-muted);}
.telemetry-ring{position:absolute;right:-20px;bottom:-20px;width:100px;height:100px;border-radius:50%;border:2px solid rgba(42,157,143,.14);box-shadow:inset 0 0 20px rgba(42,157,143,.06);}

/* ── DATA TABLE ──────────────────────────── */
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table th{
  font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.10em;
  color:var(--text-muted);padding:11px 16px;
  border-bottom:2px solid var(--border);text-align:left;white-space:nowrap;
  background:var(--surface-2);font-family:'Mulish',sans-serif;
}
.data-table td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;color:var(--text-primary);}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:#F8F3EA;}
.section-scroll{overflow-x:auto;}

/* ── PILLS / BADGES ──────────────────────── */
.pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:5px;font-size:9.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap;}
.pill-Running   {background:rgba(42,157,143,.10);color:#1E7A6E;border:1px solid rgba(42,157,143,.22);}
.pill-Completed {background:rgba(42,157,143,.10);color:#1E7A6E;border:1px solid rgba(42,157,143,.22);}
.pill-Interrupted{background:rgba(193,68,14,.08);color:#9C3510;border:1px solid rgba(193,68,14,.2);}
.pill-Scheduled {background:rgba(124,92,191,.08);color:#6344A0;border:1px solid rgba(124,92,191,.2);}
.pill-Done      {background:rgba(82,182,154,.10);color:#2D7A62;border:1px solid rgba(82,182,154,.22);}
.pill-admin     {background:rgba(233,168,37,.10);color:#7D5800;border:1px solid rgba(233,168,37,.22);}
.pill-user      {background:rgba(42,157,143,.08);color:#1E7A6E;border:1px solid rgba(42,157,143,.18);}
.pill-active    {background:rgba(82,182,154,.10);color:#2D7A62;border:1px solid rgba(82,182,154,.22);}
.pill-disabled  {background:rgba(193,68,14,.07);color:#9C3510;border:1px solid rgba(193,68,14,.16);}

/* ── SKELETON LOADER ─────────────────────── */
.skeleton{background:linear-gradient(90deg,var(--surface-2) 25%,#EAE3D2 50%,var(--surface-2) 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:6px;}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.skel-row{height:14px;margin-bottom:10px;}
.skel-wide{width:80%;}
.skel-med{width:55%;}
.skel-short{width:30%;}

/* ── TAB SECTIONS ────────────────────────── */
.tab-section{display:none;}
.tab-section.active{display:block;}

/* ── ACTION BUTTONS ──────────────────────── */
.act-btn{padding:5px 11px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;border:none;transition:all .18s;font-family:'Mulish',sans-serif;letter-spacing:.03em;}
.act-view{background:rgba(42,157,143,.08);color:#1E7A6E;border:1px solid rgba(42,157,143,.2);}
.act-edit{background:rgba(124,92,191,.07);color:#6344A0;border:1px solid rgba(124,92,191,.2);}
.act-del{background:rgba(193,68,14,.07);color:#9C3510;border:1px solid rgba(193,68,14,.16);}
.act-stop{background:rgba(233,168,37,.08);color:#7D5800;border:1px solid rgba(233,168,37,.22);}
.act-btn:hover{opacity:.8;transform:translateY(-1px);}

/* ── PRIMARY BUTTON ──────────────────────── */
.btn-primary{
  background:linear-gradient(135deg,var(--teal),var(--teal-light));
  border:none;border-radius:8px;padding:9px 18px;
  font-size:12px;font-weight:700;color:#fff;cursor:pointer;
  transition:.2s;box-shadow:0 3px 14px rgba(42,157,143,.28);
  font-family:'Mulish',sans-serif;letter-spacing:.03em;
}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(42,157,143,.40);}
.btn-danger{
  background:rgba(193,68,14,.08);border:1.5px solid rgba(193,68,14,.22);
  color:#9C3510;border-radius:8px;padding:9px 18px;
  font-size:12px;font-weight:700;cursor:pointer;transition:.2s;font-family:'Mulish',sans-serif;
}
.btn-danger:hover{background:rgba(193,68,14,.16);}

/* ── TOAST ───────────────────────────────── */
#toastZone{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:360px;}
.toast-item{
  background:var(--surface);border-radius:10px;padding:14px 16px;
  display:flex;align-items:flex-start;gap:10px;
  box-shadow:var(--shadow-xl);border-left:3.5px solid var(--border);
  animation:toastIn .28s ease;cursor:pointer;
  border:1px solid var(--border);
}
.toast-item.success{border-left-color:var(--teal);}
.toast-item.warning{border-left-color:var(--golden);}
.toast-item.critical{border-left-color:var(--terracotta);}
.toast-title{font-size:12.5px;font-weight:700;color:var(--text-primary);font-family:'Playfair Display',serif;}
.toast-msg{font-size:11.5px;color:var(--text-muted);}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}

/* ── MODAL ───────────────────────────────── */
.modal-backdrop{position:fixed;inset:0;background:rgba(28,18,8,.55);backdrop-filter:blur(8px);z-index:5000;display:flex;align-items:center;justify-content:center;padding:16px;}
.modal-panel{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:30px;width:100%;max-width:480px;animation:fadeUp .28s ease;box-shadow:var(--shadow-xl);}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.modal-title{font-family:'Playfair Display',serif;font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:20px;}
.form-row{margin-bottom:14px;}
.form-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.10em;color:var(--text-muted);margin-bottom:5px;display:block;}
.form-input{width:100%;background:var(--surface-2);border:1.5px solid var(--border);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--text-primary);font-family:'Mulish',sans-serif;outline:none;transition:.2s;}
.form-input:focus{border-color:var(--teal);background:#fff;}

/* ── QUICK ACTION FAB ─────────────────────── */
.fab{
  position:fixed;bottom:28px;right:28px;z-index:1000;
  width:56px;height:56px;
  background:linear-gradient(135deg,var(--teal),var(--golden));
  border-radius:14px;display:flex;align-items:center;justify-content:center;
  cursor:pointer;box-shadow:0 6px 24px rgba(42,157,143,.42);
  font-size:20px;color:#fff;transition:.25s;border:none;
  font-family:'Mulish',sans-serif;
}
.fab:hover{transform:scale(1.1);box-shadow:0 8px 32px rgba(42,157,143,.55);}
.fab.alert-active{background:linear-gradient(135deg,var(--terracotta),#8C2800);box-shadow:0 6px 24px rgba(193,68,14,.4);animation:fabPulse 1.6s infinite;}
@keyframes fabPulse{0%,100%{box-shadow:0 6px 24px rgba(193,68,14,.4)}50%{box-shadow:0 6px 36px rgba(193,68,14,.7)}}
.fab-count{position:absolute;top:-4px;right:-4px;background:#fff;color:var(--terracotta);font-size:9px;font-weight:900;border-radius:6px;padding:2px 6px;min-width:18px;text-align:center;}
.fab-menu{
  position:fixed;bottom:94px;right:28px;z-index:1000;
  display:flex;flex-direction:column;gap:8px;align-items:flex-end;
  pointer-events:none;opacity:0;transform:translateY(10px);transition:.2s;
}
.fab-menu.open{pointer-events:auto;opacity:1;transform:none;}
.fab-action{
  display:flex;align-items:center;gap:8px;
  background:var(--surface);border:1px solid var(--border);
  border-radius:10px;padding:8px 14px 8px 10px;
  box-shadow:var(--shadow-md);cursor:pointer;
  font-size:12px;font-weight:600;color:var(--text-primary);
  font-family:'Mulish',sans-serif;white-space:nowrap;transition:.18s;
}
.fab-action:hover{box-shadow:var(--shadow-lg);transform:translateX(-2px);}
.fab-action-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;}

/* ── ALERT PANEL ─────────────────────────── */
.alert-item{
  display:flex;align-items:flex-start;gap:10px;
  background:#FFF5EE;border:1px solid rgba(193,68,14,.14);border-left:3px solid var(--terracotta);
  border-radius:8px;padding:10px 12px;margin-bottom:8px;
}
.alert-icon{color:var(--terracotta);font-size:14px;margin-top:1px;flex-shrink:0;}
.alert-msg{font-size:11.5px;font-weight:700;color:#6A2000;}
.alert-sub{font-size:10.5px;color:var(--text-muted);margin-top:2px;}

/* ── TREND CHART ─────────────────────────── */
.chart-wrap{padding:20px 22px;}
.chart-legend{display:flex;gap:14px;margin-bottom:14px;flex-wrap:wrap;}
.legend-dot{width:8px;height:8px;border-radius:2px;flex-shrink:0;margin-top:4px;}
.legend-label{font-size:11px;font-weight:600;color:var(--text-muted);display:flex;align-items:flex-start;gap:5px;}

/* ── FC CALENDAR ─────────────────────────── */
.fc{color:var(--text-primary)!important;font-family:'Mulish',sans-serif!important;}
.fc-toolbar-title{font-family:'Playfair Display',serif!important;font-size:15px!important;font-weight:700!important;color:var(--text-primary)!important;}
.fc-button-primary{background:var(--surface-2)!important;border-color:var(--border)!important;color:var(--text-secondary)!important;border-radius:7px!important;font-size:11px!important;font-weight:700!important;font-family:'Mulish',sans-serif!important;box-shadow:none!important;}
.fc-button-primary:hover{background:var(--parchment)!important;color:var(--text-primary)!important;}
.fc-button-primary:not(:disabled).fc-button-active{background:var(--teal)!important;border-color:var(--teal)!important;color:#fff!important;}
.fc-day-today{background:rgba(42,157,143,.04)!important;}
.fc-event{border-radius:4px!important;font-size:10px!important;font-weight:700!important;cursor:pointer!important;}
.fc-col-header-cell{color:var(--text-muted)!important;font-size:10px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:.08em!important;}
.fc-daygrid-day-number{color:var(--text-secondary)!important;font-size:11.5px!important;font-weight:600!important;}
.fc-scrollgrid,.fc-scrollgrid td,.fc-scrollgrid th{border-color:var(--border)!important;}

/* ── SCROLLBAR ───────────────────────────── */
::-webkit-scrollbar{width:4px;height:4px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border-strong);border-radius:99px;}

/* ── SEARCH BOX ──────────────────────────── */
.search-box{
  background:var(--surface-2);border:1.5px solid var(--border);
  border-radius:8px;padding:8px 14px;font-size:12px;color:var(--text-primary);
  width:220px;outline:none;font-family:'Mulish',sans-serif;
}
.search-box:focus{border-color:var(--teal);}

/* ── RECORDS SPECIAL ─────────────────────── */
.mono{font-family:'Source Code Pro',monospace;font-size:11px;}

/* ── SCHEDULE TABLE ──────────────────────── */
.sched-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);}
.sched-item:last-child{border-bottom:none;}

/* Events modal body styling */
.evt-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:12.5px;}
.evt-row:last-child{border-bottom:none;}
.evt-lbl{color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;}
.evt-val{font-weight:700;color:var(--text-primary);}

/* Warm header accent bar */
.content-wrap>.tab-section.active{animation:fadeTabIn .22s ease;}
@keyframes fadeTabIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}</style>
<link rel="stylesheet" href="../assets/fishda-theme.css">
</head>
<body class="theme-fishda theme-dashboard">
<div id="toastZone"></div>

<!-- ═══════════ SIDEBAR ═══════════ -->
<div class="sidebar">
  <div class="sidebar-frost"></div>
  <div class="sidebar-brand">
    <div class="d-flex align-items-center gap-3 mb-1">
      <img src="../assets/fishda.jpg" alt="Logo" class="brand-logo" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22><rect width=%2248%22 height=%2248%22 rx=%2212%22 fill=%22%23002147%22/><text x=%2224%22 y=%2２31%2２ text-anchor=%2２middle%2２ fill=%2２%2200CCFF%2２ font-size=%2２ twenty-four% twenty-four>🐟</text></svg>'">>
      <div>
        <div class="brand-title">Smart Fish Drying</div>
        <div class="brand-sub">Control Center</div>
      </div>
    </div>
    <div class="admin-badge"><i class="fas fa-shield-halved me-1"></i>Admin Panel</div>
  </div>
  <div class="nav-clock" id="navClock">00:00:00</div>
  <div class="nav-section">Overview</div>
  <a class="nav-item active" id="link-dashboard" onclick="showTab('dashboard')"><i class="fas fa-chart-pie"></i>Dashboard</a>
  <div class="nav-section">Management</div>
  <a class="nav-item" id="link-users" onclick="showTab('users')"><i class="fas fa-microchip"></i>Device Models</a>
  <div class="nav-section">Sessions</div>
  <a class="nav-item" id="link-records" onclick="showTab('records')"><i class="fas fa-database"></i>Completed Sessions</a>
  <a class="nav-item" id="link-inquiries" onclick="showTab('inquiries')"><i class="fas fa-envelope-open-text"></i>Inquiries</a>
  <div class="sidebar-footer">
    <div class="user-card">
      <div class="d-flex align-items-center gap-2 mb-2">
        <div class="user-avatar"><?= strtoupper(substr($admin,0,1)) ?></div>
        <div>
          <div style="font-size:12.5px;font-weight:700;color:var(--text-on-dark)"><?= $admin ?></div>
          <div style="font-size:10px;color:#a78bfa;">Administrator</div>
        </div>
      </div>
      <div onclick="logoutAdmin()" class="btn-logout"><i class="fas fa-right-from-bracket me-1"></i> Logout</div>
    </div>
  </div>
</div>

<!-- ═══════════ MAIN ═══════════ -->
<div class="main">

  <div class="content-wrap">

    <!-- ══════════════════════════ DASHBOARD ══════════════════════════ -->
    <div id="tab-dashboard" class="tab-section active">
      <div class="d-flex align-items-start justify-content-between mb-4">
        <div class="page-header" style="margin-bottom:0;">
          <div class="page-title">📊 Admin Dashboard</div>
          <div class="page-sub">Real-time overview — Smart Fish Drying System</div>
        </div>
        <button onclick="loadStats();loadDailyTrends();" class="btn-primary" style="padding:8px 16px;font-size:11px;">
          <i class="fas fa-rotate me-1"></i>Refresh All
        </button>
      </div>

      <!-- Telemetry Row -->
      <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
          <div class="telemetry-card">
            <div class="telemetry-ring"></div>
            <div class="telemetry-label"><i class="fas fa-microchip me-2" style="color:var(--parchment)"></i>Total Device Models</div>
            <div class="telemetry-value" id="statUsers">—</div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="telemetry-card">
            <div class="telemetry-ring"></div>
            <div class="telemetry-label"><i class="fas fa-fish me-2" style="color:var(--golden)"></i>Total Sessions</div>
            <div class="telemetry-value" id="statSessions">—</div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="telemetry-card" style="background:linear-gradient(135deg,#1a4a2e,#0f2d1c);">
            <div class="telemetry-ring" style="border-color:rgba(46,196,182,.18)"></div>
            <div class="telemetry-label"><i class="fas fa-bolt me-2" style="color:#4ade80"></i>Active Now</div>
            <div class="telemetry-value" id="statActive" style="color:#4ade80">—</div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="telemetry-card" style="background:linear-gradient(135deg,#1a2f4a,#0f1e2d);">
            <div class="telemetry-ring" style="border-color:rgba(0,204,255,.18)"></div>
            <div class="telemetry-label"><i class="fas fa-temperature-high me-2" style="color:var(--golden)"></i>Overall Avg Temp</div>
            <div class="telemetry-value" id="statAvgTemp">—</div>
          </div>
        </div>
      </div>

      <!-- Stat cards row -->
      <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(0,119,182,.1);color:var(--teal)"><i class="fas fa-circle-check"></i></div>
            <div class="stat-value" id="statCompleted">—</div>
            <div class="stat-label">Completed Sessions</div>
            <div class="stat-accent" style="background:var(--teal)"></div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(123,97,255,.1);color:#7b61ff"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-value" id="statScheduled">—</div>
            <div class="stat-label">Pending Schedules</div>
            <div class="stat-accent" style="background:#7b61ff"></div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="stat-card" style="border-left:3px solid var(--seafoam)">
            <div class="stat-icon" style="background:rgba(46,196,182,.1);color:var(--seafoam)"><i class="fas fa-droplet-slash"></i></div>
            <div class="stat-value" style="color:var(--seafoam)" id="statDry">—</div>
            <div class="stat-label" style="color:var(--seafoam)">Fish Dried ✓</div>
            <div class="stat-accent" style="background:var(--seafoam)"></div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <!-- 14-Day Trend Chart -->
        <div class="col-12">
          <div class="glass-card">
            <div class="card-head">
              <div class="card-title">
                <span class="card-title-dot" style="background:var(--teal)"></span>
                14-Day Temperature & Humidity Trends
              </div>
              <button onclick="loadDailyTrends()" class="act-btn act-view" style="padding:4px 9px;font-size:9.5px;"><i class="fas fa-rotate me-1"></i>Refresh</button>
            </div>
            <div class="chart-legend" style="padding:0 22px;">
              <div class="legend-label"><span class="legend-dot" style="background:var(--teal)"></span>Avg Temperature</div>
              <div class="legend-label"><span class="legend-dot" style="background:var(--seafoam)"></span>Avg Humidity</div>
            </div>
            <div class="chart-wrap" style="padding-top:0"><canvas id="trendChart" height="130"></canvas></div>
          </div>
        </div>

        <!-- Recent Sessions -->
        <div class="col-12">
          <div class="glass-card">
            <div class="card-head">
              <div class="card-title">
                <span class="card-title-dot" style="background:var(--teal-light)"></span>
                Recent Sessions
              </div>
              <a onclick="showTab('records')" style="font-size:11px;color:var(--teal);cursor:pointer;font-weight:700;">View All →</a>
            </div>
            <div class="section-scroll" style="padding:0 0 16px">
              <table class="data-table">
                <thead><tr>
                  <th>#ID</th><th>Prototype</th><th>Start Time</th><th>Duration</th>
                  <th>Targets</th><th>Avg Temp</th>
                </tr></thead>
                <tbody id="recentSessionsBody">
                  <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--text-muted)">
                    <i class="fas fa-spinner fa-spin me-2"></i>Loading…
                  </td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /tab-dashboard -->

    <!-- ══════════════════════════ DEVICE MODELS ══════════════════════════ -->
    <div id="tab-users" class="tab-section">
      <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
          <div class="page-title">📦 Device Model Registry</div>
          <div class="page-sub">Register Unit/Model and Model Code for prototype access.</div>
        </div>
        <div class="d-flex gap-2">
          <input type="text" class="search-box" id="prototypeSearch" placeholder="🔍  Search model or code…" oninput="filterPrototypes()">
          <button class="btn-primary" style="padding:8px 14px;font-size:11px;" onclick="openAddPrototype()"><i class="fas fa-plus me-1"></i>Add Device</button>
        </div>
      </div>
      <div class="glass-card">
        <div class="section-scroll" style="padding:16px">
          <table class="data-table" id="prototypesTable">
            <thead><tr><th>#ID</th><th>Unit/Model</th><th>Model Code</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="prototypesBody">
              <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════ CALENDAR ══════════════════════════ -->
    <div id="tab-calendar" class="tab-section">
      <div class="page-title mb-1"><i class="fas fa-calendar-days me-2"></i>Schedule Calendar</div>
      <div class="page-sub mb-4">All batches, sessions, and scheduled drying plans.</div>
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="glass-card" style="padding:24px;">
            <div id="adminCalendar"></div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="glass-card" style="padding:20px;">
            <div class="card-title mb-3" style="display:flex;"><span class="card-title-dot me-2" style="background:var(--teal);margin-top:2px"></span>Upcoming Schedules</div>
            <div class="section-scroll" style="max-height:360px;overflow-y:auto;">
              <table class="data-table" style="font-size:11.5px;">
                <thead><tr><th>Prototype</th><th>Batch</th><th>Date/Time</th><th>Targets</th><th>Status</th></tr></thead>
                <tbody id="adminSchedulesBody">
                  <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i></td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════ RECORDS ══════════════════════════ -->
    <div id="tab-records" class="tab-section">
      <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
          <div class="page-title">📋 Completed Sessions</div>
          <div class="page-sub">Complete archive of all drying sessions.</div>
        </div>
        <button class="btn-primary" style="padding:8px 14px;font-size:11px;" onclick="loadRecords()"><i class="fas fa-rotate me-1"></i>Refresh</button>
      </div>
      <div class="glass-card">
        <div class="section-scroll" style="padding:16px">
          <table class="data-table">
            <thead><tr>
              <th>#</th><th>Prototype</th><th>Fish Count</th><th>Duration</th>
            <th>Avg Temp</th><th>Avg Hum</th><th>Status</th><th>Timestamp</th>
            </tr></thead>
            <tbody id="recordsBody">
              <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i>Loading sessions…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════ INQUIRIES ══════════════════════════ -->
    <div id="tab-inquiries" class="tab-section">
      <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
          <div class="page-title">✉️ Contact Inquiries</div>
          <div class="page-sub">Messages sent from the landing page "Get Touch With Us" form.</div>
          <div class="page-sub" id="inqMeta" style="margin-top:2px;font-size:11px;">Checking inquiry source...</div>
        </div>
        <button class="btn-primary" style="padding:8px 14px;font-size:11px;" onclick="loadInquiries()"><i class="fas fa-rotate me-1"></i>Refresh</button>
      </div>
      <div class="glass-card">
        <div class="section-scroll" style="padding:16px">
          <table class="data-table">
            <thead><tr>
              <th>#</th><th>Name</th><th>Contact</th><th>Message</th><th>Status</th><th>Sent</th><th>Action</th>
            </tr></thead>
            <tbody id="inquiriesBody">
              <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i>Loading inquiries…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /content-wrap -->
</div><!-- /main -->

<!-- ═══════════ QUICK ACTION FAB ═══════════ -->
<div class="fab-menu" id="fabMenu">
  <div class="fab-action" onclick="showTab('records');loadRecords();closeFab();">
    <div class="fab-action-icon" style="background:rgba(0,119,182,.1);color:var(--teal)"><i class="fas fa-database"></i></div>
    View Completed Sessions
  </div>
  <div class="fab-action" onclick="loadStats();loadDailyTrends();closeFab();">
    <div class="fab-action-icon" style="background:rgba(46,196,182,.1);color:var(--seafoam)"><i class="fas fa-chart-line"></i></div>
    Refresh Dashboard
  </div>
</div>
<button class="fab" id="fabBtn" onclick="toggleFab()">
  <i class="fas fa-bolt"></i>
</button>

<!-- ═══════════ DEVICE MODAL ═══════════ -->
<div class="modal-backdrop" id="prototypeModal" style="display:none;" onclick="if(event.target===this)closePrototypeModal()">
  <div class="modal-panel">
    <div class="modal-title" id="prototypeModalTitle">Add Device Model</div>
    <input type="hidden" id="editPrototypeId">
    <div class="form-row">
      <label class="form-label">Unit / Model</label>
      <input type="text" id="modalModelName" class="form-input" placeholder="e.g. Fishda Dryer V2">
    </div>
    <div class="form-row">
      <label class="form-label">Model Code</label>
      <input type="text" id="modalModelCode" class="form-input" placeholder="e.g. FD2026">
    </div>
    <div class="form-row">
      <label class="form-label">Status</label>
      <select id="modalStatus" class="form-input">
        <option value="1">Active</option>
        <option value="0">Disabled</option>
      </select>
    </div>
    <div class="d-flex gap-2 mt-2">
      <button onclick="savePrototype()" class="btn-primary" style="flex:1;">Save Device</button>
      <button onclick="closePrototypeModal()" class="btn-danger" style="flex:0 0 auto;padding:9px 18px;">Cancel</button>
    </div>
  </div>
</div>

<!-- ═══════════ EVENT MODAL ═══════════ -->
<div class="modal-backdrop" id="eventModal" style="display:none;" onclick="if(event.target===this)closeEventModal()">
  <div class="modal-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="modal-title" id="evtModalTitle" style="margin-bottom:0">Event</div>
      <button onclick="closeEventModal()" style="background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:5px 11px;font-size:11px;font-weight:600;color:var(--text-muted);cursor:pointer;"><i class="fas fa-xmark"></i></button>
    </div>
    <div id="evtModalBody" style="display:flex;flex-direction:column;gap:0"></div>
    <div id="evtModalActions" style="margin-top:12px"></div>
  </div>
</div>

<!-- ═══════════ INQUIRY MODAL ═══════════ -->
<div class="modal-backdrop" id="inquiryModal" style="display:none;" onclick="if(event.target===this)closeInquiryModal()">
  <div class="modal-panel" style="max-width:680px;width:min(680px,calc(100vw - 24px));">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="modal-title" id="inqModalTitle" style="margin-bottom:0">Inquiry Message</div>
      <button onclick="closeInquiryModal()" style="background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:5px 11px;font-size:11px;font-weight:600;color:var(--text-muted);cursor:pointer;"><i class="fas fa-xmark"></i></button>
    </div>
    <div id="inqModalBody" style="display:flex;flex-direction:column;gap:12px"></div>
  </div>
</div>

<script>
// ════════════════════════════════════════════════════════
//  STATE & INIT
// ════════════════════════════════════════════════════════
let trendChart=null, sessDetailChart=null, adminCal=null;
let allPrototypes=[];
let fabOpen=false;

// Clock
setInterval(()=>{
  const n=new Date();
  document.getElementById('navClock').textContent=n.toLocaleTimeString('en-PH',{hour12:false});
},1000);

function showTab(tab){
  document.querySelectorAll('.tab-section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('tab-'+tab).classList.add('active');
  const lnk=document.getElementById('link-'+tab);
  if(lnk) lnk.classList.add('active');
  if(tab==='users'){ loadAllPrototypes(); }
  if(tab==='records'){ loadRecords(); }
  if(tab==='inquiries'){ loadInquiries(); }
}

function toggleFab(){ fabOpen=!fabOpen; document.getElementById('fabMenu').classList.toggle('open',fabOpen); }
function closeFab(){ fabOpen=false; document.getElementById('fabMenu').classList.remove('open'); }

// ════════════════════════════════════════════════════════
//  STATS
// ════════════════════════════════════════════════════════
async function loadStats(){
  try{
    const j=await(await fetch('admin_sessions.php?action=get_stats')).json();
    if(j.status!=='success') return;
    const d=j.data;
    document.getElementById('statUsers').textContent=d.total_devices??'—';
    document.getElementById('statSessions').textContent=d.total_sessions??'—';
    document.getElementById('statActive').textContent=d.active_sessions??'0';
    document.getElementById('statCompleted').textContent=d.completed??'—';
    document.getElementById('statScheduled').textContent=d.pending_schedules??'—';
    document.getElementById('statAvgTemp').textContent=(d.overall_avg_temp??'—')+(d.overall_avg_temp?'°':'');
    // mock dry count — could be a separate query
    document.getElementById('statDry').textContent=d.completed??'—';
  }catch(e){}
}

function normalizeScheduleStatus(status){
  return status === 'Running' ? 'Running' : (status || 'Scheduled');
}
function scheduleStatusLabel(status){
  return status === 'Running' ? 'ONGOING' : (status || 'Scheduled');
}

// ════════════════════════════════════════════════════════
//  TREND CHART
// ════════════════════════════════════════════════════════
async function loadDailyTrends(){
  try{
    const j=await(await fetch('admin_sessions.php?action=get_daily_trends')).json();
    if(j.status!=='success') return;
    const data=[...j.data].reverse();
    const labels=data.map(d=>d.day?.slice(5));
    const temps=data.map(d=>parseFloat(d.avg_temp)||0);
    const hums=data.map(d=>parseFloat(d.avg_hum)||0);
    if(trendChart) trendChart.destroy();
    const ctx=document.getElementById('trendChart').getContext('2d');
    const tempGrad=ctx.createLinearGradient(0,0,0,250);
    tempGrad.addColorStop(0,'rgba(0,119,182,.25)');
    tempGrad.addColorStop(1,'rgba(0,119,182,0)');
    const humGrad=ctx.createLinearGradient(0,0,0,250);
    humGrad.addColorStop(0,'rgba(46,196,182,.25)');
    humGrad.addColorStop(1,'rgba(46,196,182,0)');
    trendChart=new Chart(ctx,{
      type:'line',
      data:{
        labels,
        datasets:[
          {label:'Avg Temp °C',data:temps,borderColor:'#0077B6',backgroundColor:tempGrad,borderWidth:2,pointRadius:3,pointBackgroundColor:'#0077B6',pointBorderColor:'#fff',pointBorderWidth:1.5,tension:.4,fill:true},
          {label:'Avg Humidity %',data:hums,borderColor:'#2EC4B6',backgroundColor:humGrad,borderWidth:2,pointRadius:3,pointBackgroundColor:'#2EC4B6',pointBorderColor:'#fff',pointBorderWidth:1.5,tension:.4,fill:true}
        ]
      },
      options:{
        responsive:true,plugins:{legend:{display:false},tooltip:{mode:'index',intersect:false,backgroundColor:'rgba(255,255,255,.98)',titleColor:'#0D1B2A',bodyColor:'#4A6FA5',borderColor:'#D6E4F0',borderWidth:1,cornerRadius:10,padding:12}},
        scales:{
          x:{grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:10,family:'DM Sans'},color:'#8BA7C4'}},
          y:{grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:10,family:'DM Sans'},color:'#8BA7C4'}}
        }
      }
    });
  }catch(e){}
}

function badge(id,txt,show){
  const el=document.getElementById(id);
  if(!el) return;
  el.textContent=txt;
  el.style.display=show?'':'none';
}

// Session Detail
let detailChartInst=null;
async function viewSessDetail(sid){
  document.getElementById('sessDetailCard').style.display='block';
  document.getElementById('sessDetailTitle').textContent=`Session #${sid} — Temperature & Humidity Log`;
  document.getElementById('sessDetailCard').scrollIntoView({behavior:'smooth',block:'nearest'});
  try{
    const j=await(await fetch(`admin_sessions.php?action=get_session_detail&session_id=${sid}`)).json();
    if(!j.data?.length) return;
    const labels=j.data.map((_,i)=>i+1);
    const temps=j.data.map(d=>parseFloat(d.recorded_temp)||0);
    const hums=j.data.map(d=>parseFloat(d.recorded_humidity)||0);
    if(detailChartInst) detailChartInst.destroy();
    const ctx=document.getElementById('sessDetailChart').getContext('2d');
    detailChartInst=new Chart(ctx,{
      type:'line',
      data:{labels,datasets:[
        {label:'Temp °C',data:temps,borderColor:'#0077B6',borderWidth:1.5,pointRadius:2,tension:.4,fill:false},
        {label:'Humidity %',data:hums,borderColor:'#2EC4B6',borderWidth:1.5,pointRadius:2,tension:.4,fill:false}
      ]},
      options:{responsive:true,plugins:{legend:{labels:{font:{size:11,family:'DM Sans'},color:'#4A6FA5'}}},
        scales:{
          x:{display:false},
          y:{grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:10,family:'DM Sans'},color:'#8BA7C4'}}
        }}
    });
  }catch(e){}
}
function closeSessDetail(){document.getElementById('sessDetailCard').style.display='none';}

async function adminStopSession(sid,deviceLabel){
  const r=await Swal.fire({
    title:`Stop Session #${sid}?`,text:`This will mark ${deviceLabel}'s session as Completed.`,
    icon:'warning',showCancelButton:true,confirmButtonColor:'#E63946',confirmButtonText:'Stop Session',
    background:'#fff',color:'#0D1B2A',customClass:{popup:'swal-clean'}
  });
  if(!r.isConfirmed) return;
  const fd=new FormData(); fd.append('action','stop_session'); fd.append('session_id',sid);
  try{
    const j=await(await fetch('admin_sessions.php',{method:'POST',body:fd})).json();
    if(j.status==='success'){ loadRecentSessions(); loadStats(); if(document.getElementById('tab-records')?.classList.contains('active')) loadRecords(); showToast('success','Session Stopped',j.message,3000); }
    else showToast('warning','Error',j.message||'Failed.',3000);
  }catch(e){ showToast('warning','Network Error','Could not reach server.',3000); }
}

// ════════════════════════════════════════════════════════
//  RECENT SESSIONS
// ════════════════════════════════════════════════════════
async function loadRecentSessions(){
  const body=document.getElementById('recentSessionsBody');
  if(!body) return;

  body.innerHTML=`<tr><td colspan="6" style="text-align:center;padding:28px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</td></tr>`;

  try{
    // Use the same dataset as Completed Sessions to keep both tables consistent.
    const r=await fetch('admin_sessions.php?action=fetch_all_records');
    const j=await r.json();
    const rows = j.records || j.data;
    if(j.status!=='success' || !Array.isArray(rows)){
      body.innerHTML=noDataRow(6);
      return;
    }

    // Show latest 10 completed records on dashboard (same source as Completed Sessions).
    const sessions=rows.slice(0,10);

    if(!sessions.length){
      body.innerHTML=noDataRow(6);
      return;
    }

    const formatDateTime=(v)=>{
      if(!v) return '—';
      const d=new Date(v.replace(' ','T'));
      if(Number.isNaN(d.getTime())) return v;
      return d.toLocaleString('en-PH',{
        year:'numeric', month:'2-digit', day:'2-digit',
        hour:'2-digit', minute:'2-digit', hour12:false
      });
    };

    body.innerHTML=sessions.map(s=>{
      const label = (s.prototype_model || 'Fishda') + ` (${s.prototype_code || 'FD2026'})`;
      const avgTemp = parseFloat(s.temp_avg || 0);
      const avgTempText = avgTemp > 0 ? `${avgTemp.toFixed(1)}°C` : '—';

      return `
        <tr>
          <td class="mono" style="color:var(--teal)">#${s.session_id}</td>
          <td style="font-weight:700">${label}</td>
          <td style="font-size:11px;color:var(--text-muted)">${formatDateTime(s.timestamp)}</td>
          <td class="mono" style="font-size:11px">${s.duration || '—'}</td>
          <td style="font-size:11px;font-weight:600">${s.set_temp ?? '—'}°C / ${s.set_humidity ?? '—'}%</td>
          <td style="font-weight:700;color:var(--amber)">${avgTempText}</td>
        </tr>`;
    }).join('');
  }catch(e){
    body.innerHTML=noDataRow(6);
  }
}

// ════════════════════════════════════════════════════════
//  USERS
// ════════════════════════════════════════════════════════
async function loadAllPrototypes(){
  const body=document.getElementById('prototypesBody');
  body.innerHTML=`<tr><td colspan="5" style="text-align:center;padding:28px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i></td></tr>`;
  try{
    const j=await(await fetch(`admin_sessions.php?action=get_all_prototypes&t=${Date.now()}`)).json();
    allPrototypes=j.data||[];
    renderPrototypes(allPrototypes);
  }catch(e){ body.innerHTML=noDataRow(5); }
}
function renderPrototypes(data){
  const body=document.getElementById('prototypesBody');
  if(!data.length){ body.innerHTML=noDataRow(5); return; }
  body.innerHTML=data.map(u=>`
    <tr>
      <td class="mono" style="color:var(--teal)">${u.id}</td>
      <td style="font-weight:700">${u.model_name}</td>
      <td class="mono" style="font-size:11px;letter-spacing:.05em">${u.given_code}</td>
      <td><span class="pill pill-${u.status==1?'active':'disabled'}">${u.status==1?'Active':'Disabled'}</span></td>
      <td>
        <div class="d-flex gap-1 flex-wrap">
          <button onclick="openEditPrototype(${u.id},decodeURIComponent('${encodeURIComponent(u.model_name||'')}'),decodeURIComponent('${encodeURIComponent(u.given_code||'')}'),${u.status})" class="act-btn act-edit">Edit</button>
          <button onclick="togglePrototypeStatus(${u.id},decodeURIComponent('${encodeURIComponent(u.model_name||'')}'))" class="act-btn act-view">${u.status==1?'Disable':'Enable'}</button>
          <button onclick="deletePrototype(${u.id},decodeURIComponent('${encodeURIComponent(u.model_name||'')}'))" class="act-btn act-del">Delete</button>
        </div>
      </td>
    </tr>`).join('');
}
function filterPrototypes(){
  const q=(document.getElementById('prototypeSearch')?.value||'').toLowerCase();
  const d=allPrototypes.filter(u=>(u.model_name||'').toLowerCase().includes(q)||(u.given_code||'').toLowerCase().includes(q));
  renderPrototypes(d);
}
function openAddPrototype(){
  document.getElementById('prototypeModalTitle').textContent='Add Device Model';
  document.getElementById('editPrototypeId').value='';
  document.getElementById('modalModelName').value='';
  document.getElementById('modalModelCode').value='';
  document.getElementById('modalStatus').value='1';
  document.getElementById('prototypeModal').style.display='flex';
}
function openEditPrototype(id,modelName,modelCode,status){
  document.getElementById('prototypeModalTitle').textContent='Edit Device Model';
  document.getElementById('editPrototypeId').value=id;
  document.getElementById('modalModelName').value=modelName;
  document.getElementById('modalModelCode').value=modelCode;
  document.getElementById('modalStatus').value=status;
  document.getElementById('prototypeModal').style.display='flex';
}
function closePrototypeModal(){ document.getElementById('prototypeModal').style.display='none'; }
async function savePrototype(){
  const uid=document.getElementById('editPrototypeId').value;
  const action=uid?'update_prototype':'add_prototype';
  const fd=new FormData();
  fd.append('action',action);
  if(uid) fd.append('proto_id',uid);
  fd.append('model_name',document.getElementById('modalModelName').value);
  fd.append('given_code',document.getElementById('modalModelCode').value);
  fd.append('status',document.getElementById('modalStatus').value);
  try{
    const j=await(await fetch('admin_sessions.php',{method:'POST',body:fd})).json();
    if(j.status==='success'){ closePrototypeModal(); loadAllPrototypes(); showToast('success','Success',j.message,3000); }
    else showToast('warning','Error',j.message||'Failed.',3000);
  }catch(e){ showToast('warning','Network Error','Could not reach server.',3000); }
}
async function togglePrototypeStatus(uid,model){
  const r=await Swal.fire({title:`Toggle "${model}"?`,icon:'question',showCancelButton:true,confirmButtonColor:'#0077B6',background:'#fff',color:'#0D1B2A'});
  if(!r.isConfirmed) return;
  const fd=new FormData(); fd.append('action','toggle_prototype_status'); fd.append('proto_id',uid);
  try{
    const j=await(await fetch('admin_sessions.php',{method:'POST',body:fd})).json();
    if(j.status==='success'){
      if (Object.prototype.hasOwnProperty.call(j, 'new_status')) {
        allPrototypes = (allPrototypes || []).map(p =>
          Number(p.id) === Number(uid) ? { ...p, status: Number(j.new_status) } : p
        );
        renderPrototypes(allPrototypes);
      }
      loadAllPrototypes();
      showToast('success','Updated',j.message,3000);
    }
    else showToast('warning','Error',j.message||'Failed.',3000);
  }catch(e){ showToast('warning','Network Error','Could not reach server.',3000); }
}
async function deletePrototype(uid,model){
  const r=await Swal.fire({title:`Delete "${model}"?`,text:'This removes this Unit/Model + Model Code from login access.',icon:'warning',showCancelButton:true,confirmButtonColor:'#E63946',confirmButtonText:'Delete',background:'#fff',color:'#0D1B2A'});
  if(!r.isConfirmed) return;
  const fd=new FormData(); fd.append('action','delete_prototype'); fd.append('proto_id',uid);
  try{
    const j=await(await fetch('admin_sessions.php',{method:'POST',body:fd})).json();
    if(j.status==='success'){ loadAllPrototypes(); showToast('success','Deleted',`Device model "${model}" removed.`,3000); }
    else showToast('warning','Error',j.message||'Failed.',3000);
  }catch(e){ showToast('warning','Network Error','Could not reach server.',3000); }
}

// ════════════════════════════════════════════════════════
//  CALENDAR
// ════════════════════════════════════════════════════════
async function initAdminCalendar(){
  if(adminCal){ adminCal.refetchEvents(); loadAdminSchedules(); return; }
  adminCal=new FullCalendar.Calendar(document.getElementById('adminCalendar'),{
    initialView:'dayGridMonth',
    headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek,listMonth'},
    height:500,nowIndicator:true,
    events:async(info,success,failure)=>{
      try{ const r=await fetch(`admin_sessions.php?action=get_calendar_events&t=${Date.now()}`); const j=await r.json(); success(j.status==='success'?j.data:[]); }
      catch(e){ failure(e); }
    },
    eventClick:(info)=>showEventModal(info.event)
  });
  adminCal.render();
  loadAdminSchedules();
}
async function loadAdminSchedules(){
  try{
    const r=await fetch(`admin_sessions.php?action=get_all_schedules&t=${Date.now()}`);
    const j=await r.json();
    const body=document.getElementById('adminSchedulesBody');
    if(j.status!=='success'){ body.innerHTML=noDataRow(5); return; }
    body.innerHTML=!j.data.length
      ?noDataRow(5)
      :j.data.map(s=>`
        <tr>
          <td style="font-weight:700;color:var(--teal)">${s.prototype_label || '—'}</td>
          <td style="font-weight:600">${s.title}</td>
          <td style="font-size:10.5px;color:var(--text-muted)">${s.sched_date} ${s.sched_time.slice(0,5)}</td>
          <td style="font-size:11px;font-weight:600">${s.set_temp}°C / ${s.set_humidity}%</td>
          <td><span class="pill pill-${normalizeScheduleStatus(s.display_status || s.status)}">${scheduleStatusLabel(s.display_status || s.status)}</span></td>
        </tr>`).join('');
  }catch(e){ document.getElementById('adminSchedulesBody').innerHTML=noDataRow(5); }
}
function showEventModal(event){
  const p=event.extendedProps;
  document.getElementById('evtModalTitle').textContent=event.title.replace(/^. /,'');
  const evRow=(lbl,val)=>`<div class="evt-row"><span class="evt-lbl">${lbl}</span><span class="evt-val">${val}</span></div>`;
  let html='',actions='';
  if(p.type==='schedule'){
    const normalizedStatus=normalizeScheduleStatus(p.status);
    html=evRow('Device Model',p.prototype_label || '—')+evRow('Date/Time',event.startStr?.slice(0,16).replace('T',' '))+evRow('Targets',`${p.set_temp}°C / ${p.set_humidity}%`)+`<div class="evt-row"><span class="evt-lbl">Status</span><span class="pill pill-${normalizedStatus}">${scheduleStatusLabel(p.status)}</span></div>`;
    actions='';
  } else {
    html=evRow('Session #',`<span class="mono" style="color:var(--teal)">#${p.session_id}</span>`)+evRow('Device Model',p.prototype_label || '—')+evRow('Start',event.startStr?.slice(0,16).replace('T',' '))+evRow('End',p.end_time?.slice(0,16)||'—')+`<div class="evt-row"><span class="evt-lbl">Status</span><span class="pill pill-${p.status}">${p.status}</span></div>`;
    if(p.status==='Running') actions=`<button onclick="adminStopSession(${p.session_id},'${(p.prototype_label||'Prototype').replace(/'/g, "\\'")}');closeEventModal()" class="btn-danger" style="width:100%;margin-top:8px;"><i class="fas fa-stop me-2"></i>Force Stop Session</button>`;
  }
  document.getElementById('evtModalBody').innerHTML=html;
  document.getElementById('evtModalActions').innerHTML=actions;
  document.getElementById('eventModal').style.display='flex';
}
function closeEventModal(){ document.getElementById('eventModal').style.display='none'; }

// ════════════════════════════════════════════════════════
//  RECORDS
// ════════════════════════════════════════════════════════
async function loadRecords(){
  document.getElementById('recordsBody').innerHTML=`<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i>Loading sessions…</td></tr>`;
  try{
    const r=await fetch('admin_sessions.php?action=fetch_all_records');
    const j=await r.json();
    if(j.status==='success'&&(j.records||j.data)&&(j.records||j.data).length){
      const recs=j.records||j.data;
      document.getElementById('recordsBody').innerHTML=recs.map(rec=>`
        <tr>
          <td class="mono" style="color:var(--teal)">#${rec.id}</td>
          <td style="font-weight:700">${(rec.prototype_model||'Fishda')} (${rec.prototype_code||'FD2026'})</td>
          <td class="mono">${rec.fish_count||0}</td>
          <td class="mono" style="font-size:11px">${rec.duration||'—'}</td>
          <td style="font-weight:600;color:var(--amber)">${parseFloat(rec.temp_avg)>0?parseFloat(rec.temp_avg).toFixed(1)+'°C':'—'}</td>
          <td style="font-weight:600;color:var(--teal)">${parseFloat(rec.hum_avg)>0?parseFloat(rec.hum_avg).toFixed(1)+'%':'—'}</td>
          <td><span class="pill ${rec.status&&rec.status.includes('Dried')?'pill-Completed':'pill-Running'}">${rec.status||'Completed'}</span></td>
          <td style="font-size:11px;color:var(--text-muted)">${rec.timestamp||'—'}</td>
        </tr>`).join('');
    } else {
      document.getElementById('recordsBody').innerHTML=`<tr><td colspan="8" style="text-align:center;padding:48px;color:var(--text-muted)">
        <div style="font-size:28px;margin-bottom:10px">📋</div>
        <div style="font-size:13px;font-weight:700;color:var(--text-primary)">No completed sessions yet</div>
        <div style="font-size:11.5px;margin-top:4px">Sessions appear here once drying cycles are completed.</div>
      </td></tr>`;
    }
  }catch(e){ document.getElementById('recordsBody').innerHTML=noDataRow(9); }
}

// ════════════════════════════════════════════════════════
//  INQUIRIES
// ════════════════════════════════════════════════════════
async function loadInquiries(){
  const body = document.getElementById('inquiriesBody');
  const meta = document.getElementById('inqMeta');
  if(!body) return;
  body.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i>Loading inquiries…</td></tr>`;
  try {
    const r = await fetch(`admin_sessions.php?action=get_inquiries&t=${Date.now()}`);
    const j = await r.json();
    const rows = j.data || [];
    if (meta) {
      const totalText = `Total: ${Number.isFinite(j.total) ? j.total : rows.length}`;
      const dbText = j.source_db ? ` | Source DB: ${j.source_db}` : '';
      meta.textContent = `${totalText}${dbText}`;
    }
    if (!rows.length) {
      body.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:48px;color:var(--text-muted)">
        <div style="font-size:28px;margin-bottom:10px"><i class="fas fa-envelope-open-text"></i></div>
        <div style="font-size:13px;font-weight:700;color:var(--text-primary)">No inquiries yet</div>
        <div style="font-size:11.5px;margin-top:4px">Landing page contact us messages will appear here.</div>
      </td></tr>`;
      return;
    }

    body.innerHTML = rows.map(row => `
      <tr>
        <td class="mono" style="color:var(--teal)">#${row.id}</td>
        <td style="font-weight:700">${escapeHtml(row.name || '—')}</td>
        <td style="font-size:11px;color:var(--text-muted)">${escapeHtml(row.contact || '—')}</td>
        <td style="max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.5;">
          <button type="button" class="act-btn act-view" onclick="viewInquiryMessage(this.dataset.inquiry)" data-inquiry="${encodeURIComponent(JSON.stringify({
            id: row.id,
            name: row.name || '—',
            contact: row.contact || '—',
            message: row.message || '—',
            status: row.status || 'pending',
            created_at: row.created_at || '—'
          }))}">View Message</button>
        </td>
        <td><span class="pill pill-${row.status === 'pending' ? 'Running' : 'Completed'}">${escapeHtml(row.status || 'pending')}</span></td>
        <td style="font-size:11px;color:var(--text-muted)">${escapeHtml((row.created_at || '').slice(0,16).replace('T',' ') || '—')}</td>
        <td>
          <div class="d-flex gap-1 flex-wrap">
            <button onclick="markInquiryStatus(${row.id},'read')" class="act-btn act-view">Mark Read</button>
            <button onclick="markInquiryStatus(${row.id},'replied')" class="act-btn act-edit">Mark Replied</button>
          </div>
        </td>
      </tr>
    `).join('');
  } catch (e) {
    if (meta) meta.textContent = 'Could not load inquiry source.';
    body.innerHTML = noDataRow(7);
  }
}


  function viewInquiryMessage(encodedInquiry){
    const data = JSON.parse(decodeURIComponent(encodedInquiry));
    const safeName = escapeHtml(data.name || '—');
    const safeContact = escapeHtml(data.contact || '—');
    const safeStatus = escapeHtml(data.status || 'pending');
    const safeDate = escapeHtml((data.created_at || '—').toString().slice(0,16).replace('T',' ') || '—');
    const safeMessage = escapeHtml(data.message || '—').replace(/\n/g, '<br>');

    document.getElementById('inqModalTitle').textContent = `Inquiry #${data.id || '—'}`;
    document.getElementById('inqModalBody').innerHTML = `
      <div class="evt-row"><span class="evt-lbl">Name</span><span class="evt-val">${safeName}</span></div>
      <div class="evt-row"><span class="evt-lbl">Contact</span><span class="evt-val">${safeContact}</span></div>
      <div class="evt-row"><span class="evt-lbl">Status</span><span class="pill pill-${data.status === 'pending' ? 'Running' : 'Completed'}">${safeStatus}</span></div>
      <div class="evt-row"><span class="evt-lbl">Sent</span><span class="evt-val">${safeDate}</span></div>
      <div class="evt-row"><span class="evt-lbl">Message</span></div>
      <div style="margin-top:4px;padding:14px 15px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2);color:var(--text-primary);line-height:1.7;white-space:normal;word-break:break-word;">${safeMessage}</div>
    `;
    document.getElementById('inquiryModal').style.display='flex';
  }

  function closeInquiryModal(){ document.getElementById('inquiryModal').style.display='none'; }
async function markInquiryStatus(inquiryId, status){
  const fd = new FormData();
  fd.append('action', 'mark_inquiry_status');
  fd.append('inquiry_id', inquiryId);
  fd.append('status', status);

  try {
    const j = await (await fetch('admin_sessions.php', { method: 'POST', body: fd })).json();
    if (j.status === 'success') {
      showToast('success', 'Inquiry Updated', j.message || 'Status updated.', 2000);
      loadInquiries();
    } else {
      showToast('warning', 'Update Failed', j.message || 'Could not update inquiry.', 3000);
    }
  } catch (e) {
    showToast('warning', 'Network Error', 'Could not update inquiry status.', 3000);
  }
}

// ════════════════════════════════════════════════════════
//  TOAST
// ════════════════════════════════════════════════════════
function showToast(type,title,msg,dur=4000){
  const el=document.createElement('div');
  el.className=`toast-item ${type}`;
  el.onclick=()=>el.remove();
  el.innerHTML=`<div><div class="toast-title">${title}</div><div class="toast-msg">${msg}</div></div>`;
  document.getElementById('toastZone').appendChild(el);
  if(dur>0) setTimeout(()=>{ if(el.parentNode) el.remove(); },dur);
}

// ════════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════════
function noDataRow(cols){
  return `<tr><td colspan="${cols}" style="text-align:center;padding:32px;color:var(--text-muted);font-size:12px;">No data found.</td></tr>`;
}
function escapeHtml(value){
  return String(value)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}
function logoutAdmin(){
  Swal.fire({title:'Logout?',icon:'question',showCancelButton:true,confirmButtonColor:'#E63946',background:'#fff',color:'#0D1B2A'})
    .then(r=>{ if(r.isConfirmed) window.location.href='../auth/logout.php'; });
}

// ════════════════════════════════════════════════════════
//  INIT
// ════════════════════════════════════════════════════════
loadStats();
loadDailyTrends();
loadRecentSessions();
setInterval(()=>{ loadStats(); },30000);
</script>
</body>
</html>