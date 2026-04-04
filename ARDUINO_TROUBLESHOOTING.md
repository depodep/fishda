## Arduino Connection Troubleshooting Guide

**Current Issue**: Arduino shows "POST error: -1" when trying to reach server
- Arduino IP: 192.168.1.106 ✓
- Server IP: 192.168.1.100 ✓
- Network: Same subnet ✓

## Quick Diagnostics

### 1. Test XAMPP Web Server
Open a web browser and try these URLs:

**On your computer:**
- `http://localhost/fishda/` 
- `http://192.168.1.100/fishda/`

**Expected**: Should show the fish drying system login page

### 2. Test API Directly
Try this URL in browser:
```
http://192.168.1.100/fishda/api/session_api.php?action=poll_session_status&access_code=APS-ESP-2026&model_unit=Fishda&model_code=FD2026&device_unique_code=ESP8266-UNIT-001
```

**Expected**: Should return JSON like:
```json
{"status":"success","message":"Session status","data":{"status":"Idle"}}
```

### 3. Check XAMPP Status
1. Open XAMPP Control Panel
2. Verify Apache is **Started** (green)
3. Verify MySQL is **Started** (green)
4. Check if port 80 is available

### 4. Windows Firewall Check
The firewall might be blocking external connections to port 80:

1. Open Windows Firewall settings
2. Allow "Apache HTTP Server" through firewall
3. Or temporarily disable Windows Firewall for testing

## Quick Fixes

### Fix 1: Allow Apache through Firewall
```cmd
netsh advfirewall firewall add rule name="Apache" dir=in action=allow protocol=TCP localport=80
```

### Fix 2: Test with Computer's IP
Try accessing from another device on same network:
`http://192.168.1.100/fishda/`

### Fix 3: Alternative Arduino Server IP
If 192.168.1.100 doesn't work, try using localhost binding:
```cpp
const char* serverIP = "192.168.1.106";  // Your computer's actual IP
```

## Expected Results
✅ Browser shows login page → XAMPP working
✅ API returns JSON → API accessible  
✅ Arduino connects → Network OK