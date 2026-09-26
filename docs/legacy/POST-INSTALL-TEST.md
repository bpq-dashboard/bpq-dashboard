# BPQ Dashboard v1.5.11 — Post-Installation Testing Guide

Work through these tests after running install.sh.
Each test tells you what to look for and what to do if it fails.

---

## TEST 1 — Web server responds

**Open in browser:** `http://your-server-ip/`

✓ Pass: You see the BPQ Dashboard page load
✗ Fail: "This site can't be reached" or blank page

Fix:
```
sudo systemctl status nginx
sudo systemctl start nginx
sudo journalctl -u nginx -n 20
```

---

## TEST 2 — Run the health check

**Open in browser:** `http://your-server-ip/install-check.php`
**Password:** `bpqcheck`

✓ Pass: Score shows 80% or higher, mostly green
✗ Fail: Red items shown — each one displays the exact fix command

Work through each red item top to bottom.

---

## TEST 3 — BPQ Chat daemon is running

```bash
sudo systemctl status bpq-chat
```

✓ Pass: Shows `active (running)`
✗ Fail: Shows `failed` or `inactive`

Fix:
```bash
sudo journalctl -u bpq-chat -n 30
sudo systemctl restart bpq-chat
```

Most common cause: LinBPQ is not running or wrong password in config.php

---

## TEST 4 — APRS daemon is running

```bash
sudo systemctl status bpq-aprs
```

✓ Pass: Shows `active (running)`
✗ Fail: Shows `failed`

Fix:
```bash
sudo journalctl -u bpq-aprs -n 30
sudo systemctl restart bpq-aprs
```

---

## TEST 5 — APRS daemon is receiving packets

Wait 2 minutes after starting, then check:
```bash
cat /var/www/<dashboard>/cache/aprs/aprs-daemon.json
```

✓ Pass: Shows `"stations": 10` or more, `"connected": true`
✗ Fail: File missing or stations is 0

Fix: Check APRS-IS passcode in config.php is correct
```bash
sudo nano /var/www/<dashboard>/config.php
# Verify aprs.pass matches your passcode from magicbug.co.uk
sudo systemctl restart bpq-aprs
```

---

## TEST 6 — Open the Chat page

**Open in browser:** `http://your-server-ip/bpq-chat.html`

✓ Pass: Login modal appears
- Enter your callsign and BPQ password
- Click Connect
- Should show "Connected to BPQ Chat Server"

✗ Fail: Error on connect or "daemon not connected"

Fix: Verify LinBPQ is running with chat enabled
```bash
sudo systemctl status bpq-chat
sudo journalctl -u bpq-chat -n 30
```

---

## TEST 7 — Open the APRS Map page

**Open in browser:** `http://your-server-ip/bpq-aprs.html`

✓ Pass: Map loads centered on your location, stations appear
  - Wait up to 2 minutes for first stations to appear
  - Click Refresh button to force immediate update

✗ Fail: Map loads but no stations after 5 minutes
  Check APRS daemon: `sudo systemctl status bpq-aprs`

---

## TEST 8 — Open the BBS Messages page

**Open in browser:** `http://your-server-ip/bbs-messages.html`

✓ Pass: Page loads, click "Get Mail" to load BBS messages
✗ Fail: Error loading messages

Fix: Check BPQ telnet password in config.php matches bpq32.cfg
```bash
sudo nano /var/www/<dashboard>/config.php
```

---

## TEST 9 — Check database

```bash
sudo systemctl status mariadb
```

✓ Pass: Shows `active (running)`

Test database connection:
```bash
mysql -u dashboard_user -p  dashboard -e "SHOW TABLES;"
```
Enter the database password you set during install.
✓ Pass: Shows a list of tables

---

## TEST 10 — Check cron jobs are set

```bash
sudo crontab -l
```

✓ Pass: Shows lines containing `connect-watchdog` and `wp_manager`
✗ Fail: Empty or missing entries

Fix: Re-run the cron setup section:
```bash
sudo crontab -e
# Add these lines:
# */5 * * * * /usr/bin/python3 /var/www/<dashboard>/scripts/connect-watchdog.py >> /var/log/connect-watchdog.log 2>&1
# 0 3 * * * cd /home/linbpq && /usr/bin/python3 /var/www/<dashboard>/scripts/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1
```

---

## ALL TESTS PASSED?

1. Remove the health check file:
   `sudo rm /var/www/<dashboard>/install-check.php`

2. Bookmark your dashboard: `http://your-server-ip/`

3. Optional — set up HTTPS with a domain name:
   `sudo certbot --nginx -d your-domain.com`

---

Questions? Email YOUR_EMAIL@example.com  73 de YOURCALL
