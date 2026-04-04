<?php
session_cache_limiter('private_no_expire');
session_start();
include('../database/dbcon.php');

if (isset($_SESSION['username']) && ($_SESSION['permission'] ?? '') === 'admin') {
    header('Location: admin_sessions.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_login') {
    header('Content-Type: application/json');

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        echo json_encode(['status' => 'error', 'message' => 'Username and password are required.']);
        exit;
    }

    try {
        $stmt = $dbh->prepare("SELECT id, username, password, permission, status FROM tblusers WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid username or password.']);
            exit;
        }

        if (($user['permission'] ?? '') !== 'admin') {
            echo json_encode(['status' => 'error', 'message' => 'This account is not an admin account.']);
            exit;
        }

        if ((int)($user['status'] ?? 0) !== 1) {
            echo json_encode(['status' => 'error', 'message' => 'This admin account is disabled.']);
            exit;
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['permission'] = 'admin';

        echo json_encode(['status' => 'success', 'message' => 'Admin login successful.', 'redirect' => 'admin_sessions.php']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Login failed.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
  --bg:#080f1a;
  --panel:rgba(10,20,40,0.88);
  --border:rgba(0,212,255,0.2);
  --txt:#e8f4ff;
  --muted:rgba(255,255,255,0.42);
  --accent:#00d4ff;
}
*{box-sizing:border-box}
body{
  margin:0;
  min-height:100vh;
  font-family:'Syne',sans-serif;
  color:var(--txt);
  background:
    radial-gradient(circle at 12% 20%, rgba(0,212,255,.12), transparent 35%),
    radial-gradient(circle at 88% 80%, rgba(10,245,160,.09), transparent 32%),
    linear-gradient(165deg,#030914,#07101d 55%,#040b16);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:24px;
}
.card{
  width:min(420px,100%);
  background:var(--panel);
  border:1px solid var(--border);
  border-radius:18px;
  padding:30px 28px;
  box-shadow:0 20px 60px rgba(0,0,0,.55);
}
.title{font-size:1.4rem;font-weight:800;margin:0 0 6px}
.sub{margin:0 0 20px;font-family:'Space Mono',monospace;font-size:.72rem;color:var(--muted);letter-spacing:.08em}
.label{display:block;font-size:.7rem;font-weight:700;letter-spacing:.1em;color:rgba(0,212,255,.8);margin-bottom:6px;text-transform:uppercase}
.wrap{position:relative;margin-bottom:14px}
.icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:rgba(0,212,255,.45);font-size:.84rem}
.input{
  width:100%;
  border:1px solid rgba(0,212,255,.18);
  border-radius:10px;
  background:rgba(0,212,255,.05);
  color:var(--txt);
  padding:11px 12px 11px 36px;
  font-family:'Space Mono',monospace;
  outline:none;
}
.input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,212,255,.1)}
.btn{
  width:100%;
  margin-top:6px;
  border:none;
  border-radius:10px;
  padding:12px;
  font-weight:800;
  letter-spacing:.08em;
  color:#fff;
  background:linear-gradient(135deg,#007ec4 0%, #00b8d4 55%, #00c9a0 100%);
  cursor:pointer;
}
.back{display:block;text-align:center;margin-top:14px;color:rgba(0,212,255,.72);text-decoration:none;font-size:.8rem}
</style>
</head>
<body>
  <div class="card">
    <h1 class="title">Admin Login</h1>
    <p class="sub">USERNAME + PASSWORD REQUIRED</p>

    <form id="adminForm" autocomplete="off">
      <input type="hidden" name="action" value="admin_login">

      <label class="label">Username</label>
      <div class="wrap">
        <i class="fas fa-user-shield icon"></i>
        <input class="input" type="text" name="username" placeholder="Enter admin username" required>
      </div>

      <label class="label">Password</label>
      <div class="wrap">
        <i class="fas fa-lock icon"></i>
        <input class="input" type="password" name="password" placeholder="Enter admin password" required>
      </div>

      <button type="submit" class="btn">LOGIN AS ADMIN</button>
    </form>

    <a class="back" href="../index.php">Back to Prototype Login</a>
  </div>

<script>
document.getElementById('adminForm').addEventListener('submit', function(e){
  e.preventDefault();
  Swal.fire({
    title:'Verifying Admin...',
    allowOutsideClick:false,
    background:'#080f1a',
    color:'#e8f4ff',
    didOpen:()=>Swal.showLoading()
  });

  fetch('', { method:'POST', body:new FormData(this) })
    .then(r=>r.json())
    .then(data=>{
      if(data.status==='success'){
        Swal.fire({icon:'success',title:'Welcome, Admin',text:data.message,timer:1300,showConfirmButton:false,background:'#080f1a',color:'#e8f4ff'})
          .then(()=>window.location.href=data.redirect);
      } else {
        Swal.fire({icon:'error',title:'Login Failed',text:data.message,background:'#080f1a',color:'#e8f4ff'});
      }
    })
    .catch(()=>Swal.fire({icon:'error',title:'Network Error',text:'Could not reach server.',background:'#080f1a',color:'#e8f4ff'}));
});
</script>
</body>
</html>
