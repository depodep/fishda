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
include('dbcon.php');

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
        $redirectPath = ($basePath !== '' ? $basePath : '') . '/users_dashboard.php';
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
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
    --bg:        #080f1a;
    --panel:     rgba(10,20,40,0.82);
    --accent:    #00d4ff;
    --accent2:   #0af5a0;
    --gold:      #ffd166;
    --border:    rgba(0,212,255,0.18);
    --muted:     rgba(255,255,255,0.38);
    --txt:       #e8f4ff;
}

*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }

body {
    font-family: 'Syne', sans-serif;
    min-height: 100vh;
    background: var(--bg);
    display: flex;
    align-items: stretch;
    overflow-x: hidden;
    position: relative;
}

/* ── ANIMATED BACKGROUND ── */
.bg-layer {
    position: fixed; inset: 0; z-index: 0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 50%, rgba(0,212,255,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 80% 50%, rgba(10,245,160,0.05) 0%, transparent 55%),
        linear-gradient(160deg, #040c18 0%, #060f1e 50%, #050b16 100%);
}

/* Grid lines */
.bg-grid {
    position: fixed; inset: 0; z-index: 1; pointer-events: none;
    background-image:
        linear-gradient(rgba(0,212,255,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,212,255,0.04) 1px, transparent 1px);
    background-size: 50px 50px;
    animation: gridDrift 20s linear infinite;
}
@keyframes gridDrift {
    from { background-position: 0 0; }
    to   { background-position: 50px 50px; }
}

/* Floating orbs */
.orb {
    position: fixed; border-radius: 50%; filter: blur(80px);
    pointer-events: none; z-index: 1; animation: orbFloat 12s ease-in-out infinite alternate;
}
.orb-1 { width:420px; height:420px; top:-10%; left:-5%; background:rgba(0,212,255,0.07); animation-delay:0s; }
.orb-2 { width:320px; height:320px; bottom:0; right:-5%; background:rgba(10,245,160,0.06); animation-delay:-5s; }
@keyframes orbFloat {
    0%   { transform: translate(0,0) scale(1); }
    100% { transform: translate(30px,40px) scale(1.08); }
}

/* ── PAGE LAYOUT ── */
.page-wrap {
    position: relative; z-index: 10;
    display: flex;
    width: 100vw; min-height: 100vh;
}

/* ════════════════════════════
   LEFT — SYSTEM INFO
════════════════════════════ */
.info-side {
    flex: 1.1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 60px 70px;
    border-right: 1px solid var(--border);
    position: relative;
}

/* Top badge */
.sys-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(0,212,255,0.08);
    border: 1px solid rgba(0,212,255,0.25);
    color: var(--accent);
    font-family: 'Space Mono', monospace;
    font-size: 0.7rem;
    letter-spacing: 0.12em;
    padding: 6px 14px;
    border-radius: 40px;
    margin-bottom: 30px;
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
    font-size: 2.8rem;
    font-weight: 800;
    color: #fff;
    line-height: 1.15;
    margin-bottom: 10px;
    letter-spacing: -0.02em;
}
.sys-title span { color: var(--accent); }

.sys-sub {
    font-family: 'Space Mono', monospace;
    font-size: 0.72rem;
    color: var(--accent2);
    letter-spacing: 0.14em;
    margin-bottom: 28px;
}

.sys-desc {
    color: rgba(220,240,255,0.65);
    font-size: 0.95rem;
    line-height: 1.75;
    max-width: 440px;
    margin-bottom: 36px;
}

/* Feature chips */
.feature-list {
    display: flex; flex-direction: column; gap: 12px;
    margin-bottom: 40px;
}
.feature-item {
    display: flex; align-items: center; gap: 14px;
    background: rgba(0,212,255,0.05);
    border: 1px solid rgba(0,212,255,0.12);
    border-radius: 10px;
    padding: 12px 18px;
    transition: border-color .2s, background .2s;
}
.feature-item:hover {
    border-color: rgba(0,212,255,0.3);
    background: rgba(0,212,255,0.09);
}
.feature-icon {
    width: 36px; height: 36px;
    border-radius: 8px;
    background: rgba(0,212,255,0.12);
    display: flex; align-items: center; justify-content: center;
    color: var(--accent);
    font-size: 0.9rem;
    flex-shrink: 0;
}
.feature-text strong {
    display: block;
    color: #e0f0ff;
    font-size: 0.88rem;
    margin-bottom: 2px;
}
.feature-text span {
    color: var(--muted);
    font-size: 0.75rem;
    font-family: 'Space Mono', monospace;
}

