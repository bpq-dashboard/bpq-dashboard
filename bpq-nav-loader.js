/**
 * bpq-nav-loader.js — Fetches _nav.html and injects it at the top of <body>.
 * 
 * Usage: pages add a <body data-nav-page="rf"> attribute to identify which
 * nav link should be highlighted as active, then load this script.
 * 
 * Behavior:
 *   1. On DOMContentLoaded, fetch _nav.html
 *   2. Inject it as the first child of <body>
 *   3. Highlight the active link based on data-nav-page
 *      (also lights up the Admin button if active page is in the dropdown)
 *   4. Wire hamburger toggle, Admin dropdown toggle, and the clock ticker
 * 
 * Falls back gracefully: if the fetch fails, nothing breaks — the page
 * just lacks the nav.
 */
(function() {
    'use strict';

    // Cache-busting query param — bump when _nav.html changes.
    var NAV_VERSION = '2026-11-27-3';   // 1.5.57: must equal the ?v= on every page's script tag (System Audit checks it)

    // Page keys that live inside the Admin dropdown — used to also light up
    // the Admin button when one of these pages is active.
    var ADMIN_KEYS = ['maint', 'firewall', 'logs', 'audit', 'audio', 'linbpq', 'nodedebug', 'modems'];

    // station-info.php (public-safe): the station line under the logo
    // (v1.5.100: node callsign, plus "affiliated with <network>" when the
    // sysop said so at install) and the menu keys of the optional pages
    // switched off in config.php ('pages_off', v1.5.19).
    var stationInfo = fetch('/station-info.php', { cache: 'default' })
        .then(function(r) { return r.ok ? r.json() : {}; })
        .catch(function() { return {}; });
    var pagesOff = stationInfo.then(function(j) { return (j && Array.isArray(j.pages_off)) ? j.pages_off : []; });

    function fillStation() {
        stationInfo.then(function(j) {
            var el = document.getElementById('bpqNavStation');
            if (!el || !j) return;
            var who = j.node || j.callsign || '';
            var net = (j.network || '').trim();
            el.textContent = who ? (net ? who + ' \u00b7 affiliated with ' + net : who) : (net ? 'affiliated with ' + net : '');
        });
    }

    function hideOffPages() {
        pagesOff.then(function(off) {
            off.forEach(function(key) {
                document.querySelectorAll('.bpq-nav-link[data-page="' + key + '"]').forEach(function(a) {
                    a.style.display = 'none';
                });
            });
            // v1.5.100: a group whose pages are all switched off (Radio without flrig) goes too
            document.querySelectorAll('.bpq-nav-group').forEach(function(g) {
                var links = g.querySelectorAll('.bpq-nav-link');
                var shown = Array.prototype.filter.call(links, function(a) { return a.style.display !== 'none'; });
                if (links.length && !shown.length) g.style.display = 'none';
            });
            // Opened directly while switched off: say so, keep the page usable.
            var here = document.body.getAttribute('data-nav-page');
            if (here && off.indexOf(here) !== -1 && !document.getElementById('bpqPageOffNote')) {
                var n = document.createElement('div');
                n.id = 'bpqPageOffNote';
                n.setAttribute('role', 'note');
                n.style.cssText = 'margin:8px 16px;padding:8px 12px;border-radius:6px;' +
                    'background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.5);font-size:13px;';
                n.textContent = 'This page is switched off for this station, so it is hidden from the menu ' +
                    '(config.php \'pages\' or \'radio\'). To use it, see Maintenance \u2192 Optional pages.';
                var nav = document.querySelector('.bpq-nav');
                if (nav && nav.parentNode) nav.parentNode.insertBefore(n, nav.nextSibling);
                else document.body.insertBefore(n, document.body.firstChild);
            }
        });
    }

    // v1.5.100: every page gets the meter logo as its tab icon
    if (!document.querySelector('link[rel~="icon"]')) {
        var ic = document.createElement('link');
        ic.rel = 'icon'; ic.type = 'image/svg+xml'; ic.href = '/favicon.svg';
        document.head.appendChild(ic);
    }

    // v1.5.100: the menu always spans the whole window, at its very top.
    // Many pages (the admin pages, Email, Chat …) put their content in a
    // centred column with padding, and the menu is the first thing in that
    // column: without this it got only the column's width, and on a
    // mid-size window its right side slid over the groups.
    function fitNav() {
        var nav = document.querySelector('.bpq-nav');
        if (!nav) return;
        nav.style.marginLeft = ''; nav.style.width = ''; nav.style.maxWidth = ''; nav.style.marginTop = '';
        var vw = document.documentElement.clientWidth;
        var r = nav.getBoundingClientRect();
        var left = r.left + window.scrollX;
        if (Math.abs(left) > 0.5 || Math.abs(r.width - vw) > 1) {
            nav.style.maxWidth = 'none';
            nav.style.width = vw + 'px';
            nav.style.marginLeft = (-left) + 'px';
        }
        var top = r.top + window.scrollY;
        if (top > 0.5 && top < 120) nav.style.marginTop = (-top) + 'px';
        // Does the full menu fit? It depends on the window, the browser's zoom,
        // the station line and the page (admin pages add the ⚙ button): measure.
        var inner = nav.querySelector('.bpq-nav-inner');
        var wasOpen = nav.classList.contains('narrow') && document.getElementById('bpqNavLinks') &&
                      document.getElementById('bpqNavLinks').classList.contains('open');
        nav.classList.remove('compact', 'narrow');
        var fits = function() { return inner.scrollWidth <= inner.clientWidth + 1; };
        if (vw <= 1100) nav.classList.add('narrow');
        else if (!fits()) {
            nav.classList.add('compact');
            if (!fits()) { nav.classList.remove('compact'); nav.classList.add('narrow'); }
        }
        if (!nav.classList.contains('narrow')) {     // leaving phone layout: close its drawer
            var m = document.getElementById('bpqNavLinks'), h = document.getElementById('bpqNavHamburger');
            if (m && m.classList.contains('open')) { m.classList.remove('open'); if (h) { h.textContent = '\u2630'; h.setAttribute('aria-expanded', 'false'); } }
        } else if (wasOpen) document.getElementById('bpqNavLinks').classList.add('open');
    }

    function injectNav() {
        fetch('/_nav.html?v=' + NAV_VERSION, { cache: 'force-cache' })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function(html) {
                var temp = document.createElement('div');
                temp.innerHTML = html;
                var firstChild = document.body.firstChild;
                while (temp.firstChild) {
                    document.body.insertBefore(temp.firstChild, firstChild);
                }
                wireUp();
                hideOffPages();
                fillStation();
                fitNav();
                window.addEventListener('resize', fitNav);
                // the ⚙ button, the station line and web fonts arrive a moment later
                [300, 1000, 2500].forEach(function(ms) { setTimeout(fitNav, ms); });
                if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitNav);
                stationInfo.then(function() { setTimeout(fitNav, 0); });
            })
            .catch(function(err) {
                console.warn('[BPQ Nav] Failed to load _nav.html:', err.message);
            });
    }

    function wireUp() {
        var activeKey = document.body.getAttribute('data-nav-page');

        // Install the shared theme system if the page hasn't already.
        // Pages that have their own theme code define window.toggleDarkMode,
        // so we leave them alone. Pages without get the central implementation.
        if (typeof window.toggleDarkMode !== 'function') {
            installSharedTheme();
        } else {
            // Page has its own theme code; just sync our nav button glyph
            var btn = document.getElementById('darkModeToggle');
            var cur = document.documentElement.getAttribute('data-theme') || 'dark';
            if (btn) btn.textContent = (cur === 'dark') ? '☀️' : '🌙';
        }

        // Highlight the active page, its group, or the Admin button
        if (activeKey) {
            var link = document.querySelector('.bpq-nav-link[data-page="' + activeKey + '"]');
            if (link) {
                link.classList.add('active');
                link.setAttribute('aria-current', 'page');
                var grp = link.closest('.bpq-nav-group');
                if (grp) grp.querySelector('.bpq-nav-gbtn').classList.add('active');
            }
            if (ADMIN_KEYS.indexOf(activeKey) !== -1) {
                var ab = document.getElementById('bpqNavAdminBtn');
                if (ab) ab.classList.add('active');
            }
        }

        // Drop-downs: the groups and Admin. One open at a time; click outside
        // or Escape closes. On a phone the same buttons open and close each
        // section inside the menu.
        var drops = Array.prototype.slice.call(document.querySelectorAll('.bpq-nav-group, #bpqNavAdmin'));
        function setOpen(d, open) {
            d.classList.toggle('open', open);
            var b = d.querySelector('.bpq-nav-gbtn, .bpq-nav-admin-btn');
            if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        function closeAll(except) { drops.forEach(function(d) { if (d !== except) setOpen(d, false); }); }
        drops.forEach(function(d) {
            var b = d.querySelector('.bpq-nav-gbtn, .bpq-nav-admin-btn');
            if (!b) return;
            b.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = !d.classList.contains('open');
                closeAll(d);
                setOpen(d, open);
            });
        });
        document.addEventListener('click', function(e) {
            if (!e.target.closest || !e.target.closest('.bpq-nav-group, #bpqNavAdmin')) closeAll(null);
        });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAll(null); });

        // Hamburger (phone): opens the menu with the current page's group unfolded
        var btn = document.getElementById('bpqNavHamburger');
        var menu = document.getElementById('bpqNavLinks');
        if (btn && menu) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();   // else the page-wide click handler closes what we open
                var open = menu.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                btn.setAttribute('aria-label', open ? 'Close the menu' : 'Open the menu');
                btn.textContent = open ? '\u2715' : '\u2630';
                if (open) {
                    var cur = menu.querySelector('.bpq-nav-link.active');
                    var g = cur && (cur.closest('.bpq-nav-group') || cur.closest('#bpqNavAdmin'));
                    if (g) { closeAll(g); setOpen(g, true); }
                }
            });
            // Choosing a page closes everything
            menu.querySelectorAll('a').forEach(function(a) {
                a.addEventListener('click', function() {
                    closeAll(null);
                    if (menu.classList.contains('open')) {
                        menu.classList.remove('open');
                        btn.setAttribute('aria-expanded', 'false');
                        btn.textContent = '\u2630';
                    }
                });
            });
        }

        // Clock ticker — only start once per page (idempotent)
        if (!window.__bpqNavClockStarted) {
            window.__bpqNavClockStarted = true;
            startClockTick();
        }
    }

    function startClockTick() {
        function pad(n) { return String(n).padStart(2, '0'); }
        function tick() {
            var d = new Date();
            var u = pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds()) + ' Z';
            var l = pad(d.getHours())    + ':' + pad(d.getMinutes())    + ':' + pad(d.getSeconds())    + ' L';
            var uEl = document.getElementById('navUtcClock');
            var lEl = document.getElementById('navLocalClock');
            if (uEl) uEl.textContent = u;
            if (lEl) lEl.textContent = l;
        }
        tick();
        setInterval(tick, 1000);
    }

    // Shared theme system. Mirrors what individual pages already have so
    // they all use the same storage key (bpq-dark-mode) and the same
    // data-theme attribute on <html>. Only installs if the page didn't
    // already define window.toggleDarkMode.
    function installSharedTheme() {
        var K = 'bpq-dark-mode';
        function getPref() {
            var s = localStorage.getItem(K);
            if (s === 'dark' || s === 'light') return s;
            return window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark' : 'light';
        }
        function apply(t) {
            document.documentElement.setAttribute('data-theme', t);
            // Update Chart.js defaults if Chart is available
            if (window.Chart) {
                var tc = (t === 'dark') ? '#9ca3af' : '#666';
                var gc = (t === 'dark') ? 'rgba(75,85,99,0.4)' : 'rgba(0,0,0,0.1)';
                Chart.defaults.color = tc;
                Chart.defaults.borderColor = gc;
                if (Chart.defaults.scale) Chart.defaults.scale.grid = { color: gc };
                Object.values(Chart.instances || {}).forEach(function(c) {
                    try { c.update('none'); } catch(e) {}
                });
            }
            var btn = document.getElementById('darkModeToggle');
            if (btn) btn.textContent = (t === 'dark') ? '☀️' : '🌙';
        }
        function toggle() {
            var cur = document.documentElement.getAttribute('data-theme') || 'light';
            var next = (cur === 'dark') ? 'light' : 'dark';
            localStorage.setItem(K, next);
            apply(next);
        }
        apply(getPref());
        // Respond to OS-level dark/light changes if user has no explicit pref
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',
            function(e) {
                if (!localStorage.getItem(K)) apply(e.matches ? 'dark' : 'light');
            });
        window.toggleDarkMode = toggle;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectNav);
    } else {
        injectNav();
    }
})();
