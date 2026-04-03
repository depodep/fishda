<?php
// ============================================================
//  esp_test.php — ESP8266 Connection Diagnostic Tool
//  Open this in browser: http://YOUR_IP/fish_drying/esp_test.php
//  DELETE this file after testing!
// ============================================================
require_once '../database/dbcon.php';

// ── Handle manual test POST (simulate ESP8266) ──
$manualResult = null;
if (isset($_POST['simulate'])) {
    $t = floatval($_POST['sim_temp'] ?? 35);
    $h = floatval($_POST['sim_hum']  ?? 60);
    $ch = curl_init('http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/../api/sensor_api.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => "temp=$t&humidity=$h",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $manualResult = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) $manualResult = json_encode(['error' => $curlErr]);
}

// ── Fetch latest 10 sensor readings ──
$rows = [];
$tableExists = false;
try {
    $check = $dbh->query("SHOW TABLES LIKE 'sensor_readings'");
    $tableExists = $check->rowCount() > 0;
    if ($tableExists) {
        $rows = $dbh->query(
            "SELECT id, temperature, humidity, session_id, timestamp
             FROM sensor_readings ORDER BY timestamp DESC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// ── Check controls row ──
$controls = [];
try {
    $controls = $dbh->query("SELECT * FROM drying_controls WHERE id=1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Check active session ──
$activeSession = [];
try {
    $activeSession = $dbh->query(
        "SELECT session_id, user_id, set_temp, set_humidity, status, start_time
         FROM drying_sessions WHERE status='Running' ORDER BY start_time DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$lastRow = $rows[0] ?? null;
$secondsAgo = $lastRow ? round((time() - strtotime($lastRow['timestamp']))) : null;
$espOk = $lastRow && $secondsAgo < 30;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="10">
<title>ESP8266 Diagnostic</title>
<style>
  body { font-family: monospace; background: #0d1117; color: #c9d1d9; padding: 20px; }
  h2   { color: #58a6ff; }
  .ok  { color: #3fb950; font-weight: bold; }
  .err { color: #f85149; font-weight: bold; }
  .warn{ color: #d29922; font-weight: bold; }
  table{ border-collapse: collapse; width: 100%; margin-top: 10px; }
  th,td{ border: 1px solid #30363d; padding: 8px 12px; text-align: left; font-size: 13px; }
  th   { background: #161b22; color: #58a6ff; }
  tr:nth-child(even) { background: #161b22; }
  .card{ background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
  .badge{ display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:bold; }
  .badge-ok  { background:#1a3a1a; color:#3fb950; border:1px solid #3fb950; }
  .badge-err { background:#3a1a1a; color:#f85149; border:1px solid #f85149; }
  .badge-warn{ background:#3a2a10; color:#d29922; border:1px solid #d29922; }
  form input[type=number]{ background:#0d1117; border:1px solid #30363d; color:#c9d1d9; padding:5px 10px; border-radius:6px; width:80px; }
  form button{ background:#238636; border:none; color:#fff; padding:8px 20px; border-radius:6px; cursor:pointer; font-weight:bold; margin-left:10px; }
  pre { background:#0d1117; border:1px solid #30363d; padding:12px; border-radius:6px; overflow-x:auto; color:#79c0ff; font-size:12px; }
</style>
</head>
<body>
<h2>🔧 ESP8266 + Fish Drying — Connection Diagnostic</h2>
<p style="color:#8b949e;font-size:12px;">Page auto-refreshes every 10 seconds. <strong style="color:#f85149">DELETE esp_test.php after done testing!</strong></p>

<!-- ── STATUS SUMMARY ── -->
<div class="card">
  <h3 style="margin:0 0 12px;color:#e6edf3">📡 ESP8266 Connection Status</h3>
  <?php if (!$tableExists): ?>
    <span class="badge badge-err">❌ sensor_readings table MISSING</span>
    <p class="err" style="margin-top:8px">Run fish_drying_FINAL.sql first! The table does not exist in your database.</p>
  <?php elseif (!$lastRow): ?>
    <span class="badge badge-err">❌ No data received yet</span>
    <p class="warn" style="margin-top:8px">Table exists but ESP8266 has not sent any data. Check: WiFi, IP address in .ino, and that you uploaded the new firmware.</p>
  <?php elseif ($espOk): ?>
    <span class="badge badge-ok">✅ ESP8266 CONNECTED — data arriving</span>
    <p class="ok" style="margin-top:8px">Last reading: <?= $secondsAgo ?>s ago — Temp: <?= $lastRow['temperature'] ?>°C / Humidity: <?= $lastRow['humidity'] ?>%</p>
  <?php else: ?>
    <span class="badge badge-warn">⚠️ Data is STALE — last reading <?= $secondsAgo ?>s ago</span>
    <p class="warn" style="margin-top:8px">ESP8266 may have disconnected. Expected a reading every 10 seconds.</p>
  <?php endif; ?>
</div>

<!-- ── CHECKLIST ── -->
<div class="card">
  <h3 style="margin:0 0 12px;color:#e6edf3">✅ Setup Checklist</h3>
  <table>
    <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
    <tr>
      <td>sensor_readings table</td>
      <td><?= $tableExists ? '<span class="ok">✅ EXISTS</span>' : '<span class="err">❌ MISSING — run SQL</span>' ?></td>
      <td>Required for live monitoring</td>
    </tr>
    <tr>
      <td>ESP8266 sending data</td>
      <td><?php
        if (!$tableExists) echo '<span class="err">❌ Cannot check</span>';
        elseif (!$lastRow) echo '<span class="err">❌ No rows yet</span>';
        elseif ($espOk)    echo '<span class="ok">✅ Active (' . $secondsAgo . 's ago)</span>';
        else               echo '<span class="warn">⚠️ Stale (' . $secondsAgo . 's ago)</span>';
      ?></td>
      <td>Should get a row every 10 seconds</td>
    </tr>
    <tr>
      <td>drying_controls row</td>
      <td><?= $controls ? '<span class="ok">✅ EXISTS</span>' : '<span class="err">❌ MISSING</span>' ?></td>
      <td>Status: <?= htmlspecialchars($controls['status'] ?? 'N/A') ?></td>
    </tr>
    <tr>
      <td>Active drying session</td>
      <td><?= $activeSession ? '<span class="ok">✅ RUNNING (ID: ' . $activeSession['session_id'] . ')</span>' : '<span class="warn">— None running</span>' ?></td>
      <td><?= $activeSession ? "Temp: {$activeSession['set_temp']}°C / Hum: {$activeSession['set_humidity']}%" : 'Start one from dashboard' ?></td>
    </tr>
    <tr>
      <td>ESP8266 target URL</td>
      <td><span class="ok">Should be →</span></td>
      <td><code style="color:#79c0ff">http://<?= $_SERVER['HTTP_HOST'] ?>/fishda/api/sensor_api.php</code></td>
    </tr>
  </table>
</div>

<!-- ── SIMULATE ESP ── -->
<div class="card">
  <h3 style="margin:0 0 12px;color:#e6edf3">🧪 Simulate ESP8266 POST (Test Without Hardware)</h3>
  <form method="POST">
    <label>Temp: <input type="number" name="sim_temp" value="48" step="0.1" min="0" max="120"></label>
    &nbsp;
    <label>Humidity: <input type="number" name="sim_hum" value="35" step="0.1" min="0" max="100"></label>
    <button type="submit" name="simulate" value="1">▶ Send Test Reading</button>
  </form>
  <?php if ($manualResult !== null): ?>
  <p style="margin-top:10px;font-size:12px;color:#8b949e">sensor_api.php responded:</p>
  <pre><?= htmlspecialchars(json_encode(json_decode($manualResult), JSON_PRETTY_PRINT)) ?></pre>
  <?php endif; ?>
</div>

<!-- ── LIVE READINGS TABLE ── -->
<div class="card">
  <h3 style="margin:0 0 12px;color:#e6edf3">📊 Last 10 Readings from sensor_readings</h3>
  <?php if (empty($rows)): ?>
    <p class="warn">No rows found in sensor_readings.</p>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Temperature °C</th><th>Humidity %</th><th>Session ID</th><th>Timestamp</th><th>Age</th></tr>
    <?php foreach ($rows as $i => $r): 
      $age = round((time() - strtotime($r['timestamp'])));
    ?>
    <tr>
      <td><?= $r['id'] ?></td>
      <td style="color:#58a6ff;font-weight:bold"><?= $r['temperature'] ?>°C</td>
      <td style="color:#3fb950;font-weight:bold"><?= $r['humidity'] ?>%</td>
      <td><?= $r['session_id'] ?? '<span style="color:#8b949e">—</span>' ?></td>
      <td><?= $r['timestamp'] ?></td>
      <td <?= $age < 15 ? 'class="ok"' : ($age < 60 ? 'class="warn"' : 'class="err"') ?>><?= $age ?>s ago</td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

</body>
</html>