/* ── INQUIRY SECTION ── */
.inquiry-box {
    background: rgba(255,209,102,0.06);
    border: 1px solid rgba(255,209,102,0.22);
    border-radius: 14px;
    padding: 20px 22px;
    max-width: 480px;
}
.inquiry-title {
    color: var(--gold);
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin-bottom: 12px;
    display: flex; align-items: center; gap: 8px;
}
.inquiry-row {
    display: flex; align-items: flex-start; gap: 10px;
    margin-bottom: 10px;
}
.inquiry-row:last-child { margin-bottom: 0; }
.inq-icon {
    color: var(--gold);
    font-size: 0.85rem;
    margin-top: 2px;
    flex-shrink: 0;
    width: 18px; text-align: center;
}
.inq-text {
    color: rgba(255,230,150,0.8);
    font-size: 0.83rem;
    line-height: 1.6;
}
.inq-text strong {
    color: var(--gold);
    display: block;
    font-size: 0.75rem;
    letter-spacing: 0.06em;
    margin-bottom: 2px;
}
.inq-contact {
    font-family: 'Space Mono', monospace;
    font-size: 0.88rem;
    color: var(--accent2);
    font-weight: 700;
    letter-spacing: 0.05em;
}

/* ════════════════════════════
   RIGHT — LOGIN PANEL
════════════════════════════ */
.login-side {
    width: 480px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 50px 44px;
}

.login-card {
    width: 100%;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 38px 36px 32px;
    backdrop-filter: blur(22px);
    box-shadow:
        0 0 0 1px rgba(0,212,255,0.06),
        0 24px 64px rgba(0,0,0,0.7),
        inset 0 1px 0 rgba(255,255,255,0.05);
    position: relative;
    overflow: hidden;
}
.login-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent), var(--accent2), transparent);
    border-radius: 22px 22px 0 0;
}

/* Corner decoration */
.card-corner {
    position: absolute; top: 18px; right: 22px;
    font-family: 'Space Mono', monospace;
    font-size: 0.6rem;
    color: rgba(0,212,255,0.3);
    letter-spacing: 0.08em;
}

.login-head {
    text-align: center;
    margin-bottom: 30px;
}

/* Circuit logo */
.circuit-icon {
    width: 64px; height: 64px;
    background: rgba(0,212,255,0.08);
    border: 1px solid rgba(0,212,255,0.25);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    font-size: 1.5rem;
    color: var(--accent);
    box-shadow: 0 0 28px rgba(0,212,255,0.15);
    position: relative;
}
.circuit-icon::after {
    content: '';
    position: absolute; inset: -5px;
    border-radius: 50%;
    border: 1px dashed rgba(0,212,255,0.2);
    animation: spin 10s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.login-head h2 {
    font-size: 1.35rem;
    font-weight: 800;
    color: #fff;
    margin-bottom: 4px;
}
.login-head p {
    font-family: 'Space Mono', monospace;
    font-size: 0.68rem;
    color: var(--muted);
    letter-spacing: 0.1em;
}

/* Form fields */
.f-group { margin-bottom: 18px; }
.f-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: rgba(0,212,255,0.8);
    text-transform: uppercase;
    margin-bottom: 7px;
}
.f-wrap {
    position: relative;
}
.f-icon {
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%);
    color: rgba(0,212,255,0.45);
    font-size: 0.85rem;
    pointer-events: none;
}
.f-input {
    width: 100%;
    background: rgba(0,212,255,0.04);
    border: 1px solid rgba(0,212,255,0.18);
    border-radius: 10px;
    color: #e8f4ff;
    font-family: 'Space Mono', monospace;
    font-size: 0.88rem;
    padding: 12px 14px 12px 40px;
    outline: none;
    transition: border-color .2s, box-shadow .2s, background .2s;
    letter-spacing: 0.04em;
}
.f-input::placeholder { color: rgba(255,255,255,0.2); }
.f-input:focus {
    border-color: var(--accent);
    background: rgba(0,212,255,0.07);
    box-shadow: 0 0 0 3px rgba(0,212,255,0.1);
}

/* Example hint */
.f-hint {
    font-family: 'Space Mono', monospace;
    font-size: 0.63rem;
    color: rgba(0,212,255,0.4);
    margin-top: 5px;
    padding-left: 2px;
}

/* Divider */
.f-divider {
    border: none;
    border-top: 1px solid var(--border);
    margin: 22px 0 18px;
}

/* Submit button */
.btn-access {
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #007ec4 0%, #00b8d4 50%, #00c9a0 100%);
    color: #fff;
    font-family: 'Syne', sans-serif;
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    transition: transform .15s, box-shadow .15s;
    box-shadow: 0 4px 20px rgba(0,180,200,0.3);
}
.btn-access::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.15) 50%, transparent 100%);
    transform: translateX(-100%);
    transition: transform .5s;
}
.btn-access:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(0,200,220,0.4); }
.btn-access:hover::after { transform: translateX(100%); }
.btn-access:active { transform: translateY(0); }

/* Footer note */
.card-footer-note {
    text-align: center;
    margin-top: 22px;
    font-size: 0.7rem;
    color: var(--muted);
    font-family: 'Space Mono', monospace;
    line-height: 1.7;
}
.card-footer-note a {
    color: rgba(0,212,255,0.6);
    text-decoration: none;
}
.card-footer-note a:hover { color: var(--accent); }

