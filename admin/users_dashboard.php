<?php
// ============================================================
//  users_dashboard.php — FINALIZED
//  Fixes applied:
//   1. Session guard uses $_SESSION['proto_id'] — refresh stays logged in
//   2. session_cache_limiter prevents browser caching login redirect
//   3. pollLiveData() handles COOLDOWN phase from server
//   4. updatePhase() — Drying + Cooldown phases with correct icons
//   5. updateHWChips() — Fan labeled "Fan (Heat)" as heat source
//   6. Fan ON during Heating & Drying phases shown correctly in UI
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
  session_cache_limiter('private_no_expire');
  session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
  ]);
  session_start();
}
include('../database/dbcon.php');

// ── Prototype-based session guard ──
if (!isset($_SESSION['proto_id']) && isset($_SESSION['model_name'], $_SESSION['given_code'])) {
  try {
    $restore = $dbh->prepare(
      "SELECT id FROM tbl_prototypes WHERE model_name=:m AND given_code=:c LIMIT 1"
    );
    $restore->execute([
      ':m' => trim((string)$_SESSION['model_name']),
      ':c' => trim((string)$_SESSION['given_code']),
    ]);
    $row = $restore->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['id'])) {
      $_SESSION['proto_id'] = (int)$row['id'];
    }
  } catch (Exception $e) {}
}

if (!isset($_SESSION['proto_id'])) {
    header('Location: ../index.php');
    exit;
}
$proto_id   = $_SESSION['proto_id'];
$model_name = htmlspecialchars($_SESSION['model_name']);
$given_code = htmlspecialchars($_SESSION['given_code']);

