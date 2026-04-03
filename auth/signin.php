<?php
session_start();
include('../database/dbcon.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    header('Content-Type: application/json');
    $username = htmlspecialchars(trim($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (strlen($username) < 3) { echo json_encode(['status' => 'error', 'message' => 'Username must be at least 3 characters.']); exit; }
    if (strlen($password) < 6) { echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']); exit; }

    $sql_check = "SELECT id FROM tblusers WHERE username = :username LIMIT 1";
    $query_check = $dbh->prepare($sql_check);
    $query_check->execute([':username' => $username]);

    if ($query_check->rowCount() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'This identity already exists in our IoT node directory.']);
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $sql_ins = "INSERT INTO tblusers (username, password, permission, status) VALUES (:u, :p, 'user', 1)";
        $query_ins = $dbh->prepare($sql_ins);
        if ($query_ins->execute([':u' => $username, ':p' => $hashed])) {
            echo json_encode(['status' => 'success', 'message' => 'Registration complete! Welcome to the Smart Fish Drying System.']);
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Smart Fish Drying System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;900&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    :root {
        --ocean-deep:   #020d1a;
        --ocean-bright: #0a7abf;
        --ocean-light:  #14b8f5;
        --ocean-foam:   #7de8ff;
        --card-bg:      rgba(2, 18, 38, 0.68);
        --border:       rgba(20,184,245,.15);
        --text-muted:   rgba(255,255,255,.38);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'Outfit', sans-serif;
        min-height: 100vh;
        position: relative;
        display: flex;
        align-items: stretch;
        overflow: hidden;
    }

    .bg-campus {
        position: fixed; inset: 0; z-index: 0;
        background: url('../assets/0547b4a4-4216-4045-b7e2-cc42bb6e47af.jpg') center center / cover no-repeat;
    }
    .bg-campus::after {
        content: '';
        position: absolute; inset: 0;
        background: linear-gradient(108deg, rgba(2,14,35,.78) 0%, rgba(3,20,50,.72) 48%, rgba(2,12,32,.92) 100%);
    }
    .bg-shimmer {
        position: fixed; inset: 0; z-index: 1; pointer-events: none;
        background:
            radial-gradient(ellipse 70% 40% at 15% 60%, rgba(20,184,245,.07) 0%, transparent 60%),
            radial-gradient(ellipse 50% 60% at 80% 20%, rgba(10,122,191,.08) 0%, transparent 55%);
        animation: shimmerDrift 12s ease-in-out infinite alternate;
    }
    @keyframes shimmerDrift { 0%{opacity:.6;transform:scale(1);} 100%{opacity:1;transform:scale(1.04);} }

    .page-wrap {
        position: relative; z-index: 10;
        display: flex; width: 100vw; min-height: 100vh; align-items: center;
    }

    /* LEFT BRANDING */
    .brand-side {
        flex: 1; display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        text-align: center; padding: 60px 80px;
    }

    .logo-ring-wrap { position: relative; width: 170px; height: 170px; margin: 0 auto 28px; }
    .logo-ring-svg  { position: absolute; inset: -10px; width: calc(100% + 20px); height: calc(100% + 20px); animation: ringRotate 10s linear infinite; }
    @keyframes ringRotate { to { transform: rotate(360deg); } }

    .brand-logo {
        width: 170px; height: 170px; border-radius: 50%; object-fit: cover; display: block;
        border: 3px solid rgba(20,184,245,.5);
        box-shadow: 0 0 0 8px rgba(20,184,245,.07), 0 0 55px rgba(20,184,245,.2), 0 18px 48px rgba(0,0,0,.7);
        animation: logoPulse 4s ease-in-out infinite;
    }
    @keyframes logoPulse {
        0%,100%{ box-shadow:0 0 0 8px rgba(20,184,245,.07),0 0 55px rgba(20,184,245,.2),0 18px 48px rgba(0,0,0,.7); }
        50%    { box-shadow:0 0 0 16px rgba(20,184,245,.04),0 0 80px rgba(20,184,245,.3),0 18px 48px rgba(0,0,0,.7); }
    }

    .brand-univ {
        font-family: 'Fira Code', monospace; font-size: .72rem;
        letter-spacing: .22em; text-transform: uppercase;
        color: var(--ocean-foam); margin-bottom: 12px; opacity: .85;
    }
    .brand-title {
        font-size: 2.7rem; font-weight: 900; line-height: 1.12; margin-bottom: 14px;
        text-shadow: 0 4px 30px rgba(0,0,0,.7);
        background: linear-gradient(135deg, #ffffff 0%, #a8e8ff 45%, var(--ocean-foam) 100%);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .brand-divider {
        display: flex; align-items: center; gap: 10px; justify-content: center;
        margin: 0 auto 18px; color: var(--ocean-light); opacity: .7; font-size: 1.1rem;
    }
    .brand-divider::before, .brand-divider::after {
        content: ''; display: block; width: 40px; height: 2px;
        background: linear-gradient(90deg, transparent, var(--ocean-light), transparent); border-radius: 99px;
    }
    .brand-desc { font-size: .93rem; color: var(--text-muted); line-height: 1.85; max-width: 400px; }

    .iot-row { display: flex; gap: 12px; margin-top: 32px; flex-wrap: wrap; justify-content: center; }
    .iot-chip {
        display: flex; align-items: center; gap: 7px;
        background: rgba(20,184,245,.08); border: 1px solid rgba(20,184,245,.18);
        border-radius: 99px; padding: 7px 14px;
        font-size: .76rem; font-weight: 600; color: rgba(255,255,255,.55); backdrop-filter: blur(8px);
    }
    .iot-chip i { color: var(--ocean-light); font-size: .8rem; }

    /* RIGHT CARD */
    .card-side {
        width: 400px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        padding: 44px 36px; min-height: 100vh;
        background: var(--card-bg);
        backdrop-filter: blur(22px) saturate(160%);
        -webkit-backdrop-filter: blur(22px) saturate(160%);
        border-left: 1px solid var(--border);
        box-shadow: -8px 0 40px rgba(0,0,0,.35);
    }
    .login-card { width: 100%; }

    .secure-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(20,184,245,.1); border: 1px solid rgba(20,184,245,.28);
        color: var(--ocean-foam); font-size: .68rem; font-weight: 700;
        letter-spacing: .14em; text-transform: uppercase;
        padding: 5px 13px; border-radius: 99px; margin-bottom: 16px;
    }
    .card-title { font-size: 1.85rem; font-weight: 900; color: #fff; margin-bottom: 5px; letter-spacing: -.02em; }
    .card-sub   { font-size: .84rem; color: var(--text-muted); margin-bottom: 28px; }

    /* Tabs */
    .auth-tabs {
        display: flex; background: rgba(255,255,255,.05);
        border: 1px solid rgba(20,184,245,.12);
        border-radius: 50px; padding: 4px; margin-bottom: 26px; position: relative;
    }
    .tab-slider {
        position: absolute; top: 4px; left: 4px;
        width: calc(50% - 4px); height: calc(100% - 8px);
        background: linear-gradient(135deg, #0a7abf 0%, #0560a0 100%);
        border-radius: 50px; border: 1px solid rgba(20,184,245,.3);
        box-shadow: 0 0 18px rgba(20,184,245,.25), inset 0 1px 0 rgba(255,255,255,.15);
        transform: translateX(100%); /* Register tab active */
    }
    .auth-tab-btn {
        flex: 1; z-index: 1; position: relative;
        background: none; border: none; color: var(--text-muted);
        font-family: 'Outfit', sans-serif; font-size: .84rem; font-weight: 700;
        padding: 9px; border-radius: 50px; cursor: pointer; transition: color .3s;
        letter-spacing: .04em; text-decoration: none;
        display: flex; align-items: center; justify-content: center;
    }
    .auth-tab-btn.active { color: #fff; }

    .f-label {
        display: block; font-size: .68rem; font-weight: 700;
        letter-spacing: .12em; text-transform: uppercase;
        color: rgba(125,232,255,.65); margin-bottom: 7px;
    }
    .f-input {
        width: 100%; background: rgba(10,122,191,.1);
        border: 1px solid rgba(20,184,245,.18); border-radius: 10px;
        padding: 11px 40px 11px 40px; color: #fff;
        font-family: 'Outfit', sans-serif; font-size: .91rem; outline: none; transition: .3s;
    }
    .f-input::placeholder { color: rgba(255,255,255,.2); }
    .f-input:focus { border-color: rgba(20,184,245,.55); background: rgba(20,184,245,.1); box-shadow: 0 0 0 3px rgba(20,184,245,.1); }
    .f-wrap { position: relative; margin-bottom: 16px; }
    .f-icon-left { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: rgba(20,184,245,.5); font-size: .84rem; pointer-events: none; }
    .f-eye { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,.25); cursor: pointer; transition: color .2s; font-size: .84rem; }
    .f-eye:hover { color: var(--ocean-foam); }

    .strength-bar-wrap { margin-top: -8px; margin-bottom: 12px; height: 3px; background: rgba(255,255,255,.07); border-radius: 99px; overflow: hidden; }
    .strength-bar { height: 100%; width: 0; border-radius: 99px; transition: width .4s, background .4s; }

    .btn-login {
        width: 100%; padding: 13px; border-radius: 10px; border: none;
        font-family: 'Outfit', sans-serif; font-size: .9rem; font-weight: 800;
        letter-spacing: .1em; text-transform: uppercase; cursor: pointer;
        background: linear-gradient(135deg, #0a90df 0%, #0568b0 100%);
        color: #fff; box-shadow: 0 4px 22px rgba(10,122,191,.45), inset 0 1px 0 rgba(255,255,255,.18);
        transition: .3s; margin-top: 4px;
        display: flex; align-items: center; justify-content: center; gap: 8px;
        position: relative; overflow: hidden;
    }
    .btn-login::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(125,232,255,.15) 0%,transparent 60%); opacity:0; transition:.3s; }
    .btn-login:hover { background:linear-gradient(135deg,#12a8f5 0%,#0880d0 100%); transform:translateY(-2px); box-shadow:0 8px 32px rgba(10,122,191,.55),inset 0 1px 0 rgba(255,255,255,.18); }
    .btn-login:hover::before { opacity:1; }
    .btn-login:active { transform:translateY(0); }

    .switch-link { text-align:center; margin-top:16px; font-size:.78rem; color:var(--text-muted); }
    .switch-link a { color:var(--ocean-foam); text-decoration:none; font-weight:700; }
    .switch-link a:hover { text-shadow:0 0 10px rgba(125,232,255,.5); }

    .card-footer-text { margin-top:24px; text-align:center; font-size:.68rem; color:rgba(255,255,255,.18); line-height:1.8; }
    .card-footer-text a { color:var(--ocean-light); text-decoration:none; font-weight:600; }

    @media (max-width: 768px) {
        .page-wrap { flex-direction: column; }
        .brand-side { padding: 50px 28px 30px; }
        .card-side { width: 100%; min-height: auto; padding: 30px 24px 50px; border-left: none; border-top: 1px solid var(--border); }
        body { overflow-y: auto; }
        .brand-title { font-size: 2rem; }
        .iot-row { display: none; }
    }
    </style>
</head>
<body>

<div class="bg-campus"></div>
<div class="bg-shimmer"></div>

<div class="page-wrap">

    <!-- LEFT: BRANDING -->
    <div class="brand-side">
        <div class="logo-ring-wrap">
            <svg class="logo-ring-svg" viewBox="0 0 190 190" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="95" cy="95" r="90" stroke="rgba(20,184,245,0.25)" stroke-width="1.5" stroke-dasharray="6 5"/>
                <circle cx="95" cy="5" r="5" fill="#14b8f5" opacity="0.8"/>
            </svg>
            <img src="../assets/625084455_1314712234011631_2618349884753441586_n.jpg" alt="ISU Logo" class="brand-logo" onerror="this.src='../assets/fishlogo.jpg'">
        </div>

        <p class="brand-univ">Isabela State University</p>
        <h1 class="brand-title">Smart Fish<br>Drying System</h1>
        <div class="brand-divider"><i class="fas fa-fish"></i></div>
        <p class="brand-desc">
            IoT-powered aquatic drying control with real-time sensor monitoring,
            automated heat management, and live session analytics
            for superior fish preservation.
        </p>
        <div class="iot-row">
            <div class="iot-chip"><i class="fas fa-microchip"></i> ESP32 Sensors</div>
            <div class="iot-chip"><i class="fas fa-wifi"></i> IoT Linked</div>
            <div class="iot-chip"><i class="fas fa-droplet"></i> Humidity Control</div>
            <div class="iot-chip"><i class="fas fa-bolt"></i> Live Alerts</div>
        </div>
    </div>

    <!-- RIGHT: REGISTER CARD -->
    <div class="card-side">
        <div class="login-card">

            <div class="secure-badge">
                <i class="fas fa-user-plus"></i> Create Account
            </div>
            <h2 class="card-title">New Account</h2>
            <p class="card-sub">Register your identity node.</p>

            <!-- Tabs -->
            <div class="auth-tabs">
                <div class="tab-slider"></div>
                <a href="../index.php" class="auth-tab-btn">Sign In</a>
                <a href="signin.php" class="auth-tab-btn active">Register</a>
            </div>

            <!-- Register Form -->
            <form id="regForm" autocomplete="off">
                <input type="hidden" name="action" value="register">

                <label class="f-label">Username</label>
                <div class="f-wrap">
                    <i class="fas fa-user f-icon-left"></i>
                    <input type="text" name="username" class="f-input" placeholder="Choose your identity code" required autocomplete="off" minlength="3">
                </div>

                <label class="f-label">Password</label>
                <div class="f-wrap">
                    <i class="fas fa-lock f-icon-left"></i>
                    <input type="password" name="password" id="regPass" class="f-input" placeholder="Min. 6 characters" required autocomplete="new-password" minlength="6" oninput="checkStrength(this.value)">
                    <i class="fas fa-eye f-eye" onclick="togglePass('regPass', this)"></i>
                </div>
                <div class="strength-bar-wrap"><div class="strength-bar" id="strengthBar"></div></div>

                <label class="f-label">Confirm Password</label>
                <div class="f-wrap">
                    <i class="fas fa-lock f-icon-left"></i>
                    <input type="password" id="regPassConfirm" class="f-input" placeholder="Re-enter password" required autocomplete="new-password">
                    <i class="fas fa-eye f-eye" onclick="togglePass('regPassConfirm', this)"></i>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-fish"></i> CREATE ACCOUNT
                </button>
            </form>

            <div class="switch-link">
                Already have an account? <a href="../index.php">Sign in →</a>
            </div>

            <div class="card-footer-text">
                Smart Fish Drying System • IoT Based<br>
                <a href="#">Isabela State University — Angadanan Campus</a>
            </div>

        </div>
    </div>

</div>

<script>
function togglePass(id, icon) {
    const inp = document.getElementById(id);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}

function checkStrength(val) {
    const bar = document.getElementById('strengthBar');
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^a-zA-Z0-9]/.test(val)) score++;
    const map = [
        {w:'0%',bg:'transparent'},{w:'25%',bg:'#ef4444'},{w:'50%',bg:'#f97316'},
        {w:'75%',bg:'#eab308'},{w:'90%',bg:'#22c55e'},{w:'100%',bg:'#14b8f5'},
    ];
    bar.style.width = map[score].w;
    bar.style.background = map[score].bg;
}

document.getElementById('regForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const pass    = document.getElementById('regPass').value;
    const confirm = document.getElementById('regPassConfirm').value;
    if (pass !== confirm) { Swal.fire({ icon:'warning', title:'Passwords do not match', background:'#020d1a', color:'#fff' }); return; }
    if (pass.length < 6)  { Swal.fire({ icon:'warning', title:'Password too short', text:'Minimum 6 characters.', background:'#020d1a', color:'#fff' }); return; }

    Swal.fire({ title:'Creating Account...', html:'<span style="font-size:.85rem;color:#64748b">Registering IoT node identity</span>', allowOutsideClick:false, background:'#020d1a', color:'#fff', didOpen:()=>Swal.showLoading() });

    fetch('', { method:'POST', body:new FormData(this) })
        .then(r=>r.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({ icon:'success', title:'Account Created!', text:data.message, background:'#020d1a', color:'#fff', confirmButtonText:'Sign In Now' })
                    .then(()=>window.location.href='../index.php');
            } else {
                Swal.fire({ icon:'error', title:'Registration Failed', text:data.message, background:'#020d1a', color:'#fff' });
            }
        })
        .catch(()=>Swal.fire({ icon:'error', title:'Network Error', background:'#020d1a', color:'#fff' }));
});
</script>
</body>
</html>