/* Alert message */
.f-alert {
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.78rem;
    margin-bottom: 16px;
    display: none;
    font-family: 'Space Mono', monospace;
}
.f-alert.error   { background: rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.3); color: #fca5a5; }
.f-alert.success { background: rgba(10,245,160,0.10); border:1px solid rgba(10,245,160,0.3); color: #6ee7b7; }

/* ── RESPONSIVE ── */
@media (max-width: 960px) {
    .page-wrap   { flex-direction: column; }
    .info-side   { padding: 50px 30px; border-right: none; border-bottom: 1px solid var(--border); }
    .login-side  { width: 100%; padding: 40px 24px; }
    .sys-title   { font-size: 2rem; }
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

        <div class="sys-badge">
            <span class="dot"></span>
            SYSTEM ONLINE
        </div>

        <h1 class="sys-title">Automated<br><span>Prototype</span><br>System</h1>
        <div class="sys-sub">// IoT-BASED MONITORING &amp; CONTROL PLATFORM</div>

        <p class="sys-desc">
            Welcome to the Automated Prototype Management System — a centralized platform for real-time monitoring, control, and data logging of registered IoT-based prototypes. Each unit is uniquely identified by its model name and access code, allowing seamless and secure session access.
        </p>

        <div class="feature-list">
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-microchip"></i></div>
                <div class="feature-text">
                    <strong>Real-Time Automation</strong>
                    <span>Live sensor data &amp; actuator control per prototype</span>
                </div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-shield-halved"></i></div>
                <div class="feature-text">
                    <strong>Secure Code-Based Access</strong>
                    <span>Each prototype has a unique model name &amp; assigned code</span>
                </div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <div class="feature-text">
                    <strong>Data Logging &amp; History</strong>
                    <span>Automated records stored per session per unit</span>
                </div>
            </div>
        </div>

        <!-- INQUIRY SECTION -->
        <div class="inquiry-box">
            <div class="inquiry-title">
                <i class="fas fa-headset"></i>
                HOW TO ORDER / INQUIRE
            </div>

            <div class="inquiry-row">
                <i class="fas fa-circle-info inq-icon"></i>
                <div class="inq-text">
                    <strong>HOW TO ORDER</strong>
                    To order or request access to a prototype, contact the system administrator with your desired model and your institution details. You will be issued a unique model name and access code upon approval.
                </div>
            </div>

            <div class="inquiry-row">
                <i class="fas fa-phone inq-icon"></i>
                <div class="inq-text">
                    <strong>CONTACT THE ADMIN</strong>
                    <span class="inq-contact">📞 +63 912 345 6789</span>
                </div>
            </div>

            <div class="inquiry-row">
                <i class="fas fa-envelope inq-icon"></i>
                <div class="inq-text">
                    <strong>EMAIL</strong>
                    admin@protoautosys.edu.ph
                </div>
            </div>

            <div class="inquiry-row">
                <i class="fas fa-clock inq-icon"></i>
                <div class="inq-text">
                    <strong>SUPPORT HOURS</strong>
                    Monday – Friday &nbsp;|&nbsp; 8:00 AM – 5:00 PM
                </div>
            </div>
        </div>

    </div>

    <!-- ════ RIGHT — LOGIN PANEL ════ -->
    <div class="login-side">
        <div class="login-card">

            <div class="card-corner">v2.0 // APS</div>

            <div class="login-head">
                <div class="circuit-icon">
                    <i class="fas fa-robot"></i>
                </div>
                <h2>Prototype Access</h2>
                <p>ENTER YOUR CREDENTIALS TO CONTINUE</p>
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
                    <i class="fas fa-microchip" style="margin-right:8px;"></i>
                    Login Prototype
                </button>
                <br>
                <a href="admin_login.php">Login as Admin</a>
            </form>

            <div class="card-footer-note">
                No access yet? Contact your administrator<br>
                to register your prototype.<br>
                <a href="#">Automated Prototype System &copy; <?= date('Y') ?></a>
            </div>
        </div>
    </div>

</div>

<script>
document.getElementById('loginForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const alertBox = document.getElementById('alertBox');
    alertBox.style.display = 'none';

    Swal.fire({
        title: 'Verifying Prototype...',
        html: '<span style="font-size:.82rem;color:#64748b;font-family:monospace">Checking model credentials</span>',
        allowOutsideClick: false,
        background: '#080f1a',
        color: '#e8f4ff',
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
                    background: '#080f1a',
                    color: '#e8f4ff'
                }).then(() => window.location.href = data.redirect);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Access Denied',
                    text: data.message,
                    background: '#080f1a',
                    color: '#e8f4ff'
                });
            }
        })
        .catch(() => Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Could not reach the server or received an invalid response.',
            background: '#080f1a',
            color: '#e8f4ff'
        }));
});
</script>
</body>
</html>