// Log page visit into tbl_sessions
if (!isset($_SESSION['session_logged'])) {
    try {
        $dbh->prepare("INSERT INTO tbl_sessions (prototype_id, ip_address) VALUES (:pid, :ip)")
            ->execute([':pid' => $proto_id, ':ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        $_SESSION['session_logged'] = true;
    } catch(Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>APS Prototype Dashboard | <?= $model_name ?> (<?= $given_code ?>)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Mulish:wght@300;400;500;600;700&family=Source+Code+Pro:wght@400;500&display=swap" rel="stylesheet">
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css' rel='stylesheet'>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>

  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
  :root{
    --sidebar-bg:#1C2B1F;
    --sidebar-w:266px;
    --main-bg:#F5F0E8;
    --surface:#FEFCF8;
    --surface-2:#F2EDE1;
    --forest:#1C2B1F;
    --forest-mid:#243228;
    --teal:#2A9D8F;
    --teal-light:#3BB5A5;
    --golden:#E9A825;
    --amber:#E76F30;
    --terracotta:#C1440E;
    --sand:#D4A96A;
    --parchment:#EDD9A3;
    --seafoam:#52B69A;
    --text-primary:#1A1208;
    --text-secondary:#4A3728;
    --text-muted:#8C7355;
    --text-on-dark:#EDE0C8;
    --text-on-dark-muted:#8FAF95;
    --border:#DDD0B8;
    --border-strong:#C4B090;
    --shadow-sm:0 1px 4px rgba(28,18,8,.07);
    --shadow-md:0 4px 16px rgba(28,18,8,.10);
    --shadow-lg:0 8px 32px rgba(28,18,8,.14);
    --shadow-xl:0 16px 56px rgba(28,18,8,.18);
  }
  body{font-family:'Mulish',sans-serif;background:var(--main-bg);color:var(--text-primary);min-height:100vh;overflow-x:hidden;}
  body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3CfeColorMatrix type='saturate' values='0'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");
    opacity:.4;}

  /* ── SIDEBAR ──────────────────────────────── */
  .sidebar{
    position:fixed;left:0;top:0;width:var(--sidebar-w);height:100vh;z-index:200;
    background:var(--sidebar-bg);
    display:flex;flex-direction:column;overflow:hidden;
    box-shadow:4px 0 40px rgba(0,0,0,.28);
  }
  .sidebar::before{
    content:'';position:absolute;inset:0;pointer-events:none;
    background:
      radial-gradient(ellipse 80% 35% at 50% 0%,rgba(42,157,143,.09) 0%,transparent 55%),
      radial-gradient(ellipse 50% 50% at 10% 100%,rgba(233,168,37,.05) 0%,transparent 60%),
      repeating-linear-gradient(0deg,transparent,transparent 48px,rgba(255,255,255,.012) 48px,rgba(255,255,255,.012) 49px);
  }
  .sidebar-frost{position:absolute;right:0;top:0;bottom:0;width:1px;background:linear-gradient(180deg,transparent,rgba(42,157,143,.28) 35%,rgba(233,168,37,.12) 70%,transparent);}
  .sidebar-brand{padding:22px 20px 16px;border-bottom:1px solid rgba(255,255,255,.07);position:relative;z-index:1;}
  .brand-logo{width:44px;height:44px;border-radius:10px;border:2px solid rgba(42,157,143,.42);box-shadow:0 0 20px rgba(42,157,143,.22);object-fit:cover;}
  .brand-title{font-family:'Playfair Display',serif;font-size:13.5px;font-weight:700;color:#EDE0C8;letter-spacing:.02em;line-height:1.2;}
  .brand-sub{font-size:9.5px;font-weight:600;color:var(--text-on-dark-muted);letter-spacing:.12em;text-transform:uppercase;margin-top:2px;}
  .nav-clock{font-family:'Source Code Pro',monospace;font-size:11px;color:var(--teal-light);background:rgba(42,157,143,.08);border:1px solid rgba(42,157,143,.18);border-radius:6px;padding:7px 14px;text-align:center;margin:12px 16px 4px;letter-spacing:.10em;}
  .nav-section{padding:14px 22px 5px;font-size:9px;font-weight:700;color:rgba(255,255,255,.22);letter-spacing:.18em;text-transform:uppercase;}
  .nav-item{display:flex;align-items:center;gap:11px;padding:11px 22px;font-size:12.5px;font-weight:600;color:var(--text-on-dark-muted);cursor:pointer;border-left:3px solid transparent;transition:all .18s;text-decoration:none;font-family:'Mulish',sans-serif;}
  .nav-item:hover{background:rgba(255,255,255,.04);color:var(--text-on-dark);border-left-color:rgba(42,157,143,.4);}
  .nav-item.active{background:linear-gradient(90deg,rgba(42,157,143,.13),rgba(42,157,143,.04));color:#5ECFBE;border-left-color:var(--teal-light);}
  .nav-item i{width:16px;text-align:center;font-size:13px;}
  .sidebar-user{margin-top:auto;padding:14px 16px;border-top:1px solid rgba(255,255,255,.07);position:relative;z-index:1;}
  .user-card{background:rgba(42,157,143,.07);border:1px solid rgba(42,157,143,.16);border-radius:12px;padding:12px;}
  .user-avatar{width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,var(--golden),var(--amber));display:flex;align-items:center;justify-content:center;font-family:'Playfair Display',serif;font-weight:800;font-size:15px;color:#fff;}
  .online-dot{width:7px;height:7px;border-radius:50%;background:var(--teal-light);box-shadow:0 0 6px var(--teal-light);display:inline-block;animation:livePulse 2s ease-in-out infinite;}
  .online-dot.offline{background:#8BA7C4;box-shadow:none;animation:none;}
  .online-dot.inactive{background:var(--terracotta);box-shadow:none;animation:none;}
  .proto-online-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;background:rgba(42,157,143,.08);border:1px solid rgba(42,157,143,.18);color:var(--teal);}
  .proto-online-pill.offline{background:rgba(139,167,196,.12);border-color:rgba(139,167,196,.22);color:#8BA7C4;}
  .proto-online-pill.inactive{background:rgba(193,68,14,.10);border-color:rgba(193,68,14,.25);color:var(--terracotta);}
  @keyframes livePulse{0%,100%{opacity:1}50%{opacity:.4}}
  .btn-logout{background:rgba(193,68,14,.1);border:1px solid rgba(193,68,14,.22);color:#E07050;border-radius:7px;padding:6px 12px;font-size:10px;font-weight:700;cursor:pointer;transition:.18s;width:100%;text-align:center;margin-top:8px;font-family:'Mulish',sans-serif;letter-spacing:.04em;text-transform:uppercase;}
  .btn-logout:hover{background:rgba(193,68,14,.2);}

  /* ── MAIN ─────────────────────────────────── */
  .main{margin-left:var(--sidebar-w);min-height:100vh;background:var(--main-bg);position:relative;z-index:1;}
  .content-wrap{padding:28px 32px;}
  .page-header{margin-bottom:24px;}
  .page-title{font-family:'Playfair Display',serif;font-size:24px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em;}
  .page-title::after{content:'';display:block;width:40px;height:3px;background:linear-gradient(90deg,var(--teal),var(--golden));border-radius:99px;margin-top:7px;}
  .page-sub{font-size:12.5px;color:var(--text-muted);margin-top:6px;font-weight:500;}

  /* ── SMART FILTER BAR ─────────────────────── */
  .filter-bar{background:var(--surface);border-bottom:2px solid var(--border);padding:11px 32px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:var(--shadow-sm);}
  .filter-label{font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.10em;white-space:nowrap;}
  .filter-divider{width:1px;height:20px;background:var(--border);flex-shrink:0;}
  
  /* Device Status Display */
  .device-status-display{display:flex;align-items:center;}
  .device-status-pill{
    display:flex;align-items:center;gap:7px;
    padding:6px 14px;border-radius:7px;font-size:11.5px;font-weight:700;
    background:rgba(193,68,14,.07);border:1px solid rgba(193,68,14,.16);color:#9C3510;
    letter-spacing:.03em;transition:all .2s;
  }
  .device-status-pill.online{
    background:rgba(42,157,143,.08);border-color:rgba(42,157,143,.2);color:#1E7A6E;
  }
  .device-status-pill.offline{
    background:rgba(193,68,14,.07);border:1px solid rgba(193,68,14,.16);color:#9C3510;
  }
  .device-status-pill.inactive{
    background:rgba(149,165,166,.08);border:1px solid rgba(149,165,166,.2);color:#5D6D6E;
  }
  .status-dot{
    width:8px;height:8px;border-radius:50%;
    background:#E76F30;box-shadow:0 0 6px rgba(231,111,48,.4);
  }
  .device-status-pill.online .status-dot{
    background:#52B69A;box-shadow:0 0 8px rgba(82,182,154,.5);
    animation:livePulse 1.6s infinite;
  }
  .device-status-pill.offline .status-dot{
    background:#E76F30;box-shadow:none;
  }
  .device-status-pill.inactive .status-dot{
    background:#95A5A6;box-shadow:none;
  }
  
  .session-status-badge{
    display:flex;align-items:center;gap:6px;margin-left:auto;
    padding:5px 12px;border-radius:6px;font-size:11px;font-weight:700;
    background:rgba(193,68,14,.07);border:1px solid rgba(193,68,14,.16);color:#9C3510;
    letter-spacing:.04em;text-transform:uppercase;
  }
  .session-status-badge.running{background:rgba(42,157,143,.08);border-color:rgba(42,157,143,.2);color:#1E7A6E;}
  .session-dot{width:7px;height:7px;border-radius:50%;animation:livePulse 1.6s infinite;}

  /* ── STAT CARDS ──────────────────────────── */
  .stat-card{background:var(--surface);border:1px solid var(--border);border-top:3px solid var(--border);border-radius:14px;padding:18px 20px;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);transition:box-shadow .2s,transform .2s;}
  .stat-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
  .stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;margin-bottom:12px;}
  .stat-value{font-family:'Playfair Display',serif;font-size:30px;font-weight:700;line-height:1;color:var(--text-primary);}
  .stat-label{font-size:10.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.10em;margin-top:4px;}
  .stat-accent{position:absolute;bottom:-18px;right:-18px;width:64px;height:64px;border-radius:50%;filter:blur(24px);opacity:.13;}

  /* ── CONTROL PANEL ─────────────────────────── */
  .control-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;box-shadow:var(--shadow-sm);}
  .control-section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:6px;}
  .range-group{margin-bottom:20px;}
  .range-label{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
  .range-name{font-size:12.5px;font-weight:600;color:var(--text-secondary);}
  .range-val{font-family:'Playfair Display',serif;font-size:22px;font-weight:700;color:var(--teal);}
  .range-val.hum{color:var(--seafoam);}
  input[type=range]{-webkit-appearance:none;appearance:none;width:100%;height:5px;background:var(--surface-2);border-radius:99px;outline:none;border:1px solid var(--border);}
  input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:19px;height:19px;border-radius:50%;background:var(--teal);box-shadow:0 2px 8px rgba(42,157,143,.38);cursor:pointer;border:2px solid #fff;}
  input[type=range].hum-range::-webkit-slider-thumb{background:var(--seafoam);box-shadow:0 2px 8px rgba(82,182,154,.38);}
  .btn-start{background:linear-gradient(135deg,var(--teal),var(--teal-light));border:none;border-radius:11px;padding:13px 24px;font-size:13px;font-weight:700;color:#fff;cursor:pointer;width:100%;transition:.22s;box-shadow:0 4px 20px rgba(42,157,143,.30);font-family:'Mulish',sans-serif;letter-spacing:.03em;text-transform:uppercase;}
  .btn-start:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(42,157,143,.45);}
  .btn-stop{background:linear-gradient(135deg,var(--terracotta),#9C3510);border:none;border-radius:11px;padding:13px 24px;font-size:13px;font-weight:700;color:#fff;cursor:pointer;width:100%;transition:.22s;box-shadow:0 4px 20px rgba(193,68,14,.28);font-family:'Mulish',sans-serif;letter-spacing:.03em;text-transform:uppercase;}
  .btn-stop:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(193,68,14,.42);}

  /* ── LIVE MONITOR ─────────────────────────── */
  .monitor-card{background:linear-gradient(160deg,var(--forest-mid) 0%,var(--forest) 100%);border:1px solid rgba(42,157,143,.15);border-radius:14px;padding:24px;color:#fff;box-shadow:var(--shadow-md);position:relative;overflow:hidden;}
  .monitor-card::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 90% 50% at 50% 0%,rgba(42,157,143,.09) 0%,transparent 60%);pointer-events:none;}
  /* Fish-scale dot overlay on monitor card */
  .monitor-card::after{content:'';position:absolute;inset:0;pointer-events:none;opacity:.035;
    background-image:radial-gradient(circle at 50% 50%,rgba(233,168,37,.6) 1px,transparent 1px);
    background-size:16px 16px;}
  .gauge-block{text-align:center;position:relative;z-index:1;}
  .gauge-ring{position:relative;width:110px;height:110px;margin:0 auto 10px;}
  .gauge-ring svg{width:100%;height:100%;}
  .gauge-text{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;}
  .gauge-val{font-family:'Playfair Display',serif;font-size:20px;font-weight:700;color:#fff;line-height:1;}
  .gauge-unit{font-size:9.5px;color:var(--text-on-dark-muted);}
  .gauge-name{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--text-on-dark-muted);}
  .hw-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:7px;padding:4px 10px;font-size:10px;font-weight:600;color:var(--text-on-dark);margin:3px;}
  .dot-on{width:7px;height:7px;border-radius:50%;background:var(--teal-light);box-shadow:0 0 6px var(--teal-light);}
  .dot-off{width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,.2);}

  /* ── PHASE BADGE ──────────────────────────── */
  .phase-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:6px;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;}
  .phase-Heating{background:rgba(231,111,48,.15);color:#8C4010;border:1px solid rgba(231,111,48,.3);}
  .phase-Drying{background:rgba(42,157,143,.12);color:#1E7A6E;border:1px solid rgba(42,157,143,.25);}
  .phase-Exhaust{background:rgba(233,168,37,.15);color:#7D5800;border:1px solid rgba(233,168,37,.3);}
  .phase-Cooldown{background:rgba(82,182,154,.15);color:#2D7A62;border:1px solid rgba(82,182,154,.3);}
  .phase-Idle{background:rgba(140,115,85,.1);color:#5A3E25;border:1px solid rgba(140,115,85,.2);}
  .phase-Done{background:rgba(42,157,143,.15);color:#1E7A6E;border:1px solid rgba(42,157,143,.3);}

  /* ── COOLDOWN BANNER ────────────────────────── */
  .cooldown-banner{
    background:linear-gradient(135deg,rgba(42,157,143,.07),rgba(82,182,154,.05));
    border:1.5px solid rgba(42,157,143,.28);border-radius:14px;
    padding:18px 22px;display:none;animation:fadeUp .4s ease;
  }

  /* ── DRYING PROGRESS ──────────────────────── */
  .drying-progress{background:var(--surface-2);border-radius:99px;height:7px;margin:8px 0 3px;overflow:hidden;}
  .drying-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--teal),var(--golden),var(--seafoam));transition:width .9s ease;}

  /* ── FISH READY BANNER ─────────────────────── */
  .fish-ready{
    background:linear-gradient(135deg,rgba(82,182,154,.08),rgba(42,157,143,.06));
    border:1.5px solid rgba(82,182,154,.3);border-radius:14px;
    padding:18px 22px;display:none;animation:fadeUp .4s ease;
  }
  @keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

  /* ── DATA TABLE ──────────────────────────── */
  .data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
  .data-table th{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.10em;color:var(--text-muted);padding:11px 16px;border-bottom:2px solid var(--border);text-align:left;background:var(--surface-2);font-family:'Mulish',sans-serif;}
  .data-table td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
  .data-table tr:last-child td{border-bottom:none;}
  .data-table tr:hover td{background:#F8F3EA;}
  .section-scroll{overflow-x:auto;}

  /* ── PILLS ───────────────────────────────── */
  .pill{display:inline-flex;align-items:center;padding:4px 10px;border-radius:5px;font-size:9.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap;}
  .pill-Running{background:rgba(42,157,143,.10);color:#1E7A6E;border:1px solid rgba(42,157,143,.22);}
  .pill-Completed{background:rgba(42,157,143,.10);color:#1E7A6E;border:1px solid rgba(42,157,143,.22);}
  .pill-Interrupted{background:rgba(193,68,14,.08);color:#9C3510;border:1px solid rgba(193,68,14,.2);}
  .pill-Scheduled{background:rgba(124,92,191,.08);color:#6344A0;border:1px solid rgba(124,92,191,.2);}
  .pill-Done{background:rgba(82,182,154,.10);color:#2D7A62;border:1px solid rgba(82,182,154,.22);}

  /* ── SCHED ITEM ──────────────────────────── */
  .sched-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
  .sched-item:last-child{border-bottom:none;}

  /* ── GLASS CARD ──────────────────────────── */
  .glass-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);}
  .card-head{padding:18px 22px 0;display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
  .card-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--text-muted);display:flex;align-items:center;gap:6px;}
  .card-title-dot{width:6px;height:6px;border-radius:2px;}

  /* ── TOAST ───────────────────────────────── */
  #toastZone{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:360px;}
  .toast-item{background:var(--surface);border-radius:10px;padding:14px 16px;display:flex;align-items:flex-start;gap:10px;box-shadow:var(--shadow-xl);border-left:3.5px solid var(--border);animation:toastIn .28s ease;cursor:pointer;border:1px solid var(--border);}
  .toast-item.success{border-left-color:var(--teal);}
  .toast-item.warning{border-left-color:var(--golden);}
  .toast-item.critical{border-left-color:var(--terracotta);}
  .toast-item.info{border-left-color:var(--teal-light);}
  .toast-title{font-size:12.5px;font-weight:700;color:var(--text-primary);font-family:'Playfair Display',serif;}
  .toast-msg{font-size:11.5px;color:var(--text-muted);}
  @keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}

  /* ── MODAL ───────────────────────────────── */
  .modal-backdrop{position:fixed;inset:0;background:rgba(28,18,8,.52);backdrop-filter:blur(8px);z-index:5000;display:flex;align-items:center;justify-content:center;padding:16px;}
  .modal-panel{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:30px;width:100%;max-width:460px;animation:fadeUp .28s ease;box-shadow:var(--shadow-xl);}
  .modal-title{font-family:'Playfair Display',serif;font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:20px;}
  .form-row{margin-bottom:14px;}
  .form-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.10em;color:var(--text-muted);margin-bottom:5px;display:block;}
  .form-input{width:100%;background:var(--surface-2);border:1.5px solid var(--border);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--text-primary);font-family:'Mulish',sans-serif;outline:none;transition:.2s;}
  .form-input:focus{border-color:var(--teal);background:#fff;}

  /* ── NOTIFICATION BELL (FAB) ──────────────── */
  .notif-bell{position:fixed;bottom:28px;right:28px;z-index:1000;width:52px;height:52px;background:linear-gradient(135deg,var(--teal),var(--golden));border-radius:14px;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 20px rgba(42,157,143,.38);font-size:19px;color:#fff;transition:.25s;}
  .notif-bell:hover{transform:scale(1.1);box-shadow:0 6px 28px rgba(42,157,143,.52);}
  .notif-count{position:absolute;top:-4px;right:-4px;background:var(--terracotta);color:#fff;font-size:9px;font-weight:900;border-radius:6px;padding:2px 6px;min-width:18px;text-align:center;}

  /* ── SKELETON ────────────────────────────── */
  .skeleton{background:linear-gradient(90deg,var(--surface-2) 25%,#EAE3D2 50%,var(--surface-2) 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:6px;}
  @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

  /* ── TAB ─────────────────────────────────── */
  .tab-section{display:none;}
  .tab-section.active{display:block;animation:fadeTabIn .22s ease;}
  @keyframes fadeTabIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

  /* ── FC CALENDAR ─────────────────────────── */
  .fc{color:var(--text-primary)!important;font-family:'Mulish',sans-serif!important;}
  .fc-toolbar-title{font-family:'Playfair Display',serif!important;font-size:15px!important;font-weight:700!important;color:var(--text-primary)!important;}
  .fc-button-primary{background:var(--surface-2)!important;border-color:var(--border)!important;color:var(--text-secondary)!important;border-radius:7px!important;font-size:11px!important;font-weight:700!important;font-family:'Mulish',sans-serif!important;box-shadow:none!important;}
  .fc-button-primary:hover{background:var(--parchment)!important;color:var(--text-primary)!important;}
  .fc-button-primary:not(:disabled).fc-button-active{background:var(--teal)!important;border-color:var(--teal)!important;color:#fff!important;}
  .fc-day-today{background:rgba(42,157,143,.04)!important;}
  .fc-event{border-radius:4px!important;font-size:10px!important;font-weight:700!important;cursor:pointer!important;}
  .fc-col-header-cell{color:var(--text-muted)!important;font-size:10px!important;font-weight:700!important;text-transform:uppercase!important;}
  .fc-daygrid-day-number{color:var(--text-secondary)!important;font-size:11.5px!important;}
  .fc-scrollgrid,.fc-scrollgrid td,.fc-scrollgrid th{border-color:var(--border)!important;}

  /* ── MISC ────────────────────────────────── */
  ::-webkit-scrollbar{width:4px;height:4px;}
  ::-webkit-scrollbar-track{background:transparent;}
  ::-webkit-scrollbar-thumb{background:var(--border-strong);border-radius:99px;}
  .mono{font-family:'Source Code Pro',monospace;font-size:11px;}
  .btn-outline-cmd{background:rgba(42,157,143,.07);border:1.5px solid rgba(42,157,143,.2);color:var(--teal);border-radius:7px;padding:6px 14px;font-size:10.5px;font-weight:700;cursor:pointer;font-family:'Mulish',sans-serif;transition:.18s;}
  .btn-outline-cmd:hover{background:rgba(42,157,143,.14);}
  .btn-primary{background:linear-gradient(135deg,var(--teal),var(--teal-light));border:none;border-radius:8px;padding:9px 18px;font-size:12px;font-weight:700;color:#fff;cursor:pointer;transition:.2s;box-shadow:0 3px 14px rgba(42,157,143,.28);font-family:'Mulish',sans-serif;}
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(42,157,143,.40);}
  .act-btn{padding:5px 11px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;border:none;transition:all .18s;font-family:'Mulish',sans-serif;}
  .act-view{background:rgba(42,157,143,.08);color:#1E7A6E;border:1px solid rgba(42,157,143,.2);}
  .act-del{background:rgba(193,68,14,.07);color:#9C3510;border:1px solid rgba(193,68,14,.16);}
</style>
</head>
<body>
<div id="toastZone"></div>

<!-- ═══════════ SIDEBAR ═══════════ -->
<div class="sidebar">
  <div class="sidebar-frost"></div>
  <div class="sidebar-brand">
    <div class="d-flex align-items-center gap-3 mb-1">
        <div class="user-avatar\"><?= strtoupper(substr($model_name,0,1)) ?></div>
      <div>
        <div class="brand-title">Smart Fish</div>
        <div class="brand-sub">Drying System</div>
      </div>
    </div>
  </div>
  <div class="nav-clock" id="navClock">00:00:00</div>
  <div class="nav-section">Main Menu</div>
  <a class="nav-item active" id="link-control" onclick="showTab('control')"><i class="fas fa-sliders"></i>Drying Control</a>
  <a class="nav-item" id="link-monitor" onclick="showTab('monitor')"><i class="fas fa-gauge-high"></i>Live Monitor</a>
  <a class="nav-item" id="link-history" onclick="showTab('history')"><i class="fas fa-clock-rotate-left"></i>Drying Records</a>
  <a class="nav-item" id="link-schedule" onclick="showTab('schedule')"><i class="fas fa-calendar-days"></i>Drying Calendar</a>
  <div class="sidebar-user">
    <div class="user-card">
      <div class="d-flex align-items-center gap-2 mb-2">
        <div class="user-avatar"><?= strtoupper(substr($model_name,0,1)) ?></div>
        <div>
          <div style="font-size:12.5px;font-weight:700;color:var(--text-on-dark)"><?= $model_name ?></div>
          <div class="proto-online-pill" id="protoOnlineSidebar"><span class="online-dot" id="protoOnlineDot"></span><span id="protoOnlineText">Checking...</span></div>
        </div>
      </div>
      <div onclick="logoutUser()" class="btn-logout"><i class="fas fa-right-from-bracket me-1"></i>Logout</div>
    </div>
  </div>
</div>

 <div class="main">

  <!-- Smart Filter Bar -->
  <div class="filter-bar">
    <span class="filter-label">Device Status:</span>
    <div class="device-status-display" id="headerDeviceStatus">
      <span class="device-status-pill" id="deviceStatusPill">
        <span class="status-dot" id="deviceStatusDot"></span>
        <span id="deviceStatusText">Checking...</span>
      </span>
    </div>
    <div class="filter-divider"></div>
    <span class="filter-label">Session:</span>
    <div class="session-status-badge" id="sessionStatusBadge">
      <span class="session-dot" style="background:#8BA7C4;box-shadow:none;"></span>
      <span id="sessionStatusText">No Active Session</span>
    </div>
    <button onclick="checkExistingSession()" style="margin-left:8px;padding:2px 6px;font-size:9px;background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.2);border-radius:4px;cursor:pointer;" title="Refresh session status">🔄</button>
  </div>

  <div class="content-wrap">

    <!-- ════════════════ TAB: DRYING CONTROL ════════════════ -->
    <div id="tab-control" class="tab-section active">
      <div class="page-header">
        <div class="page-title">🐟 Drying Control</div>
        <div class="page-sub">Configure temperature &amp; humidity targets, then start the prototype drying session.</div>
      </div>

      <!-- Fish Ready Banner -->
      <div class="fish-ready mb-3" id="fishReadyBanner">
        <div class="d-flex align-items-center gap-3">
          <span style="font-size:34px">🎉</span>
          <div>
            <div style="font-size:15px;font-weight:800;color:var(--seafoam)">Fish is Ready — Targets Reached!</div>
            <div style="font-size:12px;color:var(--text-muted)" id="fishReadyMsg">Targets reached. Cooldown in progress, system will auto-resume.</div>
          </div>
          <button onclick="document.getElementById('fishReadyBanner').style.display='none'" style="margin-left:auto;background:transparent;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;">✕</button>
        </div>
      </div>

      <!-- Cooldown Banner -->
      <div class="cooldown-banner mb-3" id="cooldownBanner">
        <div class="d-flex align-items-center gap-3">
          <span style="font-size:34px">🌀</span>
          <div>
            <div style="font-size:15px;font-weight:800;color:var(--teal)">Cooldown Phase Active</div>
            <div style="font-size:12px;color:var(--text-muted)" id="cooldownMsg">System is cooling down. Fan will restart automatically.</div>
          </div>
        </div>
      </div>

      <!-- Live Stats Row -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(42,157,143,.1);color:var(--teal)"><i class="fas fa-temperature-high"></i></div>
            <div class="stat-value" id="liveTemp">—</div>
            <div class="stat-label" id="tempLabel">Live Temp °C</div>
            <div class="stat-accent" style="background:var(--teal)"></div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(82,182,154,.1);color:var(--seafoam)"><i class="fas fa-droplet"></i></div>
            <div class="stat-value" id="liveHum" style="color:var(--seafoam)">—</div>
            <div class="stat-label" id="humLabel">Live Humidity %</div>
            <div class="stat-accent" style="background:var(--seafoam)"></div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(244,162,97,.1);color:var(--golden)"><i class="fas fa-clock"></i></div>
            <div class="stat-value mono" id="elapsedTime" style="font-size:22px;color:var(--golden)">00:00:00</div>
            <div class="stat-label">Session Duration</div>
            <div class="stat-accent" style="background:var(--amber)"></div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(123,97,255,.1);color:#6344A0"><i class="fas fa-fish"></i></div>
            <div class="stat-value" id="myTotalSessionsCtrl" style="color:#6344A0">—</div>
            <div class="stat-label">Total Sessions</div>
            <div class="stat-accent" style="background:#7b61ff"></div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <!-- Control Panel -->
        <div class="col-lg-4">
          <div class="control-card">
            <div class="control-section-title"><i class="fas fa-sliders" style="color:var(--teal)"></i>Set Parameters</div>
            <div class="range-group">
              <div class="range-label">
                <span class="range-name"><i class="fas fa-temperature-high me-2" style="color:var(--teal)"></i>Target Temperature</span>
                <span class="range-val" id="tempVal">50°C</span>
              </div>
              <input type="range" id="tempRange" min="30" max="80" value="50" oninput="document.getElementById('tempVal').textContent=this.value+'°C'">
            </div>
            <div class="range-group">
              <div class="range-label">
                <span class="range-name"><i class="fas fa-droplet me-2" style="color:var(--seafoam)"></i>Target Humidity</span>
                <span class="range-val hum" id="humVal">30%</span>
              </div>
              <input type="range" id="humRange" min="10" max="80" value="30" class="hum-range" oninput="document.getElementById('humVal').textContent=this.value+'%'">
            </div>
            <div id="controlSection">
              <button class="btn-start" id="startBtn" onclick="startSession()"><i class="fas fa-play me-2"></i>Start Drying Session</button>
            </div>
          </div>
        </div>

        <!-- Drying Progress -->
        <div class="col-lg-4">
          <div class="control-card" style="height:100%;">
            <div class="control-section-title">
              <i class="fas fa-chart-bar" style="color:var(--seafoam)"></i>Drying Progress
              <button class="btn-stop" id="stopBtn" onclick="stopSession()" style="display:none;margin-left:auto;padding:4px 8px;font-size:10px;background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.2);border-radius:6px;cursor:pointer;"><i class="fas fa-stop me-1"></i>Stop</button>
            </div>
            <div id="progressSection">
              <div id="phaseBadgeWrap" class="mb-3">
                <span class="phase-badge phase-Idle" id="phaseBadge"><i class="fas fa-circle-dot me-1"></i>Idle</span>
              </div>
              
              <!-- Scheduled Session Info -->
              <div id="scheduledInfo" style="display:none;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:8px;padding:8px;margin-bottom:12px;">
                <div style="font-size:10px;color:var(--text-muted);margin-bottom:2px;">📅 SCHEDULED SESSION</div>
                <div style="font-size:11px;font-weight:600;color:#3b82f6;" id="scheduleTitle">Loading...</div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px;" id="scheduleTime">Loading...</div>
              </div>

              <!-- Session Timer -->
              <div id="sessionTimer" style="display:none;text-align:center;margin-bottom:12px;">
                <div style="font-size:10px;color:var(--text-muted);margin-bottom:2px;" id="timerLabel">Session Duration</div>
                <div style="font-size:16px;font-weight:700;color:var(--teal);font-family:'Space Mono',monospace;" id="elapsedTime">00:00:00</div>
                
                <!-- Scheduled Duration Info -->
                <div id="scheduledDuration" style="display:none;margin-top:4px;">
                  <div style="font-size:9px;color:var(--text-muted);">Scheduled: <span id="scheduledHours" style="color:var(--amber);font-weight:600;">2.0h</span></div>
                </div>
                
                <!-- Cycle Count -->
                <div id="cycleCount" style="display:none;margin-top:4px;font-size:9px;color:var(--text-muted);">
                  Heating Cycles: <span style="color:var(--seafoam);font-weight:600;" id="cycleNumber">0</span>
                </div>
              </div>

              <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Moisture Reduction</div>
              <div class="drying-progress"><div class="drying-fill" id="dryingFill" style="width:0%"></div></div>
              <div class="d-flex justify-content-between" style="font-size:10.5px;color:var(--text-muted);margin-top:2px;"><span>0%</span><span id="progressPct" style="font-weight:700;color:var(--teal)">0%</span><span>100%</span></div>
              <div class="row g-2 mt-3" style="font-size:11.5px;">
                <div class="col-6">
                  <div style="color:var(--text-muted);font-size:10px;margin-bottom:2px;">SET TEMP</div>
                  <div style="font-weight:700;color:var(--teal)" id="setTempDisp">—</div>
                </div>
                <div class="col-6">
                  <div style="color:var(--text-muted);font-size:10px;margin-bottom:2px;">SET HUMIDITY</div>
                  <div style="font-weight:700;color:var(--seafoam)" id="setHumDisp">—</div>
                </div>
              </div>

              <!-- Hardware Status Card -->
              <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:8px;padding:10px;margin-top:12px;display:none;" id="hwStatusCard">
                <div style="font-size:10px;color:var(--text-muted);margin-bottom:8px;font-weight:600;text-transform:uppercase;">⚡ Hardware Status</div>
                <div class="row g-2" style="font-size:11px;">
                  <div class="col-4">
                    <div style="display:flex;align-items:center;gap:4px;padding:6px;background:rgba(0,0,0,.05);border-radius:4px;">
                      <span style="width:8px;height:8px;border-radius:50%;background:#ccc;display:inline-block;" id="hwHeater"></span>
                      <span id="hwHeaterLabel">Heater</span>
                    </div>
                  </div>
                  <div class="col-4">
                    <div style="display:flex;align-items:center;gap:4px;padding:6px;background:rgba(0,0,0,.05);border-radius:4px;">
                      <span style="width:8px;height:8px;border-radius:50%;background:#ccc;display:inline-block;" id="hwFan"></span>
                      <span id="hwFanLabel">Fan</span>
                    </div>
                  </div>
                  <div class="col-4">
                    <div style="display:flex;align-items:center;gap:4px;padding:6px;background:rgba(0,0,0,.05);border-radius:4px;">
                      <span style="width:8px;height:8px;border-radius:50%;background:#ccc;display:inline-block;" id="hwExhaust"></span>
                      <span id="hwExhaustLabel">Exhaust</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Live Mini Chart -->
        <div class="col-lg-4">
          <div class="control-card" style="height:100%;">
            <div class="control-section-title"><i class="fas fa-wave-square" style="color:var(--teal)"></i>Live Readings</div>
            <canvas id="miniChart" height="130"></canvas>
          </div>
        </div>
      </div>
    </div><!-- /tab-control -->

    <!-- ════════════════ TAB: LIVE MONITOR ════════════════ -->
    <div id="tab-monitor" class="tab-section">
      <div class="page-header">
        <div class="page-title">📡 Live Monitor</div>
        <div class="page-sub" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <span>Real-time telemetry for the active prototype drying session.</span>
          <span class="proto-online-pill" id="protoOnlineHeader"><span class="online-dot" id="protoOnlineHeaderDot"></span><span id="protoOnlineHeaderText">Checking...</span></span>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="monitor-card">
            <div class="row g-3">
              <div class="col-6">
                <div class="gauge-block">
                  <div class="gauge-ring">
                    <svg viewBox="0 0 100 100">
                      <circle cx="50" cy="50" r="42" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="7"/>
                      <circle cx="50" cy="50" r="42" fill="none" stroke="#2A9D8F" stroke-width="7" stroke-dasharray="263.9" id="tempArc" stroke-dashoffset="197" stroke-linecap="round" transform="rotate(-90 50 50)"/>
                    </svg>
                    <div class="gauge-text">
                      <div class="gauge-val" id="gaugeTemp">—</div>
                      <div class="gauge-unit">°C</div>
                    </div>
                  </div>
                  <div class="gauge-name">Temperature</div>
                </div>
              </div>
              <div class="col-6">
                <div class="gauge-block">
                  <div class="gauge-ring">
                    <svg viewBox="0 0 100 100">
                      <circle cx="50" cy="50" r="42" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="7"/>
                      <circle cx="50" cy="50" r="42" fill="none" stroke="#2EC4B6" stroke-width="7" stroke-dasharray="263.9" id="humArc" stroke-dashoffset="197" stroke-linecap="round" transform="rotate(-90 50 50)"/>
                    </svg>
                    <div class="gauge-text">
                      <div class="gauge-val" id="gaugeHum" style="color:#2EC4B6">—</div>
                      <div class="gauge-unit">%RH</div>
                    </div>
                  </div>
                  <div class="gauge-name">Humidity</div>
                </div>
              </div>
            </div>
            <div class="mt-3 d-flex flex-wrap justify-content-center" id="hwChips">
              <span class="hw-chip"><span class="dot-off"></span>Fan 1</span>
              <span class="hw-chip"><span class="dot-off"></span>Fan 2</span>
              <span class="hw-chip"><span class="dot-off"></span>Heater 1</span>
              <span class="hw-chip"><span class="dot-off"></span>Heater 2</span>
              <span class="hw-chip"><span class="dot-off"></span>Exhaust</span>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="glass-card" style="padding:20px;height:100%;">
            <div class="card-title mb-3"><span class="card-title-dot me-2" style="background:var(--teal)"></span>Live Telemetry</div>
            <canvas id="monitorChart" height="130"></canvas>
          </div>
        </div>
      </div>
    </div><!-- /tab-monitor -->

    <!-- ════════════════ TAB: MY SESSIONS ════════════════ -->
    <div id="tab-history" class="tab-section">
      <div class="page-header">
        <div class="page-title">🗂️ Drying Records</div>
        <div class="page-sub">// All completed prototype cycles for <?= $model_name ?> — <?= $given_code ?></div>
      </div>
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(42,157,143,.1);color:var(--teal)"><i class="fas fa-fish"></i></div>
            <div class="stat-value" id="myTotalSessions">0</div>
            <div class="stat-label">Total Sessions</div>
            <div class="stat-accent" style="background:var(--teal)"></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(82,182,154,.1);color:var(--seafoam)"><i class="fas fa-circle-check"></i></div>
            <div class="stat-value" id="myDriedCount" style="color:var(--seafoam)">0</div>
            <div class="stat-label">Fish Dried ✓</div>
            <div class="stat-accent" style="background:var(--seafoam)"></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(244,162,97,.1);color:var(--golden)"><i class="fas fa-temperature-high"></i></div>
            <div class="stat-value" id="myAvgTemp" style="color:var(--golden)">—</div>
            <div class="stat-label">Avg Temp °C</div>
            <div class="stat-accent" style="background:var(--amber)"></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:rgba(123,97,255,.1);color:#6344A0"><i class="fas fa-clock"></i></div>
            <div class="stat-value" id="myLastSession" style="font-size:18px;color:#6344A0">—</div>
            <div class="stat-label">Last Session</div>
            <div class="stat-accent" style="background:#7b61ff"></div>
          </div>
        </div>
      </div>
      <div class="glass-card" style="padding:22px;">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="card-title"><span class="card-title-dot me-2" style="background:var(--teal)"></span>Drying History</div>
          <button onclick="fetchMyRecords()" class="btn-outline-cmd"><i class="fas fa-rotate me-1"></i>Refresh</button>
        </div>
        <div class="section-scroll">
          <table class="data-table">
            <thead><tr>
              <th>#</th><th>Date &amp; Time</th><th>Duration</th>
              <th>Avg Temp</th><th>Avg Humidity</th><th>Result</th><th>Action</th>
            </tr></thead>
            <tbody id="myRecordsBody">
              <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="glass-card mt-4" id="detailChartCard" style="display:none;padding:22px;">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div style="font-family:'Playfair Display',serif;font-size:14px;font-weight:800;color:var(--text-primary)" id="detailChartTitle">Session Detail</div>
          <button onclick="closeDetailChart()" class="btn-outline-cmd">✕ Close</button>
        </div>
        <canvas id="detailChart" height="150"></canvas>
      </div>
    </div><!-- /tab-history -->

    <!-- ════════════════ TAB: SCHEDULE ════════════════ -->
    <div id="tab-schedule" class="tab-section">
      <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
          <div class="page-title">📅 Drying Calendar</div>
          <div class="page-sub">Plan and manage your upcoming drying batches.</div>
        </div>
        <button class="btn-primary" onclick="openScheduleModal()"><i class="fas fa-plus me-2"></i>Add Batch</button>
      </div>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="glass-card" style="padding:24px;">
            <div id="userCalendar"></div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="glass-card" style="padding:22px;">
            <div class="card-title mb-3"><span class="card-title-dot me-2" style="background:var(--teal)"></span>Upcoming Batches</div>
            <div id="schedulesList"></div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /content-wrap -->
</div><!-- /main -->

<!-- Notification Bell -->
<div class="notif-bell" id="notifBell" onclick="showNotifPanel()" title="Notifications">
  <i class="fas fa-bell"></i>
  <span class="notif-count" id="notifCount" style="display:none">0</span>
</div>

<!-- ═══════════ SCHEDULE MODAL ═══════════ -->
<div class="modal-backdrop" id="scheduleModal" style="display:none;" onclick="if(event.target===this)closeScheduleModal()">
  <div class="modal-panel">
    <div class="modal-title">📅 Add New Batch</div>
    <div class="form-row">
      <label class="form-label">Batch Title</label>
      <input type="text" id="sched_title" class="form-input" value="Tilapia Batch" placeholder="Tilapia / Bangus / etc.">
    </div>
    <div class="row g-3">
      <div class="col-6">
        <div class="form-row">
          <label class="form-label">Date</label>
          <input type="date" id="sched_date" class="form-input">
        </div>
      </div>
      <div class="col-6">
        <div class="form-row">
          <label class="form-label">Time</label>
          <input type="time" id="sched_time" class="form-input" value="08:00">
        </div>
      </div>
      <div class="col-6">
        <div class="form-row">
          <label class="form-label">Target Temp (°C)</label>
          <input type="number" id="sched_temp" class="form-input" value="50" min="30" max="80">
        </div>
      </div>
      <div class="col-6">
        <div class="form-row">
          <label class="form-label">Target Humidity (%)</label>
          <input type="number" id="sched_hum" class="form-input" value="30" min="10" max="80">
        </div>
      </div>
    </div>
    <div class="row g-3 mt-2">
      <div class="col-6">
        <div class="form-row">
          <label class="form-label">Duration (hours)</label>
          <input type="number" id="sched_duration" class="form-input" value="2.0" min="0.5" max="24" step="0.5">
        </div>
      </div>
      <div class="col-6">
        <div class="form-row">
          <label class="form-label" style="color:var(--text-muted);font-size:10px;">Auto Start & Stop</label>
          <div style="font-size:11px;color:var(--text-muted);padding:8px;background:rgba(59,130,246,.08);border-radius:6px;">Session will start at scheduled time and stop after duration</div>
        </div>
      </div>
    </div>
    <div class="form-row">
      <label class="form-label">Notes (optional)</label>
      <textarea id="sched_notes" class="form-input" rows="2" placeholder="Any notes…"></textarea>
    </div>
    <div class="d-flex gap-2">
      <button onclick="submitSchedule()" class="btn-primary" style="flex:1;">Add Batch</button>
      <button onclick="closeScheduleModal()" style="background:var(--surface-2);border:1.5px solid var(--border);color:var(--text-muted);border-radius:10px;padding:9px 18px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Mulish',sans-serif;">Cancel</button>
    </div>
  </div>
</div>

<!-- ═══════════ EVENT MODAL ═══════════ -->
<div class="modal-backdrop" id="eventModal" style="display:none;" onclick="if(event.target===this)closeEventModal()">
  <div class="modal-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="modal-title" id="evtTitle" style="margin-bottom:0">Event</div>
      <button onclick="closeEventModal()" style="background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:5px 11px;font-size:11px;font-weight:600;color:var(--text-muted);cursor:pointer;">✕</button>
    </div>
    <div id="evtBody" style="display:flex;flex-direction:column;gap:0;"></div>
    <div id="evtActions" style="margin-top:10px;"></div>
  </div>
</div>

<script>
const PROTO_ID   = <?= $proto_id ?>;
const MODEL_NAME = "<?= $model_name ?>";
const GIVEN_CODE = "<?= $given_code ?>";

// ════════════════════════════════════════════════════════
//  STATE
// ════════════════════════════════════════════════════════
let miniChartInst=null, monitorChartInst=null, detailChartInst=null, userCal=null;
let sessionRunning=false, sessionId=null, startTimeEpoch=null;
let currentSetTemp=50, currentSetHum=30;
let liveLabels=[], liveTemps=[], liveHums=[];
let notifList=[], timerInterval=null, protoStatusTimer=null;
let prototypeOnline=false; // Track device online status

// Clock
setInterval(()=>{
  document.getElementById('navClock').textContent=new Date().toLocaleTimeString('en-PH',{hour12:false});
},1000);

// ════════════════════════════════════════════════════════
//  TAB NAVIGATION
// ════════════════════════════════════════════════════════
function showTab(tab){
  document.querySelectorAll('.tab-section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('tab-'+tab).classList.add('active');
  const lnk=document.getElementById('link-'+tab);
  if(lnk) lnk.classList.add('active');
  if(tab==='monitor') initMonitorChart();
  if(tab==='history') fetchMyRecords();
  if(tab==='schedule') initUserCalendar();
}

// ════════════════════════════════════════════════════════
//  SESSION CONTROL
// ════════════════════════════════════════════════════════
async function startSession(){
  // Check if device is online before starting
  if(!prototypeOnline){
    Swal.fire({
      icon:'error',
      title:'Device Offline',
      html:'<div style="text-align:center;padding:8px;">The prototype device is currently offline.<br>Please make sure the device is connected and try again.</div>',
      confirmButtonText:'OK',
      confirmButtonColor:'#E63946',
      background:'#fff',
      color:'#0D1B2A'
    });
    return;
  }

  const temp=document.getElementById('tempRange').value;
  const hum=document.getElementById('humRange').value;
  const r=await Swal.fire({
    title:'Start Drying Session?',
    html:`<div style="text-align:center;padding:8px 0;">
      <div style="font-size:14px;color:#4A6FA5;margin-bottom:6px;">Target: <b>${temp}°C</b> / <b>${hum}%</b> Humidity</div>
      <div style="font-size:12px;color:#8BA7C4;">Fan will turn ON as heat source.</div>
    </div>`,
    icon:'question',showCancelButton:true,confirmButtonColor:'#2A9D8F',
    confirmButtonText:'Start',background:'#fff',color:'#0D1B2A'
  });
  if(!r.isConfirmed) return;
  try{
    const fd=new FormData();
    fd.append('action','start_session');
    fd.append('set_temp',temp);
    fd.append('set_humidity',hum);
    const j=await(await fetch('../api/session_api.php',{method:'POST',body:fd})).json();
    if(j.status==='success'){
      sessionRunning=true;
      sessionId=(j.data&&j.data.session_id)?j.data.session_id:null;
      currentSetTemp=parseFloat(temp)||50;
      currentSetHum=parseFloat(hum)||30;
      startTimeEpoch=Date.now();
      updateControlUI(true,temp,hum);
      updateSessionBadge(true);
      startTimer();
      showToast('success','Session Started!',`Fan (heat source) ON — targeting ${temp}°C / ${hum}%`,4000);
      pollLiveData();
    } else { showToast('warning','Error',j.message||'Could not start.',4000); }
  }catch(e){ showToast('warning','Network Error','Could not reach server.',3000); }
}

async function stopSession(){
  // Enhanced confirmation dialog with more info
  const result = await Swal.fire({
    title: '⚠️ Stop Heating Session?',
    html: `
      <div style="text-align: left; margin: 15px 0;">
        <p><strong>This will:</strong></p>
        <ul style="text-align: left; margin-left: 20px;">
          <li>Turn off all fans and heaters immediately</li>
          <li>Mark session as "Completed"</li>
          <li>Save current progress to records</li>
          <li>Stop temperature cycling</li>
        </ul>
        <div style="margin-top: 15px; padding: 10px; background: rgba(239,68,68,0.1); border-radius: 6px; border: 1px solid rgba(239,68,68,0.2);">
          <strong>⚡ Override Stop:</strong> This will immediately stop all heating regardless of current temperature or phase.
        </div>
      </div>
    `,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#E63946',
    confirmButtonText: '🛑 Stop Session',
    cancelButtonText: 'Continue Session',
    reverseButtons: true,
    background: '#fff',
    color: '#0D1B2A',
    width: '480px'
  });
  
  if (!result.isConfirmed) return;
  
  try {
    // Show stopping message
    const loadingToast = Swal.fire({
      title: 'Stopping Session...',
      text: 'Turning off all heating devices',
      icon: 'info',
      allowOutsideClick: false,
      showConfirmButton: false,
      didOpen: () => Swal.showLoading()
    });
    
    const fd = new FormData();
    fd.append('action', 'stop_session');
    if (sessionId) fd.append('session_id', sessionId);
    
    const response = await fetch('../api/session_api.php', {
      method: 'POST', 
      body: fd
    });
    
    const j = await response.json();
    
    loadingToast.close();
    
    if (j.status === 'success') {
      sessionRunning = false;
      clearInterval(timerInterval);
      updateControlUI(false);
      updateSessionBadge(false);
      hideBanners();
      
      // Clear hardware states and sensor labels
      updateHWChips({
        fan1_state: 0, fan2_state: 0, 
        heater1_state: 0, heater2_state: 0, 
        exhaust_state: 0,
        phase: 'Idle',
        recorded_temp: null,
        set_temp: null
      });
      
      showToast('success', '✅ Session Stopped', 'All heating devices turned off. Session data saved.', 5000);
    } else {
      showToast('error', 'Stop Failed', j.message || 'Could not stop session.', 4000);
    }
  } catch (e) {
    Swal.close();
    showToast('error', 'Network Error', 'Could not connect to server to stop session.', 4000);
  }
}

function hideBanners(){
  document.getElementById('fishReadyBanner').style.display='none';
  document.getElementById('cooldownBanner').style.display='none';
}

function updateControlUI(running,temp,hum){
  const section=document.getElementById('controlSection');
  const stopBtn=document.getElementById('stopBtn');
  
  if(running){
    section.innerHTML=`<div style="text-align:center;color:var(--text-muted);font-size:11px;padding:12px;background:rgba(42,157,143,.05);border-radius:8px;border:1px solid rgba(42,157,143,.15);"><i class="fas fa-cog fa-spin me-2"></i>Session Active</div>`;
    document.getElementById('setTempDisp').textContent=(temp||'—')+'°C';
    document.getElementById('setHumDisp').textContent=(hum||'—')+'%';
    document.getElementById('phaseBadge').className='phase-badge phase-Heating';
    document.getElementById('phaseBadge').innerHTML='<i class="fas fa-fan me-1"></i>Heating';
    
    // Show stop button when session is running
    if(stopBtn) stopBtn.style.display='inline-block';
    
  } else {
    section.innerHTML=`<button class="btn-start" id="startBtn" onclick="startSession()"><i class="fas fa-play me-2"></i>Start Drying Session</button>`;
    document.getElementById('setTempDisp').textContent='—';
    document.getElementById('setHumDisp').textContent='—';
    document.getElementById('phaseBadge').className='phase-badge phase-Idle';
    document.getElementById('phaseBadge').innerHTML='<i class="fas fa-circle-dot me-1"></i>Idle';
    document.getElementById('dryingFill').style.width='0%';
    document.getElementById('progressPct').textContent='0%';
    
    // Hide stop button when session is not running
    if(stopBtn) stopBtn.style.display='none';
    
    // Update button state based on online status
    updateStartButtonState();
  }
}

function updateSessionBadge(running){
  const badge=document.getElementById('sessionStatusBadge');
  const txt=document.getElementById('sessionStatusText');
  if(running){
    badge.className='session-status-badge running';
    badge.querySelector('.session-dot').style.cssText='background:var(--seafoam);box-shadow:0 0 6px var(--seafoam);';
    txt.textContent='Session Running';
  } else {
    badge.className='session-status-badge';
    badge.querySelector('.session-dot').style.cssText='background:#8BA7C4;box-shadow:none;';
    txt.textContent='No Active Session';
  }
}

function formatTimeAgo(seconds) {
  if (seconds === null || seconds < 0) return '';
  
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;
  
  let parts = [];
  
  if (days > 0) parts.push(`${days}d`);
  if (hours > 0) parts.push(`${hours}h`);
  if (minutes > 0) parts.push(`${minutes}m`);
  if (secs > 0) parts.push(`${secs}s`);
  
  return parts.length > 0 ? parts.join(' ') : '0s';
}

function updatePrototypeOnlineIndicator(isActive, isOnline, secondsSinceSeen){
  // Update global online status
  prototypeOnline = isOnline;
  
  const sidebar=document.getElementById('protoOnlineSidebar');
  const header=document.getElementById('protoOnlineHeader');
  const sidebarDot=document.getElementById('protoOnlineDot');
  const headerDot=document.getElementById('protoOnlineHeaderDot');
  const sidebarText=document.getElementById('protoOnlineText');
  const headerText=document.getElementById('protoOnlineHeaderText');
  
  // Sidebar: Simple Online/Offline only
  const sidebarLabel = !isActive ? 'Inactive' : (isOnline ? 'Online' : 'Offline');
  
  // Header: Include time ago for offline status
  let headerLabel;
  if (!isActive) {
    headerLabel = 'Inactive';
  } else if (isOnline) {
    headerLabel = 'Online';
  } else {
    // Offline with time
    if (secondsSinceSeen !== null && secondsSinceSeen > 20) {
      const timeAgo = formatTimeAgo(secondsSinceSeen);
      headerLabel = `Offline · Last seen ${timeAgo} ago`;
    } else {
      headerLabel = 'Offline';
    }
  }
  
  [sidebar, header].forEach(el=>{
    if(!el) return;
    el.classList.toggle('offline', !isOnline);
    el.classList.toggle('inactive', !isActive);
  });
  [sidebarDot, headerDot].forEach(el=>{
    if(!el) return;
    el.classList.toggle('offline', !isOnline);
    el.classList.toggle('inactive', !isActive);
  });
  
  if(sidebarText) sidebarText.textContent = sidebarLabel;
  if(headerText) headerText.textContent = headerLabel;
  
  // Update header device status
  updateHeaderDeviceStatus(isActive, isOnline, secondsSinceSeen);
  
  // Update Start button state based on online status
  updateStartButtonState();
}

function updateHeaderDeviceStatus(isActive, isOnline, secondsSinceSeen){
  const pill = document.getElementById('deviceStatusPill');
  const dot = document.getElementById('deviceStatusDot');
  const text = document.getElementById('deviceStatusText');
  
  if(!pill || !dot || !text) return;
  
  // Remove all status classes
  pill.classList.remove('online', 'offline', 'inactive');
  
  if(!isActive){
    // Device is inactive
    pill.classList.add('inactive');
    text.textContent = 'Device Inactive';
  } else if(isOnline){
    // Device is online
    pill.classList.add('online');
    text.textContent = 'Device Online';
  } else {
    // Device is offline (was active but not responding)
    pill.classList.add('offline');
    if(secondsSinceSeen !== null && secondsSinceSeen > 20){
      const timeAgo = formatTimeAgo(secondsSinceSeen);
      text.textContent = `Offline · Last seen ${timeAgo} ago`;
    } else {
      text.textContent = 'Device Offline';
    }
  }
}

function updateStartButtonState(){
  const startBtn = document.getElementById('startBtn');
  if(!startBtn) return;
  
  if(!prototypeOnline && !sessionRunning){
    startBtn.disabled = true;
    startBtn.style.opacity = '0.5';
    startBtn.style.cursor = 'not-allowed';
    startBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Device Offline';
  } else if(!sessionRunning){
    startBtn.disabled = false;
    startBtn.style.opacity = '1';
    startBtn.style.cursor = 'pointer';
    startBtn.innerHTML = '<i class="fas fa-play me-2"></i>Start Drying Session';
  }
}

function startTimer(){
  clearInterval(timerInterval);
  timerInterval=setInterval(()=>{
    if(!startTimeEpoch) return;
    const elapsed=Date.now()-startTimeEpoch;
    const h=Math.floor(elapsed/3600000).toString().padStart(2,'0');
    const m=Math.floor((elapsed%3600000)/60000).toString().padStart(2,'0');
    const s=Math.floor((elapsed%60000)/1000).toString().padStart(2,'0');
    document.getElementById('elapsedTime').textContent=`${h}:${m}:${s}`;
  },1000);
}

// ── Update timer to show cooldown countdown ──
let cooldownCountdownInterval=null;
function showCooldownCountdown(remainingSeconds){
  clearInterval(cooldownCountdownInterval);
  const timerEl = document.getElementById('elapsedTime');
  if(!timerEl) return;

  cooldownCountdownInterval = setInterval(()=>{
    remainingSeconds--;
    if(remainingSeconds < 0) {
      clearInterval(cooldownCountdownInterval);
      startTimer(); // Resume normal timer
      return;
    }
    const h = Math.floor(remainingSeconds/3600).toString().padStart(2,'0');
    const m = Math.floor((remainingSeconds%3600)/60).toString().padStart(2,'0');
    const s = (remainingSeconds%60).toString().padStart(2,'0');
    timerEl.textContent = `${h}:${m}:${s}`;
  }, 1000);
}

// ════════════════════════════════════════════════════════
//  SCHEDULED SESSION HANDLING
// ════════════════════════════════════════════════════════
function updateScheduledSessionDisplay(data) {
  const scheduledInfo = document.getElementById('scheduledInfo');
  const scheduleTitle = document.getElementById('scheduleTitle');
  const scheduleTime = document.getElementById('scheduleTime');
  
  if (data.is_scheduled && data.schedule_title) {
    scheduledInfo.style.display = 'block';
    scheduleTitle.textContent = data.schedule_title;
    
    if (data.schedule_date && data.schedule_time) {
      const schedDate = new Date(data.schedule_date + 'T' + data.schedule_time);
      scheduleTime.textContent = `Scheduled for ${schedDate.toLocaleDateString()} at ${schedDate.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}`;
    } else {
      scheduleTime.textContent = 'Auto-started from schedule';
    }
    
    // Disable parameter controls for scheduled sessions
    document.getElementById('tempRange').disabled = true;
    document.getElementById('humRange').disabled = true;
    document.getElementById('tempRange').style.opacity = '0.5';
    document.getElementById('humRange').style.opacity = '0.5';
  } else {
    scheduledInfo.style.display = 'none';
    
    // Enable parameter controls for manual sessions
    document.getElementById('tempRange').disabled = false;
    document.getElementById('humRange').disabled = false;
    document.getElementById('tempRange').style.opacity = '1';
    document.getElementById('humRange').style.opacity = '1';
  }
}

function updateDryingProgressDisplay(data) {
  const sessionTimer = document.getElementById('sessionTimer');
  const timerLabel = document.getElementById('timerLabel');
  const elapsedTime = document.getElementById('elapsedTime');
  const stopBtn = document.getElementById('stopBtn');
  
  if (sessionRunning) {
    sessionTimer.style.display = 'block';
    stopBtn.style.display = 'inline-block';
    
    // Update timer label and display based on session type
    if (data.is_scheduled && data.duration_hours) {
      timerLabel.textContent = 'Scheduled Session Progress';
      
      // Calculate remaining time for scheduled sessions
      if (data.start_time) {
        const startTime = new Date(data.start_time.replace(' ', 'T')).getTime();
        const durationMs = parseFloat(data.duration_hours) * 60 * 60 * 1000;
        const endTime = startTime + durationMs;
        const now = Date.now();
        const remaining = Math.max(0, endTime - now);
        
        const remainingHours = Math.floor(remaining / (60 * 60 * 1000));
        const remainingMins = Math.floor((remaining % (60 * 60 * 1000)) / (60 * 1000));
        const remainingSecs = Math.floor((remaining % (60 * 1000)) / 1000);
        
        elapsedTime.textContent = `${remainingHours.toString().padStart(2,'0')}:${remainingMins.toString().padStart(2,'0')}:${remainingSecs.toString().padStart(2,'0')} left`;
        elapsedTime.style.color = remaining > 0 ? 'var(--teal)' : 'var(--accent-red)';
        
        // Update every second for countdown
        clearInterval(window.countdownInterval);
        window.countdownInterval = setInterval(() => {
          updateDryingProgressDisplay(data);
        }, 1000);
      }
    } else {
      timerLabel.textContent = 'Session Duration';
      elapsedTime.style.color = 'var(--teal)';
      // Elapsed time is handled by startTimer function
    }
    
    // Update set temp/humidity displays
    document.getElementById('setTempDisp').textContent = `${data.set_temp}°C`;
    document.getElementById('setHumDisp').textContent = `${data.set_humidity}%`;
  } else {
    sessionTimer.style.display = 'none';
    stopBtn.style.display = 'none';
    document.getElementById('setTempDisp').textContent = '—';
    document.getElementById('setHumDisp').textContent = '—';
    clearInterval(window.countdownInterval);
  }
}

// ════════════════════════════════════════════════════════
//  LIVE POLLING — always-on sensor (no session needed)
// ════════════════════════════════════════════════════════
async function pollSensorAlways(){
  try{
    // Always poll live sensor data, even when idle
    const j=await(await fetch(`../api/session_api.php?action=get_live_data&proto_id=${PROTO_ID}`)).json();
    if(j.status==='success'&&j.data){
      const d=j.data;
      const t=parseFloat(d.recorded_temp);
      const h=parseFloat(d.recorded_humidity);

      // Show live data (even if idle) — display if we have any sensor data
      if(d.recorded_temp !== null && !isNaN(t)){
        document.getElementById('liveTemp').textContent=t.toFixed(1);
        document.getElementById('liveHum').textContent=h.toFixed(1);
        if(document.getElementById('gaugeTemp')){
          document.getElementById('gaugeTemp').textContent=t.toFixed(1);
          document.getElementById('gaugeHum').textContent=h.toFixed(1);
          updateGaugeArc('tempArc',t,80);
          updateGaugeArc('humArc',h,100);
        }
        updateMiniChart(t,h);
      }
    }
  }catch(e){}
  setTimeout(pollSensorAlways, 3000);
}

// ════════════════════════════════════════════════════════
//  LIVE POLLING — session data (every 3s when running)
//  UPDATED: handles COOLDOWN phase from server
// ════════════════════════════════════════════════════════
async function pollLiveData(){
  if(!sessionRunning) return;
  try{
    const j=await(await fetch(`../api/session_api.php?action=get_live_data&proto_id=${PROTO_ID}`)).json();
    if(j.status==='success'&&j.data){
      const d=j.data;
      const temp=parseFloat(d.recorded_temp)||0;
      const hum=parseFloat(d.recorded_humidity)||0;

      if(d.set_temp)     currentSetTemp=parseFloat(d.set_temp);
      if(d.set_humidity) currentSetHum=parseFloat(d.set_humidity);
      if(!startTimeEpoch && d.start_time){
        startTimeEpoch=new Date(d.start_time.replace(' ','T')).getTime();
        startTimer();
      }
      if(!sessionId && d.session_id) sessionId=d.session_id;

      // ── Handle scheduled session info ─────────────────────
      updateScheduledSessionDisplay(d);
      
      // ── Handle cycle count and duration display ─────────────
      updateSessionTimingDisplay(d);
      
      // ── Update drying progress display ─────────────────────
      updateDryingProgressDisplay(d);

      // ── COOLDOWN phase handling ──────────────────────────
      if(d.ctrl_status==='COOLDOWN'){
        const rem=parseInt(d.cooldown_remaining)||0;
        const mins=Math.floor(rem/60);
        const secs=(rem%60).toString().padStart(2,'0');
        updatePhase('Cooldown');
        document.getElementById('fishReadyBanner').style.display='none';
        const cb=document.getElementById('cooldownBanner');
        cb.style.display='';
        document.getElementById('cooldownMsg').textContent=
          `Cooling down: ${mins}m ${secs}s remaining. Fan will restart automatically after cooldown.`;

        // ── Show cooldown countdown in SESSION DURATION ──
        showCooldownCountdown(rem);
        updateHWChips({
          fan1:0, fan2:0,
          phase: 'Cooldown',
          recorded_temp: temp,
          set_temp: currentSetTemp
        });
        if(d.recorded_temp!==null){
          document.getElementById('liveTemp').textContent=temp.toFixed(1);
          document.getElementById('liveHum').textContent=hum.toFixed(1);
          if(document.getElementById('gaugeTemp')){
            document.getElementById('gaugeTemp').textContent=temp.toFixed(1);
            document.getElementById('gaugeHum').textContent=hum.toFixed(1);
            updateGaugeArc('tempArc',temp,80);
            updateGaugeArc('humArc',hum,100);
          }
          if(!refreshLiveChartsFromLogs(d.recent_logs)){
            updateMiniChart(temp,hum);
          }
        }
        if(sessionRunning) setTimeout(pollLiveData,3000);
        return;
      }

      // ── Normal running phase ─────────────────────────────
      document.getElementById('cooldownBanner').style.display='none';
      clearInterval(cooldownCountdownInterval); // Stop cooldown countdown if running
      startTimer(); // Resume normal elapsed time timer

      if(d.recorded_temp!==null){
        document.getElementById('liveTemp').textContent=temp.toFixed(1);
        document.getElementById('liveHum').textContent=hum.toFixed(1);
        if(document.getElementById('gaugeTemp')){
          document.getElementById('gaugeTemp').textContent=temp.toFixed(1);
          document.getElementById('gaugeHum').textContent=hum.toFixed(1);
          updateGaugeArc('tempArc',temp,80);
          updateGaugeArc('humArc',hum,100);
        }
        updateHWChips(d);
        updatePhase(d.phase);
        if(!refreshLiveChartsFromLogs(d.recent_logs)){
          updateMiniChart(temp,hum);
        }
        updateDryingProgress(hum,currentSetHum);

        if(d.fish_ready){
          document.getElementById('fishReadyBanner').style.display='';
          document.getElementById('fishReadyMsg').textContent=
            `Temp ${temp.toFixed(1)}°C / Humidity ${hum.toFixed(1)}% — targets reached! 5-min cooldown in progress.`;
          showToast('success','🎉 Targets Reached!','5-min cooldown started. System will auto-resume heating.',5000);
        }
      }
    } else if(j.status==='error'){
      sessionRunning=false;
      clearInterval(timerInterval);
      updateControlUI(false);
      updateSessionBadge(false);
      hideBanners();
      showToast('warning','Session Ended',j.message||'Session was stopped.',5000);
      return;
    }
  }catch(e){}
  if(sessionRunning) setTimeout(pollLiveData,3000);
}

function updateGaugeArc(id,val,max){
  const el=document.getElementById(id);
  if(!el) return;
  const circ=263.9;
  const offset=circ-(val/max)*circ;
  el.style.strokeDashoffset=Math.max(0,offset);
}

// ── ENHANCED: Hardware status with phase-aware descriptions ──
function updateHWChips(d){
  const phase = d.phase || 'Idle';
  const isSession = sessionRunning;
  const temp = parseFloat(d.recorded_temp) || 0;
  const targetTemp = parseFloat(d.set_temp) || currentSetTemp;

  // Determine status context for better descriptions
  const statusText = getHardwareStatusText(phase, isSession);

  // Update sensor labels with phase information
  updateSensorLabels(phase, isSession, temp, targetTemp);

  const chips=[
    {label:'Fan 1',    on: parseInt(d.fan1||0)===1, desc: phase === 'Heating' ? '(Heat)' : phase === 'Drying' ? '(Circ)' : ''},
    {label:'Fan 2',    on: parseInt(d.fan2||0)===1, desc: phase === 'Heating' ? '(Heat)' : phase === 'Drying' ? '(Circ)' : ''},
  ];

  const hwChipsHtml = chips.map(c=>
    `<span class="hw-chip">
       <span class="${c.on?'dot-on':'dot-off'}"></span>
       ${c.label}${c.desc}${c.on?' <b style="color:#4ade80;font-size:9px;">ON</b>':''}
     </span>`
  ).join('');

  // Update hardware status card in drying progress
  updateHardwareStatusCard(d);
}

// ── Update hardware status indicators in DRYING PROGRESS card ──
function updateHardwareStatusCard(d) {
  const hwCard = document.getElementById('hwStatusCard');
  if (!hwCard) return;

  const fan1On = parseInt(d.fan1 || 0) === 1;
  const fan2On = parseInt(d.fan2 || 0) === 1;

  // Update dot colors and labels
  const fan1Dot = document.getElementById('hwHeater');
  const fan2Dot = document.getElementById('hwFan');
  const exhaustDot = document.getElementById('hwExhaust');

  fan1Dot.style.background = fan1On ? '#3b82f6' : '#d1d5db';
  fan2Dot.style.background = fan2On ? '#3b82f6' : '#d1d5db';
  exhaustDot.style.background = '#d1d5db';  // Not used, hide
  exhaustDot.parentElement.style.display = 'none';

  document.getElementById('hwHeaterLabel').textContent = fan1On ? '💨 Fan 1' : 'Fan 1';
  document.getElementById('hwFanLabel').textContent = fan2On ? '💨 Fan 2' : 'Fan 2';

  // Show card only during active session
  hwCard.style.display = sessionRunning ? '' : 'none';
}

// ── Get contextual hardware status description ──
function getHardwareStatusText(phase, isSession) {
  if (!isSession) {
    return '🔌 All devices offline - No active session';
  }

  switch(phase) {
    case 'Heating':
      return '🔥 Active heating - Fans working to reach target temperature';
    case 'Drying':
      return '🌬️ Gentle drying - Fans maintaining temperature with circulation';
    case 'Cooldown':
      return '❄️ Cooling down - Fans off, rest period before next cycle';
    case 'Idle':
      return '⏸️ Session paused - Awaiting next cycle';
    default:
      return `📊 ${phase} mode - Session active`;
  }
}

// ── Update live sensor labels with phase information ──
function updateSensorLabels(phase, isSession, temp, targetTemp) {
  const tempLabel = document.getElementById('tempLabel');
  const humLabel = document.getElementById('humLabel');
  
  if (!isSession) {
    tempLabel.textContent = 'Live Temp °C';
    humLabel.textContent = 'Live Humidity %';
    return;
  }
  
  let tempStatus = '';
  let humStatus = '';
  
  switch(phase) {
    case 'Heating':
      if (temp && targetTemp) {
        const diff = targetTemp - temp;
        tempStatus = diff > 10 ? '• Heating Up' : '• Near Target';
      } else {
        tempStatus = '• Heating';
      }
      humStatus = '• Active Session';
      break;
    case 'Drying':
      tempStatus = '• Maintaining';
      humStatus = '• Reducing';
      break;
    case 'Cooldown':
      tempStatus = '• Cooling Down';
      humStatus = '• Rest Period';
      break;
    case 'Exhaust':
      tempStatus = '• Exhausting';
      humStatus = '• Cooling';
      break;
    case 'Idle':
      tempStatus = '• Session Paused';
      humStatus = '• Session Paused';
      break;
    default:
      tempStatus = `• ${phase}`;
      humStatus = `• ${phase}`;
  }
  
  tempLabel.textContent = `Live Temp °C ${tempStatus}`;
  humLabel.textContent = `Live Humidity % ${humStatus}`;
}

// ── UPDATED: Drying phase + Cooldown phase added ────────
function updatePhase(phase){
  const p=phase||'Idle';
  const icons={
    Heating :'fa-fan',
    Drying  :'fa-fan',
    Exhaust :'fa-wind',
    Cooldown:'fa-snowflake',
    Idle    :'fa-circle-dot',
    Done    :'fa-check'
  };
  const labels={
    Heating :'Heating (Fan ON)',
    Drying  :'Drying (Fan ON)',
    Exhaust :'Exhaust',
    Cooldown:'Cooldown',
    Idle    :'Idle',
    Done    :'Done'
  };
  const ic=icons[p]||'fa-circle-dot';
  const lb=labels[p]||p;
  const el=document.getElementById('phaseBadge');
  if(el){ el.className=`phase-badge phase-${p}`; el.innerHTML=`<i class="fas ${ic} me-1"></i>${lb}`; }
}

function updateDryingProgress(currentHum,targetHum){
  const startHum=80;
  const range=Math.max(1,startHum-targetHum);
  const reduced=startHum-currentHum;
  const pct=Math.min(100,Math.max(0,Math.round((reduced/range)*100)));
  document.getElementById('dryingFill').style.width=pct+'%';
  document.getElementById('progressPct').textContent=pct+'%';
}

// ════════════════════════════════════════════════════════
//  CHARTS
// ════════════════════════════════════════════════════════
function initLiveChart(){
  const ctx=document.getElementById('miniChart').getContext('2d');
  miniChartInst=new Chart(ctx,{
    type:'line',
    data:{labels:[],datasets:[
      {label:'Temp',data:[],borderColor:'#2A9D8F',backgroundColor:'rgba(42,157,143,.08)',borderWidth:1.5,pointRadius:1.5,tension:.45,fill:true},
      {label:'Humidity',data:[],borderColor:'#2EC4B6',backgroundColor:'rgba(82,182,154,.08)',borderWidth:1.5,pointRadius:1.5,tension:.45,fill:true}
    ]},
    options:{
      responsive:true,
      plugins:{legend:{labels:{font:{size:10,family:'DM Sans'},color:'#4A6FA5'}}},
      scales:{
        x:{display:false},
        y:{grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:9,family:'DM Sans'},color:'#8BA7C4'}}
      }
    }
  });
}

function initMonitorChart(){
  if(monitorChartInst) return;
  const ctx=document.getElementById('monitorChart').getContext('2d');
  monitorChartInst=new Chart(ctx,{
    type:'line',
    data:{labels:[],datasets:[
      {label:'Temp °C',data:[],borderColor:'#2A9D8F',borderWidth:2,pointRadius:2,tension:.4,fill:false},
      {label:'Humidity %',data:[],borderColor:'#2EC4B6',borderWidth:2,pointRadius:2,tension:.4,fill:false}
    ]},
    options:{
      responsive:true,
      plugins:{legend:{labels:{font:{size:11,family:'DM Sans'},color:'#4A6FA5'}}},
      scales:{
        x:{grid:{color:'rgba(0,0,0,.04)'},ticks:{font:{size:9,family:'DM Sans'},color:'#8BA7C4'}},
        y:{grid:{color:'rgba(0,0,0,.04)'},ticks:{font:{size:9,family:'DM Sans'},color:'#8BA7C4'}}
      }
    }
  });
}

function updateMiniChart(temp,hum){
  const now=new Date().toLocaleTimeString('en-PH',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  if(!miniChartInst) return;
  miniChartInst.data.labels.push(now);
  miniChartInst.data.datasets[0].data.push(temp);
  miniChartInst.data.datasets[1].data.push(hum);
  if(miniChartInst.data.labels.length>30){
    miniChartInst.data.labels.shift();
    miniChartInst.data.datasets.forEach(d=>d.data.shift());
  }
  miniChartInst.update('none');
  if(monitorChartInst){
    monitorChartInst.data.labels.push(now);
    monitorChartInst.data.datasets[0].data.push(temp);
    monitorChartInst.data.datasets[1].data.push(hum);
    if(monitorChartInst.data.labels.length>60){
      monitorChartInst.data.labels.shift();
      monitorChartInst.data.datasets.forEach(d=>d.data.shift());
    }
    monitorChartInst.update('none');
  }
}

function refreshLiveChartsFromLogs(logs){
  if(!Array.isArray(logs) || !logs.length) return false;
  const ordered=logs.slice().sort((a,b)=>new Date(a.timestamp.replace(' ','T'))-new Date(b.timestamp.replace(' ','T')));
  const labels=ordered.map(row=>row.timestamp ? row.timestamp.slice(11,19) : '');
  const temps=ordered.map(row=>parseFloat(row.recorded_temp)||0);
  const hums=ordered.map(row=>parseFloat(row.recorded_humidity)||0);
  liveLabels=labels.slice();
  liveTemps=temps.slice();
  liveHums=hums.slice();
  if(miniChartInst){
    miniChartInst.data.labels=labels.slice();
    miniChartInst.data.datasets[0].data=temps.slice();
    miniChartInst.data.datasets[1].data=hums.slice();
    miniChartInst.update('none');
  }
  if(monitorChartInst){
    monitorChartInst.data.labels=labels.slice();
    monitorChartInst.data.datasets[0].data=temps.slice();
    monitorChartInst.data.datasets[1].data=hums.slice();
    monitorChartInst.update('none');
  }
  return true;
}

// ════════════════════════════════════════════════════════
//  MY RECORDS
// ════════════════════════════════════════════════════════
async function fetchMyRecords(){
  document.getElementById('myRecordsBody').innerHTML=`<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</td></tr>`;
  try{
    const j=await(await fetch('../api/session_api.php?action=get_my_sessions')).json();
    if(j.status==='success'&&j.data?.length){
      const data=j.data;
      document.getElementById('myTotalSessions').textContent=data.length;
      document.getElementById('myTotalSessionsCtrl').textContent=data.length;
      const completed=data.filter(d=>d.status==='Completed');
      document.getElementById('myDriedCount').textContent=completed.length;
      const avgT=data.reduce((a,d)=>a+(parseFloat(d.avg_temp)||0),0)/data.length;
      document.getElementById('myAvgTemp').textContent=avgT.toFixed(1);
      document.getElementById('myLastSession').textContent=data[0]?.start_time?.slice(0,10)||'—';
      document.getElementById('myRecordsBody').innerHTML=data.map((s,i)=>`
        <tr>
          <td class="mono" style="color:var(--teal)">${i+1}</td>
          <td style="font-size:11.5px;color:var(--text-muted)">${s.start_time?.slice(0,16)||'—'}</td>
          <td class="mono">${s.duration?.slice(0,8)||'—'}</td>
          <td style="font-weight:600;color:var(--golden)">${s.avg_temp?parseFloat(s.avg_temp).toFixed(1)+'°C':'—'}</td>
          <td style="font-weight:600;color:var(--seafoam)">${s.avg_hum?parseFloat(s.avg_hum).toFixed(1)+'%':'—'}</td>
          <td><span class="pill pill-${s.status}">${s.status}</span></td>
          <td><button onclick="viewSessionDetail(${s.session_id})" class="act-btn act-view">Detail</button></td>
        </tr>`).join('');
    } else {
      document.getElementById('myTotalSessionsCtrl').textContent='0';
      document.getElementById('myRecordsBody').innerHTML=`<tr><td colspan="7" style="text-align:center;padding:48px;color:var(--text-muted)">
        <div style="font-size:28px;margin-bottom:8px">🐟</div>
        <div style="font-size:13px;font-weight:700;color:var(--text-primary)">No sessions yet</div>
        <div style="font-size:11.5px;margin-top:3px">Start a prototype drying session to see your records here.</div>
      </td></tr>`;
    }
  }catch(e){
    document.getElementById('myRecordsBody').innerHTML=`<tr><td colspan="7" style="text-align:center;padding:28px;color:var(--text-muted)">No data found.</td></tr>`;
  }
}

let detailChartIns=null;
async function viewSessionDetail(sid){
  try{
    const j=await(await fetch(`../api/session_api.php?action=get_session_logs&session_id=${sid}`)).json();
    if(j.status!=='success'||!j.data?.length){ showToast('warning','No Logs','No readings found for this session.',3000); return; }
    const logs=j.data;
    document.getElementById('detailChartCard').style.display='';
    document.getElementById('detailChartTitle').textContent=`Drying Session #${sid} — ${logs.length} readings`;
    if(detailChartIns){ detailChartIns.destroy(); detailChartIns=null; }
    const ctx=document.getElementById('detailChart').getContext('2d');
    detailChartIns=new Chart(ctx,{
      type:'line',
      data:{
        labels:logs.map(l=>l.timestamp?.slice(11,19)||''),
        datasets:[
          {label:'Temp °C',data:logs.map(l=>parseFloat(l.recorded_temp)),borderColor:'#2A9D8F',borderWidth:2,pointRadius:2,tension:.4,fill:false},
          {label:'Humidity %',data:logs.map(l=>parseFloat(l.recorded_humidity)),borderColor:'#2EC4B6',borderWidth:2,pointRadius:2,tension:.4,fill:false}
        ]
      },
      options:{
        responsive:true,
        plugins:{legend:{labels:{font:{size:11,family:'DM Sans'},color:'#4A6FA5'}}},
        scales:{
          x:{grid:{color:'rgba(0,0,0,.04)'},ticks:{font:{size:9},color:'#8BA7C4',maxTicksLimit:12}},
          y:{grid:{color:'rgba(0,0,0,.04)'},ticks:{font:{size:9},color:'#8BA7C4'}}
        }
      }
    });
    document.getElementById('detailChartCard').scrollIntoView({behavior:'smooth',block:'start'});
  }catch(e){ showToast('warning','Error','Could not load session detail.',3000); }
}

function closeDetailChart(){
  document.getElementById('detailChartCard').style.display='none';
  if(detailChartIns){ detailChartIns.destroy(); detailChartIns=null; }
}

// ════════════════════════════════════════════════════════
//  SCHEDULE
// ════════════════════════════════════════════════════════
function openScheduleModal(){
  const today=new Date().toISOString().slice(0,10);
  document.getElementById('sched_date').value=today;
  document.getElementById('scheduleModal').style.display='flex';
}
function closeScheduleModal(){ document.getElementById('scheduleModal').style.display='none'; }

async function submitSchedule(){
  const title=document.getElementById('sched_title').value.trim();
  const date=document.getElementById('sched_date').value;
  const time=document.getElementById('sched_time').value;
  const temp=document.getElementById('sched_temp').value;
  const hum=document.getElementById('sched_hum').value;
  const duration=document.getElementById('sched_duration').value;
  const notes=document.getElementById('sched_notes').value;
  if(!date){ showToast('warning','Date Required','Please choose a schedule date.',3000); return; }
  const fd=new FormData();
  fd.append('action','create_schedule');
  fd.append('title',title);
  fd.append('sched_date',date);
  fd.append('sched_time',time);
  fd.append('set_temp',temp);
  fd.append('set_hum',hum);
  fd.append('duration_hours',duration);
  fd.append('notes',notes);
  try{
    const j=await(await fetch('../api/schedule_api.php',{method:'POST',body:fd})).json();
    if(j.status==='success'){
      closeScheduleModal();
      if(userCal) userCal.refetchEvents();
      loadUserSchedules();
      showToast('success','Added!','Your batch has been added to the calendar.',3000);
    } else { showToast('warning','Error',j.message||'Failed.',3000); }
  }catch(e){ showToast('warning','Network Error','Could not save schedule.',3000); }
}

async function deleteSchedule(id){
  const r=await Swal.fire({title:'Delete Schedule?',icon:'warning',showCancelButton:true,confirmButtonColor:'#E63946',background:'#fff',color:'#0D1B2A'});
  if(!r.isConfirmed) return;
  const fd=new FormData(); fd.append('action','delete_schedule'); fd.append('schedule_id',id);
  await fetch('../api/schedule_api.php',{method:'POST',body:fd});
  if(userCal) userCal.refetchEvents();
  loadUserSchedules();
}

function initUserCalendar(){
  if(userCal){ userCal.refetchEvents(); loadUserSchedules(); return; }
  userCal=new FullCalendar.Calendar(document.getElementById('userCalendar'),{
    initialView:'dayGridMonth',
    headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek,listMonth'},
    height:480,nowIndicator:true,
    events:async(info,success,failure)=>{
      try{ const r=await fetch('../api/schedule_api.php?action=get_calendar_events'); const j=await r.json(); success(j.status==='success'?j.data:[]); }
      catch(e){ failure(e); }
    },
    eventClick:(info)=>showEventModal(info.event)
  });
  userCal.render();
  loadUserSchedules();
}

async function loadUserSchedules(){
  try{
    const j=await(await fetch(`../api/schedule_api.php?action=get_my_schedules&proto_id=${PROTO_ID}`)).json();
    const el=document.getElementById('schedulesList');
    if(j.status!=='success'||!j.data?.length){ el.innerHTML=`<div style="text-align:center;padding:24px;color:var(--text-muted);font-size:12px;">No upcoming batches.</div>`; return; }
    el.innerHTML=j.data.slice(0,8).map(s=>`
      <div class="sched-item">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(42,157,143,.1);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;">📅</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;font-size:12.5px;color:var(--text-primary)">${s.title}</div>
          <div style="font-size:10.5px;color:var(--text-muted)">${s.sched_date} ${s.sched_time?.slice(0,5)}</div>
        </div>
        <span class="pill pill-${s.status}" style="flex-shrink:0;">${s.status}</span>
      </div>`).join('');
  }catch(e){}
}

function showEventModal(event){
  const p=event.extendedProps;
  document.getElementById('evtTitle').textContent=event.title.replace(/^. /,'');
  const evRow=(lbl,val)=>`<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--border);font-size:12.5px;"><span style="color:var(--text-muted)">${lbl}</span><span style="font-weight:700">${val}</span></div>`;
  let html='';
  if(p.type==='schedule'){
    html=evRow('Date/Time',event.startStr?.slice(0,16).replace('T',' '))+evRow('Targets',`${p.set_temp}°C / ${p.set_humidity}%`)+`<div style="display:flex;justify-content:space-between;padding:9px 0;font-size:12.5px;"><span style="color:var(--text-muted)">Status</span><span class="pill pill-${p.status}">${p.status}</span></div>`+
    (p.notes?`<div style="background:var(--surface-2);border-radius:8px;padding:10px;font-size:11.5px;color:var(--text-muted);margin-top:8px">${p.notes}</div>`:'');
    document.getElementById('evtActions').innerHTML=`<button onclick="deleteSchedule(${p.schedule_id});closeEventModal()" class="act-btn act-del" style="width:100%;padding:8px;"><i class="fas fa-trash me-1"></i>Delete Schedule</button>`;
  } else {
    html=evRow('Session #',`<span class="mono" style="color:var(--teal)">#${p.session_id}</span>`)+evRow('Start',event.startStr?.slice(0,16).replace('T',' '))+evRow('End',p.end_time?.slice(0,16)||'—')+`<div style="display:flex;justify-content:space-between;padding:9px 0;font-size:12.5px;"><span style="color:var(--text-muted)">Status</span><span class="pill pill-${p.status}">${p.status}</span></div>`;
    document.getElementById('evtActions').innerHTML='';
  }
  document.getElementById('evtBody').innerHTML=html;
  document.getElementById('eventModal').style.display='flex';
}
function closeEventModal(){ document.getElementById('eventModal').style.display='none'; }

// ══ SESSION TIMING DISPLAY ═══════════════════════════════
function updateSessionTimingDisplay(d) {
  const sessionTimer = document.getElementById('sessionTimer');
  const scheduledDuration = document.getElementById('scheduledDuration');
  const cycleCount = document.getElementById('cycleCount');
  
  if (sessionRunning) {
    sessionTimer.style.display = 'block';
    
    // Show scheduled duration if available
    if (d.duration_hours) {
      scheduledDuration.style.display = 'block';
      document.getElementById('scheduledHours').textContent = `${d.duration_hours}h`;
    } else {
      scheduledDuration.style.display = 'none';
    }
    
    // Show cycle count if available
    if (d.cycle_count !== undefined && d.cycle_count > 0) {
      cycleCount.style.display = 'block';
      document.getElementById('cycleNumber').textContent = d.cycle_count;
    } else {
      cycleCount.style.display = 'none';
    }
    
    // Update timer label based on session type
    if (d.duration_hours) {
      document.getElementById('timerLabel').textContent = 'Elapsed / Scheduled';
    } else {
      document.getElementById('timerLabel').textContent = 'Session Duration';
    }
  } else {
    sessionTimer.style.display = 'none';
    scheduledDuration.style.display = 'none';
    cycleCount.style.display = 'none';
  }
}

// ════════════════════════════════════════════════════════
//  TOAST
// ════════════════════════════════════════════════════════
function showToast(type,title,msg,duration=4000){
  const el=document.createElement('div');
  el.className=`toast-item ${type}`;
  el.id='toast_'+Date.now();
  el.onclick=()=>el.remove();
  el.innerHTML=`<div style="flex:1"><div class="toast-title">${title}</div><div class="toast-msg">${msg}</div></div>`;
  document.getElementById('toastZone').appendChild(el);
  if(duration>0) setTimeout(()=>el.remove(),duration);
}

// ════════════════════════════════════════════════════════
//  NOTIFICATION BELL
// ════════════════════════════════════════════════════════
function addNotif(title,msg){
  notifList.unshift({title,msg,time:new Date().toLocaleTimeString('en-PH',{hour12:true})});
  const cnt=document.getElementById('notifCount');
  const n=parseInt(cnt.textContent||0)+1;
  cnt.textContent=n;
  cnt.style.display='';
  document.getElementById('notifBell').style.animation='shake .4s ease';
  setTimeout(()=>document.getElementById('notifBell').style.animation='',500);
}

function showNotifPanel(){
  document.getElementById('notifCount').style.display='none';
  document.getElementById('notifCount').textContent='0';
  if(!notifList.length){ showToast('info','No Notifications','No alerts at this time.',2000); return; }
  const items=notifList.slice(0,10).map(n=>`
    <div style="padding:10px 0;border-bottom:1px solid var(--border);">
      <div style="font-size:12.5px;font-weight:700;color:var(--text-primary)">${n.title}</div>
      <div style="font-size:11px;color:var(--text-muted)">${n.msg}</div>
      <div style="font-size:10px;color:var(--text-muted);margin-top:2px">${n.time}</div>
    </div>`).join('');
  Swal.fire({
    title:'🔔 Notifications',
    html:`<div style="text-align:left;max-height:320px;overflow-y:auto">${items}</div>`,
    showConfirmButton:false,showCloseButton:true,background:'#fff',color:'#0D1B2A'
  });
}

// ════════════════════════════════════════════════════════
//  LOGOUT
// ════════════════════════════════════════════════════════
function logoutUser(){
  Swal.fire({title:'Logout?',icon:'question',showCancelButton:true,confirmButtonColor:'#E63946',background:'#fff',color:'#0D1B2A'})
    .then(r=>{ if(r.isConfirmed) window.location.href='../auth/logout.php'; });
}

// ════════════════════════════════════════════════════════
//  BROWSER NOTIFICATIONS PERMISSION
// ════════════════════════════════════════════════════════
if('Notification' in window && Notification.permission==='default'){
  setTimeout(()=>Notification.requestPermission(),3000);
}

// ════════════════════════════════════════════════════════
//  INIT
// ════════════════════════════════════════════════════════
initLiveChart();
document.getElementById('elapsedTime').textContent='00:00:00';
checkExistingSession();  // ← CHECK FOR EXISTING SESSION ON PAGE LOAD
pollSensorAlways();
pollPrototypeStatus();
protoStatusTimer=setInterval(pollPrototypeStatus,5000);

async function pollPrototypeStatus(){
  try{
    const j=await(await fetch(`../api/session_api.php?action=get_prototype_status&proto_id=${PROTO_ID}`)).json();
    if(j.status==='success'&&j.data){
      updatePrototypeOnlineIndicator(!!j.data.prototype_active, !!j.data.prototype_online, j.data.seconds_since_seen ?? null);
    }
  }catch(e){}
}

// ── On page load / refresh: restore active session if one is running ──
async function checkExistingSession(){
  try{
    console.log('🔍 Checking existing session with PROTO_ID:', PROTO_ID);
    const j=await(await fetch(`../api/session_api.php?action=get_live_data&proto_id=${PROTO_ID}`)).json();
    console.log('📡 Session check response:', j);

    if(j.status==='success'&&j.data){
      const d=j.data;

      // Display idle sensor data (even if no active session)
      if(d.recorded_temp!==null && d.recorded_temp!==''){
        document.getElementById('liveTemp').textContent=parseFloat(d.recorded_temp).toFixed(1);
        document.getElementById('liveHum').textContent=parseFloat(d.recorded_humidity).toFixed(1);
        if(document.getElementById('gaugeTemp')){
          document.getElementById('gaugeTemp').textContent=parseFloat(d.recorded_temp).toFixed(1);
          document.getElementById('gaugeHum').textContent=parseFloat(d.recorded_humidity).toFixed(1);
          updateGaugeArc('tempArc',parseFloat(d.recorded_temp),80);
          updateGaugeArc('humArc',parseFloat(d.recorded_humidity),100);
        }
        updateMiniChart(parseFloat(d.recorded_temp), parseFloat(d.recorded_humidity));
      }

      // Check if there's an active session
      if(d.session_id){
        console.log('✅ Active session found:', d.session_id);

        sessionRunning=true;
        sessionId=d.session_id;
        currentSetTemp=parseFloat(d.set_temp)||50;
        currentSetHum=parseFloat(d.set_humidity)||30;
        if(d.start_time) startTimeEpoch=new Date(d.start_time.replace(' ','T')).getTime();

        updateControlUI(true,d.set_temp,d.set_humidity);
        updateSessionBadge(true);

        // Show stop button
        const stopBtn = document.getElementById('stopBtn');
        if(stopBtn) stopBtn.style.display = 'inline-block';

        // Show session timer
        const sessionTimer = document.getElementById('sessionTimer');
        if(sessionTimer) sessionTimer.style.display = 'block';

        startTimer();
        pollLiveData();
        showToast('success','Session Restored','Your active drying session was resumed.',3000);
      } else {
        // Idle state - no active session but sensor data available
        console.log('⏸ Idle state - no active session');
        sessionRunning=false;
        sessionId=null;
        startTimeEpoch=null;
        updateControlUI(false);
        updateSessionBadge(false);

        // Hide stop button
        const stopBtn = document.getElementById('stopBtn');
        if(stopBtn) stopBtn.style.display = 'none';
      }
    } else if(j.status==='error'){
      // Handle API errors properly - ensure UI shows "not running"
      console.log('❌ No active session:', j.message);
      sessionRunning=false;
      sessionId=null;
      startTimeEpoch=null;
      updateControlUI(false);
      updateSessionBadge(false);

      // Hide stop button
      const stopBtn = document.getElementById('stopBtn');
      if(stopBtn) stopBtn.style.display = 'none';
    }
  }catch(e){
    // Handle network errors - ensure UI shows "not running"
    console.log('❌ Network error checking existing session:', e);
    sessionRunning=false;
    sessionId=null;
    startTimeEpoch=null;
    updateControlUI(false);
    updateSessionBadge(false);

    // Hide stop button
    const stopBtn = document.getElementById('stopBtn');
    if(stopBtn) stopBtn.style.display = 'none';
  }
}

// Wait for DOM to be ready, then check for existing session
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 DOM ready, checking for existing session...');
    setTimeout(checkExistingSession, 500); // Small delay to ensure everything is initialized
  });
} else {
  // DOM is already loaded
  console.log('🚀 DOM already ready, checking for existing session...');
  setTimeout(checkExistingSession, 500);
}
</script>
</body>
</html>