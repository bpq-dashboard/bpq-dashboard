# BPQ Dashboard v1.5.5 — Troubleshooting Guide

---

## Dashboard page won't load / "This site can't be reached"

**Check nginx is running:**
```bash
sudo systemctl status nginx
sudo systemctl start nginx
sudo nginx -t
```
If `nginx -t` shows errors, check:
```bash
sudo cat /var/log/nginx/bpq-dashboard-error.log
```

---

## "502 Bad Gateway" error

PHP-FPM is not running.
```bash
sudo systemctl status php8.3-fpm
sudo systemctl start php8.3-fpm
```
If php8.3 is not installed try php8.2:
```bash
sudo systemctl start php8.2-fpm
```

---

## Chat says "daemon not connected" or won't connect

**Step 1** — Is LinBPQ running?
```bash
pgrep -x linbpq && echo "Running" || echo "NOT running"
```
If not running, start it:
```bash
cd /home/linbpq && sudo ./linbpq mail chat &
```

**Step 2** — Is port 8010 listening?
```bash
ss -tnlp | grep 8010
```
Should show a line with :8010. If not, check TCPPORT=8010 in bpq32.cfg.

**Step 3** — Is the BPQ chat daemon running?
```bash
sudo systemctl status bpq-chat
sudo journalctl -u bpq-chat -n 30
```

**Step 4** — Is the password correct?
```bash
sudo nano /var/www/html/bpq/config.php
```
Check `bbs.pass` matches the password in your bpq32.cfg USER= line.

---

## APRS map shows no stations

**Step 1** — Check daemon is running:
```bash
sudo systemctl status bpq-aprs
```

**Step 2** — Check it is connected and receiving:
```bash
cat /var/www/html/bpq/cache/aprs/aprs-daemon.json
```
Should show `"connected": true` and `"packets"` greater than 0.

**Step 3** — Wrong APRS passcode?
```bash
sudo journalctl -u bpq-aprs -n 20 | grep -i "verif\|login\|unverif"
```
If you see "unverified" — your passcode is wrong.
Get the correct passcode: https://apps.magicbug.co.uk/passcode/
Then update config.php:
```bash
sudo nano /var/www/html/bpq/config.php
sudo systemctl restart bpq-aprs
```

**Step 4** — Clear old cache:
```bash
sudo rm -f /var/www/html/bpq/cache/aprs/stations.json
sudo systemctl restart bpq-aprs
```

---

## BBS messages won't load

Check the BPQ telnet password in config.php matches bpq32.cfg:
```bash
sudo nano /var/www/html/bpq/config.php
# bbs.pass must match the USER= line in bpq32.cfg
```

Check LinBPQ BBS port (8010) is accessible:
```bash
telnet 127.0.0.1 8010
```
Should show a login prompt. Type Ctrl+] then quit to exit.

---

## "Permission denied" errors

Fix all permissions in one command:
```bash
sudo chown -R www-data:www-data /var/www/html/bpq
sudo chmod -R 755 /var/www/html/bpq
sudo chmod -R 775 /var/www/html/bpq/cache /var/www/html/bpq/data
sudo chmod 640 /var/www/html/bpq/config.php
```

---

## Database connection error

**Check MariaDB is running:**
```bash
sudo systemctl status mariadb
sudo systemctl start mariadb
```

**Test connection:**
```bash
mysql -u tprfn_user -p bpqdash -e "SELECT 1"
```

**Reset database password:**
```bash
sudo mysql
ALTER USER 'tprfn_user'@'localhost' IDENTIFIED BY 'your-new-password';
FLUSH PRIVILEGES;
exit
```
Then update config.php with the new password.

---

## Daemon won't start after reboot

Check it is enabled to start at boot:
```bash
sudo systemctl is-enabled bpq-chat
sudo systemctl is-enabled bpq-aprs
```
If it shows `disabled`:
```bash
sudo systemctl enable bpq-chat
sudo systemctl enable bpq-aprs
```

---

## install-check.php shows many failures

Run it and work through each red item from top to bottom.
Each red item shows the exact command to fix it.
After fixing items, refresh the page to re-run checks.

Access: `http://your-server-ip/install-check.php`
Password: `bpqcheck`

---

## Something else is wrong

**Collect information for support:**
```bash
# Save system status to a file
sudo systemctl status bpq-chat bpq-aprs nginx mariadb > /tmp/status.txt
sudo journalctl -u bpq-chat -u bpq-aprs -n 50 >> /tmp/status.txt
cat /tmp/status.txt
```

Email the output to: sysop@example.com
Include your callsign and a description of the problem.

---

73 de YOURCALL — sysop@example.com

---

## BBS Rules only showing 2 of 4 / rules disappearing after reload

This is a browser localStorage quota issue. The 5MB limit fills up
with saved message bodies and new saves silently fail.

Fix — switch to server storage:
1. Open bbs-messages.html
2. Click the **💻 Browser storage** chip in the toolbar
3. It should switch to **☁️ Server storage**
4. Re-enter your rules — they now save to the server permanently

If server storage shows "unavailable":
```bash
# Create storage directory
sudo mkdir -p /var/www/html/bpq/data/messages
sudo chown -R www-data:www-data /var/www/html/bpq/data/messages
sudo chmod 775 /var/www/html/bpq/data/messages

# Test endpoint
sudo -u www-data php -r "
\$_SERVER['REQUEST_METHOD']='GET';
\$_SERVER['PHP_SELF']='/message-storage.php';
\$_GET['action']='stats';
chdir('/var/www/html/bpq');
include '/var/www/html/bpq/message-storage.php';
"
```

Should return: `{"success":true,...}`

---

## Rule-matched messages stay green after reading / folder badge not clearing

Apply latest bbs-messages.html from the dashboard archive.
The fix requires server storage to be enabled so read state persists.

---

## After saving message to folder it stays in inbox

Apply latest bbs-messages.html — messages are now removed from
inbox array immediately after being saved to a folder.

