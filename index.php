<?php
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
include('database/dbcon.php');

// ─── INQUIRY HANDLER ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_inquiry') {
    header('Content-Type: application/json');

    $name    = trim($_POST['inq_name'] ?? '');
    $contact = trim($_POST['inq_contact'] ?? '');
    $message = trim($_POST['inq_message'] ?? '');

    if ($name === '' || $message === '') {
        echo json_encode(['status' => 'error', 'message' => 'Name and message are required.']);
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

        $stmt = $dbh->prepare(
            "INSERT INTO tbl_inquiries (name, contact, message, status)
             VALUES (:name, :contact, :message, 'pending')"
        );
        $stmt->execute([
            ':name' => $name,
            ':contact' => ($contact !== '' ? $contact : null),
            ':message' => $message,
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Your inquiry has been sent.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to send inquiry. Please try again.']);
    }
    exit;
}

// ─── LOGIN HANDLER ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    header('Content-Type: application/json');
    $model_name = trim($_POST['model_name'] ?? '');
    $code       = trim($_POST['code'] ?? '');

    $query = $dbh->prepare("SELECT * FROM tbl_prototypes WHERE model_name=:m AND given_code=:c LIMIT 1");
    $query->execute([':m' => $model_name, ':c' => $code]);
    $proto = $query->fetch(PDO::FETCH_OBJ);

    if ($proto) {
        if ($proto->status != '1') {
            echo json_encode(['status'=>'error','message'=>'Your prototype is registered but access is currently restricted by the administrator.']);
            exit;
        }

        session_regenerate_id(true);

        $session_user_id = 0;
        $map = $dbh->prepare("SELECT id FROM tblusers WHERE id=:id AND status=1 LIMIT 1");
        $map->execute([':id' => (int)$proto->id]);
        $mapped = $map->fetch(PDO::FETCH_ASSOC);
        if ($mapped && isset($mapped['id'])) {
            $session_user_id = (int)$mapped['id'];
        } else {
            $fallback = $dbh->query("SELECT id FROM tblusers WHERE username='system_operator' AND status=1 LIMIT 1")
                            ->fetch(PDO::FETCH_ASSOC);
            if (!$fallback) {
                $fallback = $dbh->query("SELECT id FROM tblusers WHERE status=1 ORDER BY id ASC LIMIT 1")
                                ->fetch(PDO::FETCH_ASSOC);
            }
            if ($fallback && isset($fallback['id'])) {
                $session_user_id = (int)$fallback['id'];
            }
        }

        $_SESSION['proto_id']    = $proto->id;
        $_SESSION['user_id']     = $session_user_id;
        $_SESSION['model_name']  = $proto->model_name;
        $_SESSION['given_code']  = $proto->given_code;
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $redirectPath = ($basePath !== '' ? $basePath : '') . '/admin/users_dashboard.php';
        session_write_close();
        echo json_encode([
            'status' => 'success',
            'message' => 'Prototype verified! Loading your automated dashboard...',
            'redirect' => $redirectPath
        ]);
    } else {
        echo json_encode(['status'=>'error','message'=>'No matching prototype found. Check your Model Name and Code.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Automated Prototype System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
    --bg:        #eff6f3;
    --panel:     rgba(255,255,255,0.84);
    --accent:    #0f766e;
    --accent2:   #155e75;
    --gold:      #b45309;
    --border:    rgba(15,118,110,0.22);
    --muted:     #6b7f86;
    --txt:       #102a2d;
    --text-dim:  #3f5f66;
    --success:   #0f766e;
    --warning:   #b45309;
    --danger:    #dc2626;
    --swal-bg:   #ffffff;
    --swal-text: #102a2d;
}

*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }

body {
    font-family: 'Bricolage Grotesque', sans-serif;
    min-height: 100vh;
    background: var(--bg);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow-x: hidden;
    position: relative;
    padding: 20px;
}

/* ── ANIMATED BACKGROUND ── */
.bg-layer {
    position: fixed; inset: 0; z-index: 0;
    background:
    radial-gradient(ellipse 70% 50% at 20% 35%, rgba(15,118,110,0.12) 0%, transparent 62%),
    radial-gradient(ellipse 65% 60% at 78% 70%, rgba(180,83,9,0.10) 0%, transparent 58%),
    linear-gradient(155deg, #f7fbf9 0%, #e7f1ee 52%, #f9f6ef 100%);
}

/* Grid lines */
.bg-grid {
    position: fixed; inset: 0; z-index: 1; pointer-events: none;
    background-image:
        linear-gradient(rgba(21,94,117,0.08) 1px, transparent 1px),
        linear-gradient(90deg, rgba(21,94,117,0.08) 1px, transparent 1px);
    background-size: 60px 60px;
    animation: gridDrift 25s linear infinite;
    opacity: 0.7;
}
@keyframes gridDrift {
    from { background-position: 0 0; }
    to   { background-position: 60px 60px; }
}

/* Floating orbs */
.orb {
    position: fixed; border-radius: 50%; filter: blur(100px);
    pointer-events: none; z-index: 1; animation: orbFloat 15s ease-in-out infinite alternate;
}
.orb-1 { 
    width: 350px; 
    height: 350px; 
    top: -5%; 
    left: 5%; 
    background: rgba(15,118,110,0.16); 
    animation-delay: 0s; 
}
.orb-2 { 
    width: 280px; 
    height: 280px; 
    bottom: 5%; 
    right: 10%; 
    background: rgba(180,83,9,0.13); 
    animation-delay: -7s; 
}
@keyframes orbFloat {
    0%   { transform: translate(0,0) scale(1); opacity: 0.7; }
    100% { transform: translate(40px, 50px) scale(1.1); opacity: 1; }
}

/* ── PAGE LAYOUT ── */
.page-wrap {
    position: relative; 
    z-index: 10;
    width: 100%;
    max-width: 1160px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(360px, 420px);
    gap: 28px;
    align-items: start;
}

/* Left content */
.info-side {
    display: block;
    background: rgba(42,42,42,0.92);
    border: 1px solid rgba(0,131,143,0.22);
    border-radius: 18px;
    padding: 26px;
    box-shadow: 0 8px 28px rgba(0,0,0,0.28);
}

.hero-nav {
    display: flex;
    gap: 12px;
    margin-top: 14px;
    margin-bottom: 16px;
}

.hero-link {
    border: 1px solid rgba(0,184,212,0.3);
    background: rgba(0,184,212,0.08);
    color: #a9f4ff;
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    font-weight: 700;
    text-transform: uppercase;
    cursor: pointer;
}

.hero-link.active {
    background: linear-gradient(135deg, #00838F 0%, #00b8d4 100%);
    color: #fff;
    border-color: transparent;
}

.hero-panel {
    display: none;
}

.hero-panel.active {
    display: block;
}

.hero-gallery {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}

.hero-shot {
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid rgba(0,184,212,0.22);
    background: rgba(0,0,0,0.18);
    min-height: 140px;
}

.hero-gallery .hero-shot {
    grid-column: 1 / -1;
    width: min(100%, 520px);
    margin: 0 auto;
}

.hero-shot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.hero-shot-fallback {
    width: 100%;
    height: 100%;
    min-height: 140px;
    display: grid;
    place-items: center;
    color: rgba(255,255,255,0.75);
    font-family: 'Space Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    background: linear-gradient(160deg, rgba(0,131,143,0.28), rgba(0,0,0,0.15));
}

.home-copy {
    color: rgba(255,255,255,0.86);
    line-height: 1.6;
    font-size: 1rem;
}

.contact-card {
    border: 1px solid rgba(0,184,212,0.24);
    border-radius: 12px;
    padding: 16px;
    background: rgba(0,184,212,0.04);
}

.contact-card h3 {
    color: #c9f7ff;
    margin: 0 0 6px;
    font-size: 1.05rem;
}

.contact-card p {
    color: rgba(255,255,255,0.7);
    font-size: 0.82rem;
    margin-bottom: 12px;
}

.contact-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.contact-field {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid rgba(0,184,212,0.26);
    background: rgba(0,184,212,0.06);
    color: #fff;
    font-size: 0.84rem;
    outline: none;
}

.contact-field::placeholder {
    color: rgba(255,255,255,0.45);
}

.contact-field:focus {
    border-color: #00b8d4;
    box-shadow: 0 0 0 3px rgba(0,184,212,0.14);
}

.contact-message {
    margin-top: 10px;
    min-height: 110px;
    resize: vertical;
}

.contact-submit {
    margin-top: 10px;
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
    border-radius: 8px;
    padding: 10px 14px;
    font-weight: 700;
    letter-spacing: 0.05em;
    background: linear-gradient(135deg, #00838F 0%, #00b8d4 100%);
    color: #fff;
    cursor: pointer;
}

.contact-direct {
    margin-top: 12px;
    font-size: 0.76rem;
    color: rgba(255,255,255,0.72);
    line-height: 1.65;
}

/* ── THEME REFRESH OVERRIDES ── */
.info-side {
    background: var(--panel);
    border: 1px solid var(--border);
    box-shadow: 0 14px 34px rgba(16,42,45,0.12);
}

.hero-link {
    border-color: rgba(21,94,117,0.24);
    background: rgba(15,118,110,0.08);
    color: var(--accent2);
}

.hero-link.active {
    background: linear-gradient(135deg, #0f766e 0%, #155e75 100%);
}

.hero-shot {
    border-color: rgba(21,94,117,0.18);
    background: rgba(15,118,110,0.06);
}

.hero-shot-fallback {
    color: var(--text-dim);
    font-family: 'JetBrains Mono', monospace;
    background: linear-gradient(160deg, rgba(15,118,110,0.18), rgba(255,255,255,0.44));
}

.home-copy,
.sys-desc,
.sys-caption,
.card-footer-note,
.contact-direct,
.contact-item-bottom {
    color: var(--text-dim);
}

.sys-badge-top,
.sys-badge,
.sys-sub,
.f-hint,
.card-corner,
.contact-item-bottom,
.contact-direct,
.f-input {
    font-family: 'JetBrains Mono', monospace;
}

.system-title,
.sys-title,
.login-head h2 {
    color: var(--txt);
}

.system-title span,
.sys-title span,
.link-admin,
.login-head p,
.f-label {
    color: var(--accent2);
}

.sys-badge-top {
    background: rgba(15,118,110,0.10);
    border-color: rgba(15,118,110,0.24);
    color: var(--accent2);
}

.sys-badge-top .pulse-dot {
    background: #16a34a;
    box-shadow: 0 0 8px rgba(22,163,74,0.5);
}

.contact-card,
.login-card,
.contact-info-bottom {
    background: rgba(255,255,255,0.86);
    border-color: var(--border);
}

.contact-card h3,
.contact-card p,
.contact-title,
.contact-item-bottom,
.f-label,
.f-icon,
.feature-pill {
    color: var(--txt);
}

.feature-pill {
    background: rgba(15,118,110,0.07);
    border-color: rgba(15,118,110,0.20);
}

.feature-pill i,
.f-icon,
.contact-item-bottom i,
.contact-title i {
    color: var(--accent);
}

.login-card {
    box-shadow: 0 16px 36px rgba(16,42,45,0.16), 0 0 0 1px rgba(15,118,110,0.10);
}

.login-card::before {
    background: linear-gradient(90deg, transparent, #0f766e, #155e75, #0f766e, transparent);
}

.card-corner,
.f-hint,
.f-divider::before,
.card-footer-note a {
    color: var(--muted);
}

.f-divider {
    border-top-color: rgba(63,95,102,0.24);
}

.f-divider::before {
    background: rgba(255,255,255,0.95);
}

.f-input,
.contact-field {
    background: #ffffff;
    border-color: rgba(21,94,117,0.26);
    color: var(--txt);
}

.f-input::placeholder,
.contact-field::placeholder {
    color: #88a0a7;
}

.f-input:focus,
.contact-field:focus {
    border-color: var(--accent2);
    box-shadow: 0 0 0 3px rgba(21,94,117,0.14);
}

.btn-access,
.contact-submit {
    background: linear-gradient(135deg, #0f766e 0%, #155e75 100%);
    font-family: 'Bricolage Grotesque', sans-serif;
}

.btn-access:hover,
.contact-submit:hover {
    background: linear-gradient(135deg, #0e8b80 0%, #1b6f87 100%);
}

.link-admin:hover,
.card-footer-note a:hover {
    color: var(--accent);
}

/* System Header Badge */
.system-header {
    text-align: center;
    margin-bottom: 25px;
}
.sys-badge-top {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(0,131,143,0.12);
    border: 1px solid rgba(0,131,143,0.3);
    color: #00b8d4;
    font-family: 'Space Mono', monospace;
    font-size: 0.65rem;
    letter-spacing: 0.15em;
    padding: 8px 16px;
    border-radius: 50px;
    margin-bottom: 12px;
    box-shadow: 0 4px 12px rgba(0,131,143,0.15);
}
.sys-badge-top .pulse-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #00ff88;
    box-shadow: 0 0 8px #00ff88;
    animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(0.9); }
}
.system-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: rgba(255,255,255,0.9);
    letter-spacing: 0.05em;
    margin: 0;
}
.system-title span {
    color: #00b8d4;
    font-weight: 800;
}

/* Top badge */
.sys-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(0,212,255,0.08);
    border: 1px solid rgba(0,212,255,0.25);
    color: var(--accent);
    font-family: 'Space Mono', monospace;
    font-size: 0.6rem;
    letter-spacing: 0.12em;
    padding: 4px 12px;
    border-radius: 40px;
    margin-bottom: 15px;
    width: fit-content;
}
.sys-badge span.dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--accent2);
    box-shadow: 0 0 8px var(--accent2);
    animation: blink 1.4s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }

.sys-title {
    font-size: 2.2rem;
    font-weight: 800;
    color: var(--txt);
    line-height: 1.1;
    margin-bottom: 8px;
    letter-spacing: -0.02em;
}
.sys-title span { color: var(--accent); }

.sys-sub {
    font-family: 'Space Mono', monospace;
    font-size: 0.65rem;
    color: var(--accent2);
    letter-spacing: 0.14em;
    margin-bottom: 18px;
}

.sys-desc {
    color: var(--text-dim);
    font-size: 0.85rem;
    line-height: 1.6;
    max-width: 440px;
    margin-bottom: 20px;
}

/* Feature chips */
.feature-list {
    display: flex; flex-direction: column; gap: 8px;
    margin-bottom: 25px;
}
.feature-item {
    display: flex; align-items: center; gap: 10px;
    background: rgba(0,212,255,0.05);
    border: 1px solid rgba(0,212,255,0.12);
    border-radius: 8px;
    padding: 8px 14px;
    transition: border-color .2s, background .2s;
}
.feature-item:hover {
    border-color: rgba(0,212,255,0.3);
    background: rgba(0,212,255,0.09);
}
.feature-icon {
    width: 28px; height: 28px;
    border-radius: 6px;
    background: rgba(0,212,255,0.12);
    display: flex; align-items: center; justify-content: center;
    color: var(--accent);
    font-size: 0.8rem;
    flex-shrink: 0;
}
.feature-text strong {
    display: block;
    color: #e0f0ff;
    font-size: 0.8rem;
    margin-bottom: 1px;
}
.feature-text span {
    color: var(--muted);
    font-size: 0.7rem;
    font-family: 'Space Mono', monospace;
}

/* ── INQUIRY SECTION ── */
.inquiry-box {
    background: rgba(255,209,102,0.06);
    border: 1px solid rgba(255,209,102,0.22);
    border-radius: 12px;
    padding: 16px 18px;
    max-width: 480px;
}
.inquiry-title {
    color: var(--gold);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin-bottom: 8px;
    display: flex; align-items: center; gap: 6px;
}
.inquiry-row {
    display: flex; align-items: flex-start; gap: 8px;
    margin-bottom: 6px;
}
.inquiry-row:last-child { margin-bottom: 0; }
.inq-icon {
    color: var(--gold);
    font-size: 0.75rem;
    margin-top: 1px;
    flex-shrink: 0;
    width: 16px; text-align: center;
}
.inq-text {
    color: rgba(255,230,150,0.8);
    font-size: 0.75rem;
    line-height: 1.5;
}
.inq-text strong {
    color: var(--gold);
    display: block;
    font-size: 0.7rem;
    letter-spacing: 0.06em;
    margin-bottom: 1px;
}
.inq-contact {
    font-family: 'Space Mono', monospace;
    font-size: 0.8rem;
    color: var(--accent2);
    font-weight: 700;
    letter-spacing: 0.05em;
}

/* ── COMPACT DESIGN STYLES ── */
.compact-header {
    text-align: left;
    margin-bottom: 16px;
}

.main-content {
    flex: 1;
}

/* ── COMPACT FEATURE GRID ── */
.feature-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 16px;
}

.feature-chip {
    display: flex; 
    align-items: center; 
    gap: 8px;
    background: rgba(0,229,255,0.08);
    border: 1px solid rgba(0,229,255,0.2);
    border-radius: 6px;
    padding: 6px 10px;
    transition: border-color .2s, background .2s;
    font-size: 0.7rem;
    color: var(--txt);
    font-weight: 500;
}

.feature-chip:nth-child(3) {
    grid-column: 1 / -1;
    justify-self: center;
    max-width: 200px;
}

.feature-chip:hover {
    border-color: rgba(0,229,255,0.4);
    background: rgba(0,229,255,0.12);
}

.feature-chip i {
    color: var(--accent);
    font-size: 0.75rem;
    width: 16px;
    text-align: center;
}

/* ── INLINE CONTACT ── */
.contact-inline {
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: rgba(255,184,0,0.08);
    border: 1px solid rgba(255,184,0,0.3);
    border-radius: 8px;
    padding: 10px 12px;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.68rem;
    color: rgba(255,230,150,0.95);
    font-weight: 500;
}

.contact-item i {
    color: var(--warning);
    width: 14px;
    text-align: center;
    font-size: 0.65rem;
}

/* ════════════════════════════
   LOGIN PANEL - COMPACT CENTERED
════════════════════════════ */
.login-side {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: transparent;
}

.login-card {
    width: 100%;
    background: rgba(42,42,42,0.98);
    border: 1px solid rgba(0,131,143,0.3);
    border-radius: 16px;
    padding: 40px 35px;
    backdrop-filter: blur(10px);
    box-shadow: 0 8px 32px rgba(0,0,0,0.4), 0 0 0 1px rgba(0,131,143,0.1);
    position: relative;
    overflow: hidden;
}
.login-card::before {
    content: '';
    position: absolute; 
    top: 0; 
    left: 0; 
    right: 0; 
    height: 3px;
    background: linear-gradient(90deg, transparent, #00838F, #00b8d4, #00838F, transparent);
    border-radius: 16px 16px 0 0;
}

/* Corner decoration */
.card-corner {
    position: absolute; 
    top: 15px; 
    right: 20px;
    font-family: 'Space Mono', monospace;
    font-size: 0.55rem;
    color: rgba(0,229,255,0.35);
    letter-spacing: 0.08em;
}

.login-head {
    text-align: center;
    margin-bottom: 28px;
}

/* Circuit logo */
.circuit-icon {
    width: 70px; 
    height: 70px;
    background: linear-gradient(135deg, rgba(0, 131, 143, 0.2), rgba(0, 184, 212, 0.15));
    border: 2px solid rgba(0, 131, 143, 0.4);
    border-radius: 50%;
    display: flex; 
    align-items: center; 
    justify-content: center;
    margin: 0 auto 16px;
    font-size: 1.6rem;
    color: #00b8d4;
    box-shadow: 0 0 30px rgba(0, 131, 143, 0.3), inset 0 2px 8px rgba(0,184,212,0.1);
    position: relative;
}
.circuit-icon::after {
    content: '';
    position: absolute; 
    inset: -6px;
    border-radius: 50%;
    border: 1px dashed rgba(0, 131, 143, 0.3);
    animation: spin 12s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.login-head h2 {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--txt);
    margin-bottom: 6px;
    letter-spacing: 0.02em;
}
.login-head p {
    font-family: 'Space Mono', monospace;
    font-size: 0.63rem;
    color: rgba(0,212,255,0.7);
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.sys-caption {
    font-size: 0.72rem;
    color: rgba(255,255,255,0.6);
    margin-top: 8px;
    font-weight: 400;
    letter-spacing: 0.01em;
    line-height: 1.5;
}

/* Feature Pills */
.feature-pills {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 18px;
    flex-wrap: wrap;
}
.feature-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(0,229,255,0.08);
    border: 1px solid rgba(0,229,255,0.2);
    border-radius: 20px;
    padding: 5px 12px;
    font-size: 0.65rem;
    color: rgba(255,255,255,0.75);
    font-weight: 500;
    transition: all .2s;
}
.feature-pill:hover {
    background: rgba(0,229,255,0.12);
    border-color: rgba(0,229,255,0.35);
    transform: translateY(-1px);
}
.feature-pill i {
    color: #00b8d4;
    font-size: 0.7rem;
}

/* Form fields */
.f-group { margin-bottom: 18px; }
.f-label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: rgba(0,212,255,0.85);
    text-transform: uppercase;
    margin-bottom: 7px;
}
.f-wrap {
    position: relative;
}
.f-icon {
    position: absolute; 
    left: 14px; 
    top: 50%;
    transform: translateY(-50%);
    color: rgba(0,229,255,0.5);
    font-size: 0.85rem;
    pointer-events: none;
    transition: color .25s ease;
}
.f-input {
    width: 100%;
    background: rgba(0,229,255,0.06);
    border: 1.5px solid rgba(0,229,255,0.2);
    border-radius: 10px;
    color: var(--txt);
    font-family: 'Space Mono', monospace;
    font-size: 0.85rem;
    padding: 12px 14px 12px 42px;
    outline: none;
    transition: all .25s ease;
    letter-spacing: 0.03em;
}
.f-input::placeholder { 
    color: rgba(255,255,255,0.35); 
    font-style: italic;
}
.f-input:focus {
    border-color: #00b8d4;
    background: rgba(0,229,255,0.10);
    box-shadow: 0 0 0 3px rgba(0,184,212,0.12), 0 4px 12px rgba(0,131,143,0.15);
    transform: translateY(-1px);
}
.f-input:focus + .f-icon {
    color: #00b8d4;
}
.f-icon {
    position: absolute; 
    left: 14px; 
    top: 50%;
    transform: translateY(-50%);
    color: rgba(0,229,255,0.5);
    font-size: 0.85rem;
    pointer-events: none;
    transition: color .25s ease;
}

/* Example hint */
.f-hint {
    font-family: 'Space Mono', monospace;
    font-size: 0.58rem;
    color: var(--muted);
    margin-top: 5px;
    padding-left: 3px;
}

/* Divider */
.f-divider {
    border: none;
    border-top: 1px solid rgba(80,80,80,0.35);
    margin: 24px 0 24px;
    position: relative;
}
.f-divider::before {
    content: 'OR';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(255,255,255,0.95);
    padding: 0 12px;
    font-size: 0.6rem;
    color: var(--muted);
    letter-spacing: 0.15em;
    font-family: 'Space Mono', monospace;
}

/* Submit button */
.btn-access {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #00838F 0%, #00b8d4 100%);
    color: #fff;
    font-family: 'Syne', sans-serif;
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    transition: all .2s ease;
    box-shadow: 0 4px 20px rgba(0,180,200,0.35), 0 2px 8px rgba(0,131,143,0.2);
}
.btn-access::after {
    content: '';
    position: absolute; 
    inset: 0;
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.2) 50%, transparent 100%);
    transform: translateX(-100%);
    transition: transform .5s;
}
.btn-access:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 6px 28px rgba(0,200,220,0.5), 0 4px 12px rgba(0,131,143,0.3);
    background: linear-gradient(135deg, #00949F 0%, #00c9db 100%);
}
.btn-access:hover::after { transform: translateX(100%); }
.btn-access:active { 
    transform: translateY(0); 
    box-shadow: 0 2px 12px rgba(0,180,200,0.3);
}
.link-admin {
    display: block;
    text-align: center;
    margin-top: 14px;
    font-size: 0.75rem;
    color: var(--accent2);
    text-decoration: none;
    font-weight: 600;
    transition: color .2s;
}
.link-admin:hover {
    color: var(--accent);
    text-decoration: underline;
}

