# Dynamic Scheduling Setup Instructions

## 🚀 **Dynamic Automatic Scheduling is Now Available!**

### **What This Does:**
- **NO TASK SCHEDULER NEEDED!** System is self-managing
- Automatically starts scheduled drying sessions when their time arrives
- Automatically stops sessions when duration expires
- Shows scheduled session info on the dashboard 
- Disables parameter controls for scheduled sessions
- Real-time countdown for scheduled sessions

---

## **Setup Steps:**

### **1. Run Database Migration**
Execute this **ONCE** to add the `schedule_id` column:
```
http://localhost/fishda/database/migrate_schedule_id.php
```

### **2. Setup Windows Task Scheduler**
1. Open **Task Scheduler** (search "Task Scheduler" in Windows)
2. Click **"Create Basic Task"**
3. Name: `Fish Drying Auto Scheduler`
4. Trigger: **Daily** 
5. Start: **Today**
6. Repeat: **Every 1 minute** for **Indefinitely**
7. Action: **Start a program**
8. Program: `C:\xampp\htdocs\fishda\run_scheduler.bat`
9. Click **Finish**

### **3. Create Logs Directory** (Optional)
```bash
mkdir C:\xampp\htdocs\fishda\logs
```

---

## **How It Works:**

### **📅 Scheduling Sessions:**
- Use the calendar to add scheduled batches
- Set date, time, temperature, and humidity
- Status shows as "Scheduled" 

### **⚡ Auto-Start Process:**
- Scheduler runs every minute
- Finds schedules due to start (within last 2 minutes)  
- Checks if prototype is online
- Automatically starts session with scheduled parameters
- Updates schedule status to "Running"

### **🎛️ Dashboard Changes:**
- **Scheduled sessions** show blue info box with schedule details
- Parameter sliders are **disabled** for scheduled sessions
- **Stop button** appears when session is running
- Timer shows **elapsed time** for all sessions

### **📊 Session Types:**
- **Manual**: Started by user, full parameter control
- **Scheduled**: Auto-started, parameters locked from schedule

---

## **Testing:**
1. Create a schedule for 2 minutes from now
2. Wait for auto-start 
3. Check that:
   - Session starts automatically
   - Dashboard shows scheduled session info
   - Parameter controls are disabled
   - Stop button is available

---

## **Troubleshooting:**
- Check `C:\xampp\htdocs\fishda\logs\scheduler.log` for scheduler activity
- Ensure prototype is online when schedule is due
- Only one session can run per user at a time
- Schedules are skipped if device is offline

**✅ Automatic scheduling is now fully functional!** 🎯