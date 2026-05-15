# Installation Guide

This guide walks you through installing the BPQ Dashboard on a Linux
server. **No prior Linux experience required** — every step is
explained.

## The 60-second version

```bash
sudo bash install.sh
```

That's it. The installer asks you a few questions and does everything
else. If you're comfortable on Linux and just want to know where things
go, skip to the **What the installer does** section below.

## Before you start

You need:

- A **Linux server** running Debian, Ubuntu, or Raspberry Pi OS
- **root access** (sudo) on that server
- A **running BPQ32 / LinBPQ node** (or one you're about to set up)
- About 15 minutes

You should have ready, on a piece of paper or open in another window:

| Item | Where to find it |
|---|---|
| Your callsign | Your FCC license |
| Your BPQ node callsign with SSID (e.g. W1AW-4) | `bpq32.cfg` `APPLICATION=` line |
| Your station latitude/longitude | Google Maps → right-click your QTH → "Copy coordinates" |
| Your BPQ telnet port | `bpq32.cfg` TELNET section, `TCPPORT=` (usually 8010) |
| Your BPQ sysop username and password | `bpq32.cfg` `USER=` line — first and second fields |
| Path to your LinBPQ directory | The folder where you run LinBPQ from |

## Step-by-step

### 1. Download and unzip the archive

If you got this as a zip file, copy it to your Linux server and unzip
it. If you got it via git clone, you already have it.

```bash
unzip BPQ-Dashboard-v1.5.8.zip
cd BPQ-Dashboard-v1.5.8
```

If instead you want to extract the contents into an existing working
directory (for example, a directory you've prepared for `git` to push
the files publicly from), unzip into a temporary location and then move
the files up:

```bash
unzip BPQ-Dashboard-v1.5.8.zip
mv BPQ-Dashboard-v1.5.8/* .
rmdir BPQ-Dashboard-v1.5.8
```

The archive contains no top-level dotfiles, so a simple `*` glob is
enough. Don't bother with `.[!.]*` — it just produces an error.

### 2. Run the installer

```bash
sudo bash install.sh
```

You'll see a welcome banner, then it will ask the questions from the
list above. **At every prompt, you can press ENTER to accept the
default shown in `[brackets]`.**

### 3. Open the dashboard

When the installer finishes, it prints the URL. Open it in a browser
on the same network:

```
http://<your-server-ip>/
```

If you don't know your server's IP, run `hostname -I` on the server.

## What the installer does

For experienced Linux users, here's what the installer does without
asking:

1. Updates the apt package index
2. Detects nginx, Apache, or installs nginx if neither is present
3. Installs PHP-FPM + a standard set of extensions
4. Installs Python 3 + `requests`, `pexpect`, `pyserial`
5. Asks before installing MariaDB (optional)
6. Copies the dashboard to `/var/www/bpq-dashboard/`
7. Writes `config.php` from your answers (mode 0640, owned by web user)
8. Writes a web-server site config and reloads nginx/Apache
9. Symlinks `/var/www/bpq-dashboard/logs/` to your LinBPQ directory
10. Installs systemd unit files for the helper daemons (not started)
11. Tightens permissions

Total disk footprint: about 30 MB.

## After the install

### Telling the dashboard about LinBPQ

If LinBPQ wasn't running when you installed, the dashboard won't have
any log data to show. After you start LinBPQ, the log viewer and BBS
pages will populate as logs accumulate. The first 5 minutes after
LinBPQ start are usually empty; give it time.

### Enabling helper daemons

The installer installs systemd unit files for the daemons but doesn't
start them. Enable each one you want:

```bash
sudo systemctl enable --now bpq-telnet     # web telnet terminal
sudo systemctl enable --now bpq-chat       # web chat client
sudo systemctl enable --now bpq-aprs       # APRS feed
sudo systemctl enable --now bpq-vara       # VARA session logger
sudo systemctl enable --now vara-logger    # VARA log capture
```

You can stop a daemon any time with `sudo systemctl stop <name>`.

### Changing settings later

Edit `/var/www/bpq-dashboard/config.php`. No restart needed.

### Making the dashboard reachable from the internet

By default the dashboard listens on port 80, accessible to anything on
your LAN. **Do NOT expose it directly to the internet** without:

- A reverse proxy with HTTPS (e.g. nginx with Let's Encrypt)
- Authentication on at least the BBS write and maintenance pages
- Switching `'security_mode' => 'public'` in config.php
- Enabling `'rate_limit' => ['enabled' => true]` in config.php

If you need internet exposure, the simplest path is Tailscale or
ZeroTier — gives you remote access without opening any port to the
public internet.

## Troubleshooting

See `TROUBLESHOOTING.md` for the most common problems and fixes.

If the installer itself failed partway through, the log at
`/tmp/bpq-dashboard-install-*.log` has the full record of what
succeeded and what didn't.

## Uninstalling

To remove the dashboard:

```bash
# Stop and disable any daemons that were started
for s in bpq-telnet bpq-chat bpq-aprs bpq-vara vara-logger; do
    sudo systemctl disable --now "$s" 2>/dev/null
done

# Remove systemd units
sudo rm -f /etc/systemd/system/bpq-*.service /etc/systemd/system/vara-logger.service
sudo systemctl daemon-reload

# Remove web server config
sudo rm -f /etc/nginx/sites-enabled/bpq-dashboard \
           /etc/nginx/sites-available/bpq-dashboard \
           /etc/apache2/sites-enabled/bpq-dashboard.conf \
           /etc/apache2/sites-available/bpq-dashboard.conf

# Remove the dashboard files (THIS DELETES YOUR CONFIG TOO)
sudo rm -rf /var/www/bpq-dashboard

# Reload the web server
sudo systemctl reload nginx 2>/dev/null || sudo systemctl reload apache2 2>/dev/null

# Optionally drop the database
sudo mysql -u root -e "DROP DATABASE bpqdash; DROP USER 'bpqdash'@'localhost';"
```

The installer doesn't install anything outside `/var/www/bpq-dashboard/`,
`/etc/systemd/system/`, and the nginx or Apache sites directories — so
removing those is enough.