/* Footer note */
.card-footer-note {
    text-align: center;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid rgba(80,80,80,0.3);
    font-size: 0.65rem;
    color: var(--muted);
    font-family: 'Space Mono', monospace;
    line-height: 1.8;
}
.card-footer-note a {
    color: var(--accent2);
    text-decoration: none;
    transition: color .2s;
}
.card-footer-note a:hover { 
    color: var(--accent);
}

/* Contact Info Below Card */
.contact-info-bottom {
    text-align: center;
    margin-top: 20px;
    padding: 15px;
    background: rgba(255,184,0,0.05);
    border: 1px solid rgba(255,184,0,0.2);
    border-radius: 12px;
    backdrop-filter: blur(10px);
}
.contact-title {
    font-size: 0.65rem;
    color: rgba(255,184,0,0.9);
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.contact-list {
    display: flex;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
}
.contact-item-bottom {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.7rem;
    color: rgba(255,230,150,0.9);
    font-family: 'Space Mono', monospace;
}
.contact-item-bottom i {
    color: rgba(255,184,0,0.8);
    font-size: 0.68rem;
}

/* Alert message */
.f-alert {
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 0.75rem;
    margin-bottom: 18px;
    display: none;
    font-family: 'Space Mono', monospace;
    animation: slideDown 0.3s ease;
}
.f-alert.error   { 
    background: rgba(239,68,68,0.15); 
    border: 1.5px solid rgba(239,68,68,0.35); 
    color: #fca5a5;
    box-shadow: 0 4px 12px rgba(239,68,68,0.15);
}
.f-alert.success { 
    background: rgba(10,245,160,0.12); 
    border: 1.5px solid rgba(10,245,160,0.35); 
    color: #6ee7b7;
    box-shadow: 0 4px 12px rgba(10,245,160,0.15);
}
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ── RESPONSIVE ── */
@media (max-width: 500px) {
    .page-wrap { 
        max-width: 100%; 
        display: block;
    }
    .info-side {
        margin-bottom: 16px;
        padding: 18px;
    }
    .hero-gallery {
        grid-template-columns: 1fr;
    }
    .contact-grid {
        grid-template-columns: 1fr;
    }
    .login-card {
        padding: 30px 22px;
    }
    .circuit-icon {
        width: 60px;
        height: 60px;
        font-size: 1.4rem;
    }
    .login-head h2 {
        font-size: 1.2rem;
    }
    .system-title {
        font-size: 1rem;
    }
    .sys-badge-top {
        font-size: 0.6rem;
        padding: 6px 12px;
    }
    .contact-list {
        flex-direction: column;
        gap: 8px;
    }
}
@media (max-height: 700px) {
    .system-header {
        margin-bottom: 15px;
    }
    .login-card {
        padding: 28px 30px;
    }
    .circuit-icon {
        width: 55px;
        height: 55px;
        font-size: 1.3rem;
    }
    .feature-pills {
        margin-top: 12px;
    }
}
</style>
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="page-wrap">

    <!-- ════ LEFT — SYSTEM INFO ════ -->
    <div class="info-side">

        <div class="compact-header">
            <div class="sys-badge">
                <span class="dot"></span>
                SYSTEM ONLINE
            </div>
            <h1 class="sys-title">Automated <span>Fish Dryer</span></h1>
            <div class="sys-sub">IoT MONITORING & CONTROL PLATFORM</div>

            <div class="hero-nav">
                <button type="button" class="hero-link active" data-target="homePanel">Home</button>
                <button type="button" class="hero-link" data-target="contactPanel">Contact Us</button>
            </div>
        </div>

        <div class="main-content hero-panel active" id="homePanel">
            <div class="hero-gallery">
                <div class="hero-shot">
                    <img src="assets/fishlogo.jpg" alt="Fish dryer prototype view" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                    <div class="hero-shot-fallback" style="display:none;">PROTOTYPE VIEW A</div>
                </div>
            </div>

            <p class="home-copy">
                Welcome to the Automated Fish Dryer Prototype Management System, a centralized platform for real-time monitoring,
                control, and data logging of registered IoT prototypes. Each unit is uniquely identified by model name and access code,
                allowing seamless and secure session access.
            </p>
        </div>

        <div class="main-content hero-panel" id="contactPanel">
            <div class="contact-card">
                <h3>Get Touch With Us</h3>
                <p>Interested in our automated fish dryer? Send us your inquiry.</p>

                <form id="inquiryForm" autocomplete="off">
                    <input type="hidden" name="action" value="send_inquiry">
                    <div class="contact-grid">
                        <input type="text" name="inq_name" class="contact-field" placeholder="Name" required>
                        <input type="text" name="inq_contact" class="contact-field" placeholder="Email or Phone">
                    </div>
                    <textarea name="inq_message" class="contact-field contact-message" placeholder="Messages" required></textarea>
                    <button type="submit" class="contact-submit"><i class="fas fa-paper-plane"></i>Order Now</button>
                </form>

                <div class="contact-direct">
                    Phone: <strong>+63 9977856704</strong><br>
                    Email: <strong>fd_admin@gmail.com</strong><br>
                    Support Hours: Monday - Friday | 8:00 AM - 5:00 PM
                </div>
            </div>
        </div>

    </div>

    <!-- ════ LOGIN PANEL ════ -->
    <div class="login-side">
        
        <!-- System Header -->
        <div class="system-header">
            <div class="sys-badge-top">
                <span class="pulse-dot"></span>
                SYSTEM ONLINE
            </div>
            <h1 class="system-title">Automated <span>Prototype</span> System</h1>
        </div>

        <div class="login-card">

            <div class="card-corner">v2.0 // APS</div>

            <div class="login-head">
                <div class="circuit-icon">
                    <i class="fas fa-robot"></i>
                </div>
                <h2>Prototype Access</h2>
                <p>IOT MONITORING & CONTROL</p>
                <div class="sys-caption">Enter your credentials to access the dashboard</div>
                
                <!-- Feature Pills -->
                <div class="feature-pills">
                    <div class="feature-pill">
                        <i class="fas fa-microchip"></i>
                        <span>Real-Time</span>
                    </div>
                    <div class="feature-pill">
                        <i class="fas fa-shield-alt"></i>
                        <span>Secure</span>
                    </div>
                    <div class="feature-pill">
                        <i class="fas fa-database"></i>
                        <span>Data Logging</span>
                    </div>
                </div>
            </div>

            <div class="f-alert" id="alertBox"></div>

            <form id="loginForm" autocomplete="off">
                <input type="hidden" name="action" value="login">

                <div class="f-group">
                    <label class="f-label">Unit / Model</label>
                    <div class="f-wrap">
                        <i class="fas fa-cube f-icon"></i>
                        <input type="text" name="model_name" id="modelName" class="f-input"
                               placeholder="e.g. Fishda" required autocomplete="off">
                    </div>
                    <div class="f-hint">// enter the registered unit or model identity</div>
                </div>

                <div class="f-group">
                    <label class="f-label">Model Code</label>
                    <div class="f-wrap">
                        <i class="fas fa-key f-icon"></i>
                        <input type="text" name="code" id="givenCode" class="f-input"
                               placeholder="e.g. FD2026" required autocomplete="off">
                    </div>
                    <div class="f-hint">// unique code assigned by administrator</div>
                </div>

                <hr class="f-divider">

                <button type="submit" class="btn-access">
                    <i class="fas fa-sign-in-alt" style="margin-right:8px;"></i>
                    Login to System
                </button>
                
                <a href="auth/admin_login.php" class="link-admin">
                    <i class="fas fa-user-shield" style="margin-right:5px;"></i>
                    Administrator Login
                </a>
            </form>

            <div class="card-footer-note">
                No access yet? Contact administrator to register<br>
                <a href="#">Automated Prototype System &copy; <?= date('Y') ?></a>
            </div>
        </div>

        <!-- Contact Info -->
        <div class="contact-info-bottom">
            <div class="contact-title">
                <i class="fas fa-headset"></i>
                Need Help?
            </div>
            <div class="contact-list">
                <div class="contact-item-bottom">
                    <i class="fas fa-phone"></i>
                    <span>+63 912 345 6789</span>
                </div>
                <div class="contact-item-bottom">
                    <i class="fas fa-envelope"></i>
                    <span>admin@protoautosys.edu.ph</span>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
const appStyles = getComputedStyle(document.documentElement);
const swalTheme = {
    background: appStyles.getPropertyValue('--swal-bg').trim() || '#ffffff',
    color: appStyles.getPropertyValue('--swal-text').trim() || '#102a2d'
};

document.querySelectorAll('.hero-link').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.hero-link').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.hero-panel').forEach(panel => panel.classList.remove('active'));
        this.classList.add('active');
        const target = document.getElementById(this.getAttribute('data-target'));
        if (target) target.classList.add('active');
    });
});

