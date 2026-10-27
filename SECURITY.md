# Security

BPQ Dashboard runs on the same machine as your LinBPQ node and can act as
your station: it reads and sends BBS mail, posts bulletins, sends APRS
messages, talks on BPQ Chat and logs in to the node as sysop. Anyone who can
use those pages can transmit under your callsign. Please treat its security
as part of running the station.

## Reporting a problem

Please **don't open a public issue** for a security problem.

Report it privately on GitHub: open the repository's **Security** tab and
choose **Report a vulnerability**. Say which version you run (the badge on
any page, or `CHANGELOG.md`), whether you use nginx or Apache, and how to
reproduce it. Leave real passwords, callsigns' passcodes and server
addresses out of the report.

You should get a reply within a week. Fixes go into the next release and are
listed under **Security** in `CHANGELOG.md`.

Only the latest release gets security fixes. If you run an older version,
upgrade first (re-run the new version's `install.sh` and answer **Y** to the
backup question; your database and config are kept).

## Running it safely

**Reach it over HTTPS, with the admin login, and never open it to the
internet without both.** In detail:

1. **Keep the admin login.** `install.sh` sets up a web-server login (the
   browser's login box) and puts everything that acts as the station behind
   it: the Email Client, Chat, NWS posting, the admin pages, Install Check,
   YAPP downloads, and the "save" and "send" actions on RF Connections and
   APRS. Viewing maps, logs, traffic and weather stays public and read-only.
   The table in `USER-GUIDE.md` ("Which pages ask for a login") lists every
   page.
2. **The PHP checks the login too.** Those endpoints refuse to act
   (403, `login_required`) unless the web server tells PHP it checked the
   login (`fastcgi_param BPQ_WEB_LOGIN $remote_user;` on nginx, `REMOTE_USER`
   on Apache). So a hand-made or out-of-date web-server config fails closed
   instead of leaving them open. `'require_web_login' => false` in
   `config.php` turns this off; only use it if the dashboard is protected some
   other way (VPN only, or an authenticating proxy).
3. **Use HTTPS** whenever the dashboard is reachable from outside your LAN,
   so the login and your mail aren't sent in clear text
   (`sudo certbot --nginx -d your.domain`).
4. **Check the firewall, IPv6 included.** The installer doesn't change your
   firewall. Many home connections now give the server a public IPv6 address
   that bypasses the router's port forwarding, and LinBPQ listens on all
   addresses by default. Make sure only 80/443 (and anything you mean to
   share, such as forwarding ports) are open, for both `iptables` and
   `ip6tables`:
   ```bash
   sudo iptables -S INPUT | head -3
   sudo ip6tables -S INPUT | head -3      # policy should be DROP, not ACCEPT
   sudo ss -tlnp | grep -E 'linbpq|:80 |:443 '
   ```
5. **Run Install Check after every install or upgrade**
   (`https://your.domain/install-check.php`, admin login). The rows
   *Admin folder behind the login*, *Login reaches PHP* and *Station-acting
   pages behind the login* must be green.
6. **Keep secrets out of the web root.** Passwords live in `config.php`
   (and, on older installs, `db-config.php`, `bbs-config.php`,
   `nws-config.php`). The web-server config refuses to serve them, and
   `fix-permissions.sh` sets them to 640. Never leave backups (`*.bak`,
   `*.sql`, copies of `bpq32.cfg`) inside the web root: put them in
   `/root/` or another folder the web server doesn't serve.
7. **Leave other web sites out unless you need them.** Since 2026-10-20 the
   dashboard answers only its own pages (no `Access-Control-Allow-Origin:
   *`), so another web site can't use your QRZ lookups, proxies or BBS
   logins from its visitors' browsers. If a site of yours really has to call
   the dashboard, name it in `config.php`:
   `'cors' => ['allow_all' => false, 'allowed_origins' => ['https://club.example.org']]`.
   To make the Email Client read-only (no Send, Forward or Kill), set
   `'features' => ['bbs_write' => false, …]`.
8. **Use different passwords** for the admin login, the BBS sysop account,
   the database and anything else on the network (Wi-Fi, router). LinBPQ's
   own Telnet logs record login lines; keep them out of the web root.

## What's in scope

The dashboard's own pages, PHP, scripts, daemons, the installer and the
web-server templates in this repository. Problems in LinBPQ, VARA, flrig,
nginx, Apache, PHP or MariaDB themselves should go to those projects, but
tell us if the dashboard's defaults make them worse.
