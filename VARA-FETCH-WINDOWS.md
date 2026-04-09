# VARA Log Fetcher Setup Guide (Windows)

This guide explains how to automatically fetch your VARA connection log from the BPQDash server to use with the BPQ Dashboard RF Connections page.

## What is the VARA Log?

The RF Connections dashboard displays your VARA HF connection history — which stations you connected to, when, on what frequency, signal reports, bytes transferred, etc. This data comes from a `.vara` log file.

**Where does this file come from?**

If you're part of the **BPQDash (Texas Packet Radio Forwarding Network)**, the network's central server logs all your VARA connections. Your personal log is available at:

```
https://your-domain.com/YOURCALL.vara
```

The fetch script downloads new entries from this URL and **appends** them to your local `.vara` file every 15 minutes, building up a complete connection history over time.

> **Not part of BPQDash?** If your node doesn't connect through BPQDash, you won't have a log file on their server (you'll get a 404 error). The RF Connections dashboard will still work with BBS logs, just without VARA-specific metrics. Contact BPQDash if you'd like to join the network.

---

## Quick Setup (PowerShell - Recommended)

PowerShell is built into Windows - no additional software needed.

### Step 1: Download the Script

Save `fetch-vara-bpqdash.ps1` to `C:\Scripts\`

### Step 2: Edit Your Callsign

Open `C:\Scripts\fetch-vara-bpqdash.ps1` in Notepad and change:
```powershell
$CALLSIGN = "YOURCALL"
```
to your callsign (e.g., `$CALLSIGN = "YOURCALL"`)

Also verify the log directory matches your web server:
```powershell
$LOG_DIR = "C:\UniServerZ\www\bpq\logs"
```

### Step 3: Test the Script

Open PowerShell as Administrator and run:
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
C:\Scripts\fetch-vara-bpqdash.ps1
```

You should see:
```
Fetching VARA log for YOURCALL from BPQDash server...
Created C:\UniServerZ\www\bpq\logs\YOURCALL.vara
SUCCESS: VARA log updated at 01/22/2026 10:30:00
```

### Step 4: Schedule to Run Every 15 Minutes

1. Open **Task Scheduler** (search in Start menu)
2. Click **Create Basic Task**
3. Name: `Fetch VARA Log from BPQDash`
4. Trigger: **Daily**
5. Action: **Start a program**
6. Program: `powershell.exe`
7. Arguments: `-ExecutionPolicy Bypass -File "C:\Scripts\fetch-vara-bpqdash.ps1"`
8. Click **Finish**

**Set 15-minute repeat:**
1. Right-click the task → **Properties**
2. Go to **Triggers** tab → **Edit**
3. Check **"Repeat task every"** → select **15 minutes**
4. Set **"for a duration of"** → **Indefinitely**
5. Click **OK** twice

---

## Alternative: Using wget (Batch File)

If you prefer using wget:

### Step 1: Install wget

Download wget for Windows from:
https://eternallybored.org/misc/wget/

Extract `wget.exe` to `C:\Windows\System32\` or add it to your PATH.

### Step 2: Use the Batch Script

Save `fetch-vara-bpqdash.bat` to `C:\Scripts\` and edit:
```batch
set CALLSIGN=YOURCALL
set LOG_DIR=C:\UniServerZ\www\bpq\logs
```

### Step 3: Schedule in Task Scheduler

Same as above, but use:
- Program: `C:\Scripts\fetch-vara-bpqdash.bat`

---

## Configure BPQ Dashboard

Edit `bpq-rf-connections.html` and set your VARA log filename:

```javascript
const VARA_LOG_FILE = 'YOURCALL.vara';  // Change to your callsign
```

This is near the top of the `<script>` section (around line 629).

---

## Verify It's Working

1. Open your browser to: `http://localhost/bpq/bpq-rf-connections.html`
2. Check the status indicator shows "X connections" (not "No VARA log")
3. Your connection history should appear in the dashboard

---

## Troubleshooting

### "No VARA log" Error
- Verify the file exists: `C:\UniServerZ\www\bpq\logs\YOURCALL.vara`
- Check the filename matches `VARA_LOG_FILE` in the HTML
- Make sure the fetch script ran successfully

### Download Fails
- Check your internet connection
- Verify your callsign has a log on the server:
  - Open browser to `https://your-domain.com/YOURCALL.vara`
  - If you get 404, contact BPQDash to set up your log

### Script Won't Run
- PowerShell: Run `Set-ExecutionPolicy RemoteSigned` as Administrator
- Batch: Make sure wget.exe is in your PATH

### Empty Data
- The log may be new with no connections yet
- Wait for VARA connections to be logged by the network

---

## File Locations Summary

| File | Location |
|------|----------|
| PowerShell script | `C:\Scripts\fetch-vara-bpqdash.ps1` |
| Batch script (alt) | `C:\Scripts\fetch-vara-bpqdash.bat` |
| VARA log output | `C:\UniServerZ\www\bpq\logs\YOURCALL.vara` |
| Dashboard HTML | `C:\UniServerZ\www\bpq\bpq-rf-connections.html` |

---

## Support

- BPQ Network: https://bpqdash.net/
- BPQ Dashboard Issues: Check CHANGELOG.md and README.md