const inquiryForm = document.getElementById('inquiryForm');
if (inquiryForm) {
    inquiryForm.addEventListener('submit', function (e) {
        e.preventDefault();
        fetch(window.location.pathname, {
            method: 'POST',
            body: new FormData(this),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(async r => {
                const raw = await r.text();
                try {
                    return JSON.parse(raw);
                } catch (_) {
                    throw new Error('Invalid inquiry response');
                }
            })
            .then(data => {
                if (data.status === 'success') {
                    this.reset();
                    Swal.fire({
                        icon: 'success',
                        title: 'Inquiry Sent',
                        text: data.message || 'Your message has been sent.',
                        background: swalTheme.background,
                        color: swalTheme.color
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Send Failed',
                        text: data.message || 'Could not send inquiry.',
                        background: swalTheme.background,
                        color: swalTheme.color
                    });
                }
            })
            .catch(() => Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'Could not send your inquiry right now.',
                background: swalTheme.background,
                color: swalTheme.color
            }));
    });
}

document.getElementById('loginForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const alertBox = document.getElementById('alertBox');
    alertBox.style.display = 'none';

    Swal.fire({
        title: 'Verifying Prototype...',
        html: '<span style="font-size:.82rem;color:#64748b;font-family:monospace">Checking model credentials</span>',
        allowOutsideClick: false,
        background: swalTheme.background,
        color: swalTheme.color,
        didOpen: () => Swal.showLoading()
    });

    fetch(window.location.pathname, {
        method: 'POST',
        body: new FormData(this),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
        .then(async r => {
            const raw = await r.text();
            try {
                return JSON.parse(raw);
            } catch (_) {
                throw new Error('Invalid login response');
            }
        })
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Access Granted!',
                    text: data.message,
                    timer: 1800,
                    showConfirmButton: false,
                    background: swalTheme.background,
                    color: swalTheme.color
                }).then(() => window.location.href = data.redirect);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Access Denied',
                    text: data.message,
                    background: swalTheme.background,
                    color: swalTheme.color
                });
            }
        })
        .catch(() => Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Could not reach the server or received an invalid response.',
            background: swalTheme.background,
            color: swalTheme.color
        }));
});
</script>
</body>
</html>
