═══════════════════════════════════════════════════════════════════
  BPQ Dashboard v1.5.5
  K1AJD — TPRFN Network — Hephzibah, GA
  https://www.tprfn.net
═══════════════════════════════════════════════════════════════════

WHAT IS BPQ DASHBOARD?
───────────────────────
A web-based dashboard for LinBPQ/BPQ32 packet radio nodes. Provides
real-time monitoring of RF connections, BBS messages, chat, APRS
live map, weather, system logs and more.

REQUIREMENTS
─────────────
• A Linux server (Debian, Ubuntu, or Raspberry Pi OS recommended)
• LinBPQ already installed and running
• Internet connection (for installation and APRS-IS)
• A web browser to access the dashboard

INSTALLATION — STEP BY STEP
─────────────────────────────

STEP 1 — Copy the zip file to your Linux server
  If you are on Windows, use WinSCP or FileZilla to copy
  BPQ-Dashboard-v1.5.5.zip to your Linux home folder.

  Or from Linux command line:
    scp BPQ-Dashboard-v1.5.5.zip tony@your-server:~/

STEP 2 — Log in to your Linux server
  Open a terminal (or SSH session):
    ssh tony@your-server-ip

STEP 3 — Unzip the archive
  unzip BPQ-Dashboard-v1.5.5.zip

STEP 4 — Enter the directory
  cd BPQ-Dashboard-v1.5.5

STEP 5 — Run the installer
  sudo bash install.sh

  The installer will ask you several questions (callsign, password,
  hostname etc.) then install and configure everything automatically.
  It takes about 5-10 minutes.

STEP 6 — Open your browser
  Go to: http://your-server-ip/
  or:    http://your-hostname/

STEP 7 — Run the health check (optional)
  Go to: http://your-server-ip/install-check.php?pass=bpqcheck
  This shows what is working and what needs attention.

STEP 8 — Remove the health check file when done
  sudo rm /var/www/tprfn/install-check.php

STEP 9 — Set up SSL (HTTPS) — optional but recommended
  sudo certbot --nginx -d your-domain.com

AFTER INSTALLATION
───────────────────
Check daemons are running:
  sudo systemctl status bpq-chat
  sudo systemctl status bpq-aprs

View live daemon logs:
  sudo journalctl -u bpq-chat -f
  sudo journalctl -u bpq-aprs -f

Edit your configuration:
  sudo nano /var/www/tprfn/config.php

WHAT THE INSTALLER SETS UP
────────────────────────────
  ✓ Nginx web server
  ✓ PHP 8.x with required extensions
  ✓ MariaDB database
  ✓ All dashboard HTML and PHP files
  ✓ Python scripts and daemons
  ✓ BPQ Chat daemon (systemd service, auto-starts at boot)
  ✓ APRS daemon (systemd service, auto-starts at boot)
  ✓ VARA callsign validator (systemd service)
  ✓ Scheduled tasks (cron jobs)
  ✓ Log files
  ✓ Firewall rules
  ✓ Correct file permissions throughout
  ✓ config.php generated from your answers

FILES INCLUDED
───────────────
  install.sh              ← RUN THIS to install
  install-check.php       ← Run after install to check everything
  *.html                  Dashboard pages
  *.php                   API files
  scripts/                Python daemon scripts
  data/                   Configuration data files
  bpq-chat.service        Systemd service (installed automatically)
  bpq-aprs.service        Systemd service (installed automatically)
  vara-validator.service  Systemd service (installed automatically)
  tprfn.conf              Nginx config (reference only)
  CHANGELOG.md            Version history

SUPPORT
────────
  Sysop : Tony K1AJD
  Email : tony@k1ajd.net
  Web   : https://www.tprfn.net

═══════════════════════════════════════════════════════════════════
