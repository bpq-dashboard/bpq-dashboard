# BPQ Dashboard v1.5.6 — Post-Installation Testing Guide

Work through these tests after running install.sh.
Each test tells you what to look for and what to do if it fails.

---

## TEST 1 — Web server responds

**Open in browser:** `http://your-server-ip/`

✓ Pass: BPQ Dashboard page loads
✗ Fail: "This site can't be reached" or blank page

```bash
sudo systemctl status nginx
sudo systemctl start nginx
sudo journalctl -u nginx -n 20
```

---

## TEST 2 — Run the health check

**Open in browser:** `http://your-server-ip/install-check.php`
**Password:** `bpqcheck`

✓ Pass: Score 80% or higher, mostly green
✗ Fail: Red items shown — each displays the exact fix command

Work through red items top to bottom. Refresh to re-run checks.

---

## TEST 3 — BPQ Chat daemon running

```bash
sudo systemctl status bpq-chat
```

✓ Pass: `active (running)`
✗ Fail: `failed` or `inactive`

```bash
sudo journalctl -u bpq-chat -n 30
sudo systemctl restart bpq-chat
```

Most common cause: LinBPQ not running or wrong password in config.php

---

## TEST 4 — APRS daemon running and receiving

```bash
sudo systemctl status bpq-aprs
```

✓ Pass: `active (running)`

Check it is receiving packets (wait 2 minutes):
```bash
cat /var/www/html/bpq/cache/aprs/aprs-daemon.json
```

✓ Pass: `"connected": true` and `"packets"` > 0
✗ Fail: stations = 0 after 5 minutes

Check APRS-IS passcode in config.php:
```bash
sudo journalctl -u bpq-aprs -n 20 | grep -i "verif\|login"
sudo nano /var/www/html/bpq/config.php
sudo systemctl restart bpq-aprs
```

---

## TEST 5 — Server storage working

**Open BBS page:** `http://your-server-ip/bbs-messages.html`

Test the server storage endpoint:
```bash
sudo -u www-data php -r "
\$_SERVER['REQUEST_METHOD']='GET';
\$_SERVER['PHP_SELF']='/message-storage.php';
\$_GET['action']='stats';
chdir('/var/www/html/bpq');
include '/var/www/html/bpq/message-storage.php';
" 2>&1
```

✓ Pass: Shows `{"success":true,"stats":{...}}`
✗ Fail: PHP errors shown

Fix:
```bash
sudo mkdir -p /var/www/html/bpq/data/messages
sudo chown -R www-data:www-data /var/www/html/bpq/data/messages
sudo chmod 775 /var/www/html/bpq/data/messages
```

In the BBS page click **💻 Browser storage** chip to switch to
**☁️ Server storage** — rules and read state will now persist correctly.

---

## TEST 6 — BBS Chat page connects

**Open:** `http://your-server-ip/bpq-chat.html`

✓ Pass: Login modal appears, enter callsign + BPQ password, connects
✗ Fail: Connection error

```bash
sudo systemctl status bpq-chat
sudo journalctl -u bpq-chat -n 30
```

---

## TEST 7 — APRS Map shows stations

**Open:** `http://your-server-ip/bpq-aprs.html`

✓ Pass: Map loads, stations appear within 2 minutes
✗ Fail: No stations after 5 minutes

```bash
sudo systemctl status bpq-aprs
sudo journalctl -u bpq-aprs -n 20
```

---

## TEST 8 — BBS Messages loads

**Open:** `http://your-server-ip/bbs-messages.html`

✓ Pass: Page loads, click Get Mail, messages appear
✗ Fail: Error loading messages

```bash
# Check BPQ telnet password matches bpq32.cfg
sudo nano /var/www/html/bpq/config.php
```

---

## TEST 9 — Database connected

```bash
sudo systemctl status mariadb
mysql -u bpqdash_user -p bpqdash -e "SHOW TABLES;"
```

✓ Pass: Lists tables including sessions, hubs, prop_decisions

---

## TEST 10 — Cron jobs set (root only)

```bash
sudo crontab -l
```

✓ Pass: Shows connect-watchdog and wp_manager entries
✗ Fail: Missing

```bash
sudo crontab -e
# Add:
# */5 * * * * /usr/bin/python3 /var/www/html/bpq/scripts/connect-watchdog.py >> /var/log/connect-watchdog.log 2>&1
# 0 3 * * * cd /home/linbpq && /usr/bin/python3 /var/www/html/bpq/scripts/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1
```

---

## ALL TESTS PASSED?

1. Switch BBS to server storage — click **💻 Browser storage** chip
2. Enter your filing rules in BBS page (they now save to server)
3. Remove health check: `sudo rm /var/www/html/bpq/install-check.php`
4. Optional HTTPS: `sudo certbot --nginx -d your-domain.com`
5. Bookmark: `http://your-server-ip/`

---

Questions? Email sysop@example.com  73 de YOURCALL
