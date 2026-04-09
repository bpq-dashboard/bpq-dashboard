═══════════════════════════════════════════════════════════════════
  BPQ Dashboard v1.5.5
  YOURCALL — BPQ Network — Your City, GA
  https://www.bpqdash.net     sysop@example.com
═══════════════════════════════════════════════════════════════════

WHAT IS BPQ DASHBOARD?
───────────────────────
BPQ Dashboard is a web-based monitoring and control interface for
your LinBPQ / BPQ32 packet radio node. It gives you a real-time
view of RF connections, BBS messages, live APRS map, chat server,
NWS weather, system logs and much more — all in a web browser.

BEFORE YOU START — WHAT YOU NEED
──────────────────────────────────
  ✓ A Linux server already running LinBPQ
    (Debian, Ubuntu, or Raspberry Pi OS recommended)
  ✓ LinBPQ configured and running (BBS and Chat working)
  ✓ The server must have internet access
  ✓ SSH access to the server
  ✓ Your station callsign and BPQ telnet password

IF YOU ARE ON WINDOWS — FREE TOOLS YOU WILL NEED
──────────────────────────────────────────────────
  SSH (to log in to Linux):
    PuTTY — https://www.putty.org
    Enter your server IP, port 22, click Open

  File transfer (to copy the zip to Linux):
    WinSCP  — https://winscp.net
    FileZilla — https://filezilla-project.org
    Connect using SFTP, your server IP, port 22

HOW TO FIND YOUR SERVER IP ADDRESS
────────────────────────────────────
  Log in to your Linux server and type:
    hostname -I
  The first number shown (e.g. 192.168.1.100) is your IP.

WHERE TO FIND YOUR BPQ TELNET PASSWORD
────────────────────────────────────────
  Open bpq32.cfg and look for a line like:
    USER=YOURCALL,YOURPASSWORD,YOURCALL-4,NODE,SYSOP
  The password is between the 1st and 2nd comma.
  In this example the password is: YOURPASSWORD

WHERE IS MY LINBPQ DIRECTORY?
───────────────────────────────
  Common locations:
    /home/linbpq/
    /home/pi/linbpq/
    /home/yourname/linbpq/
  To find it automatically, type:
    find /home -name "bpq32.cfg" 2>/dev/null

HOW TO GET YOUR APRS-IS PASSCODE
──────────────────────────────────
  Your APRS-IS passcode is a number tied to your callsign.
  Get it free at: https://apps.magicbug.co.uk/passcode/
  Enter your callsign (no SSID) — it shows your passcode.
  Example: callsign YOURCALL gives passcode 15769

INSTALLATION — STEP BY STEP
─────────────────────────────

  STEP 1 — Copy zip to your Linux server
    Windows: Use WinSCP or FileZilla to copy
      BPQ-Dashboard-v1.5.5-deploy.zip
      to your home folder on the server

    Linux/Mac terminal:
      scp BPQ-Dashboard-v1.5.5-deploy.zip user@server-ip:~/

  STEP 2 — Log in to your server
    ssh your-username@your-server-ip

  STEP 3 — Install unzip (if needed)
    sudo apt install unzip

  STEP 4 — Unzip the archive
    unzip BPQ-Dashboard-v1.5.5-deploy.zip

  STEP 5 — Enter the folder
    cd BPQ-Dashboard-deploy

  STEP 6 — Run the installer
    sudo bash install.sh

    Have ready before you start:
      • Your callsign            (e.g. YOURCALL)
      • Node callsign with SSID  (e.g. YOURCALL-4)
      • BPQ telnet password      (from bpq32.cfg USER= line)
      • Server hostname or IP    (e.g. 192.168.1.100)
      • LinBPQ directory path    (e.g. /home/linbpq)
      • APRS-IS passcode         (from magicbug.co.uk link above)
      • Your email address

    The installer takes 5-10 minutes.
    Answer each question and press ENTER.
    Press ENTER alone to accept the default [shown in brackets].

  STEP 7 — Open your browser
    Go to: http://your-server-ip/
    You should see the BPQ Dashboard.

  STEP 8 — Run the health check
    Go to: http://your-server-ip/install-check.php
    Password: bpqcheck
    Green items = working correctly
    Red items = need attention (fix command shown for each)

  STEP 9 — See POST-INSTALL-TEST.md for full testing steps

  STEP 10 — Remove health check when done
    sudo rm /var/www/html/bpq/install-check.php

  STEP 11 — Optional: Set up HTTPS
    If you have a domain name:
      sudo certbot --nginx -d your-domain.com

USEFUL COMMANDS AFTER INSTALLATION
────────────────────────────────────
  Check services running:
    sudo systemctl status bpq-chat
    sudo systemctl status bpq-aprs

  View live logs:
    sudo journalctl -u bpq-chat -f
    sudo journalctl -u bpq-aprs -f

  Restart a service:
    sudo systemctl restart bpq-chat
    sudo systemctl restart bpq-aprs

  Edit your settings:
    sudo nano /var/www/html/bpq/config.php

DASHBOARD PAGES
────────────────
  RF Connections  — Who is connected to your node right now
  Traffic         — Weekly/monthly statistics and charts
  System Logs     — BPQ logs, RMS/CMS connection history
  Connect Log     — Full history of all connections
  Messages        — BBS email forwarding monitor
  BBS             — Full BBS message browser (Thunderbird style)
  Weather         — NWS weather alerts and forecasts
  Chat            — Live BPQ chat with room tabs and Who panel
  APRS            — Live map with station tracking and WX data

FILES IN THIS PACKAGE
──────────────────────
  install.sh            ← RUN THIS FIRST
  install-check.php     ← Run after install to verify
  README.txt            ← This file
  POST-INSTALL-TEST.md  ← Step-by-step testing guide
  TROUBLESHOOTING.md    ← Common problems and fixes
  *.html / *.php        ← Dashboard pages and APIs
  scripts/              ← Python daemon scripts
  data/                 ← Configuration files

SUPPORT
────────
  Sysop  : Tony YOURCALL
  Email  : sysop@example.com
  Web    : https://www.bpqdash.net
  GitHub : https://github.com/bpq-dashboard/bpq-dashboard

═══════════════════════════════════════════════════════════════════
