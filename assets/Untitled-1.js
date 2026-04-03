// =====================================================================
//  PATCH for users_dashboard.php — JavaScript section
//  3 fixes:
//   A) Refresh/session persistence — already works via checkExistingSession()
//      but the PHP top guard needs one small tweak (see PHP section below)
//   B) pollLiveData() — handle COOLDOWN phase from server
//   C) updatePhase() — add Drying + Cooldown phases (fan is heat source)
//   D) updateHWChips() label fix — "Fan (Heat)" instead of just "Fan"
// =====================================================================

// ── A) PHP TOP GUARD FIX (replace lines 1-11 of users_dashboard.php) ─
/*
<?php
session_start();
include('dbcon.php');

// Session guard — if no valid session, go to login
// Using isset($_SESSION['sid']) is more reliable than isset($_SESSION['username'])
if (!isset($_SESSION['sid']) || $_SESSION['permission'] !== 'user') {
    header('Location: index.php');
    exit;
}
$username = htmlspecialchars($_SESSION['username']);
$user_id  = $_SESSION['sid'];
?>
*/
// The key fix: check $_SESSION['sid'] (set during login) not just 'username'
// This prevents refresh from destroying the session context.
// Also make sure index.php sets session_cache_limiter('private') before session_start()
// so browsers don't cache the redirect.

// ── B) Replace pollLiveData() with this version ────────────────────────
async function pollLiveData(){
  if(!sessionRunning) return;
  try{
    const j=await(await fetch('../api/session_api.php?action=get_live_data')).json();
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

      // ── Handle COOLDOWN phase ──────────────────────────────────
      if(d.ctrl_status === 'COOLDOWN'){
        const rem = parseInt(d.cooldown_remaining)||0;
        const mins = Math.floor(rem/60);
        const secs = rem%60;
        updatePhase('Cooldown');
        // Show cooldown banner
        const banner = document.getElementById('fishReadyBanner');
        if(banner){
          banner.style.display='';
          document.getElementById('fishReadyMsg').textContent=
            `🌀 Cooldown: ${mins}m ${secs}s remaining — system will resume heating automatically.`;
        }
        updateHWChips({heater_state:0, exhaust_state:0, fan_state:0});
        if(d.recorded_temp !== null){
          document.getElementById('liveTemp').textContent=temp.toFixed(1);
          document.getElementById('liveHum').textContent=hum.toFixed(1);
          updateMiniChart(temp,hum);
        }
        // Keep polling during cooldown
        if(sessionRunning) setTimeout(pollLiveData,3000);
        return;
      }

      if(d.recorded_temp !== null){
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
        updateMiniChart(temp,hum);
        updateDryingProgress(hum, currentSetHum);
        if(d.fish_ready){
          document.getElementById('fishReadyBanner').style.display='';
          document.getElementById('fishReadyMsg').textContent=
            `Temp ${temp.toFixed(1)}°C / Humidity ${hum.toFixed(1)}% — targets reached! Cooling down, will auto-resume.`;
          showToast('success','🎉 Target Reached!','5-min cooldown started. System will auto-resume.',5000);
        }
      }
    } else if(j.status==='error'){
      sessionRunning=false;
      clearInterval(timerInterval);
      updateControlUI(false);
      updateSessionBadge(false);
      showToast('warning','Session Ended',j.message||'Session was stopped.',5000);
      return;
    }
  }catch(e){}
  if(sessionRunning) setTimeout(pollLiveData,3000);
}

// ── C) Replace updatePhase() — add Drying and Cooldown ─────────────────
function updatePhase(phase){
  const p=phase||'Idle';
  const icons={
    Heating  :'fa-fan',        // fan is the heat source
    Drying   :'fa-fan',        // fan running at target temp
    Exhaust  :'fa-wind',
    Cooldown :'fa-snowflake',
    Idle     :'fa-circle-dot',
    Done     :'fa-check'
  };
  const ic=icons[p]||'fa-circle-dot';
  const el=document.getElementById('phaseBadge');
  if(el){ el.className=`phase-badge phase-${p}`; el.innerHTML=`<i class="fas ${ic} me-1"></i>${p}`; }
}

// ── D) Replace updateHWChips() — label Fan as heat source ──────────────
function updateHWChips(d){
  const chips=[
    {label:'Fan (Heat)', on: parseInt(d.fan_state)===1},
    {label:'Exhaust',    on: parseInt(d.exhaust_state)===1},
    {label:'Heater',     on: parseInt(d.heater_state)===1},
  ];
  document.getElementById('hwChips').innerHTML=chips.map(c=>
    `<span class="hw-chip"><span class="${c.on?'dot-on':'dot-off'}"></span>${c.label}${c.on?' <b style="color:#4ade80;font-size:9px;">ON</b>':''}</span>`
  ).join('');
}

// ── E) index.php — add this before session_start() to prevent cache issues ──
/*
  In index.php, at the very top (line 1), add:
    <?php
    session_cache_limiter('private_no_expire');   // <-- ADD THIS
    session_start();
    ...

  In users_dashboard.php, at the very top:
    <?php
    session_cache_limiter('private_no_expire');   // <-- ADD THIS
    session_start();
    ...

  This prevents the browser from caching the redirect to index.php
  and showing the login page on F5 refresh when the session is still valid.
*/