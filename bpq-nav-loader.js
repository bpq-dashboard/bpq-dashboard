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
    var NAV_VERSION = '2026-10-30-1';   // 1.5.16: must equal the ?v= on every page's script tag (System Audit checks it)

    // Page keys that live inside the Admin dropdown — used to also light up
    // the Admin button when one of these pages is active.
    var ADMIN_KEYS = ['maint', 'firewall', 'logs', 'audit'];

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

        // Highlight active link
        if (activeKey) {
            var link = document.querySelector('.bpq-nav-link[data-page="' + activeKey + '"]');
            if (link) link.classList.add('active');
            // If the active page is inside the Admin dropdown, also light up
            // the Admin button so the user knows where they are
            if (ADMIN_KEYS.indexOf(activeKey) !== -1) {
                var adminBtn = document.getElementById('bpqNavAdminBtn');
                if (adminBtn) adminBtn.classList.add('active');
            }
        }

        // Hamburger toggle (mobile menu)
        var btn = document.getElementById('bpqNavHamburger');
        var menu = document.getElementById('bpqNavLinks');
        if (btn && menu) {
            btn.addEventListener('click', function() {
                var open = menu.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                btn.textContent = open ? '\u2715' : '\u2630';
            });
        }

        // Admin dropdown toggle (desktop click-to-open)
        var adminBtn = document.getElementById('bpqNavAdminBtn');
        var adminWrap = document.getElementById('bpqNavAdmin');
        if (adminBtn && adminWrap) {
            adminBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = adminWrap.classList.toggle('open');
                adminBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            // Click anywhere outside the dropdown closes it
            document.addEventListener('click', function(e) {
                if (!adminWrap.contains(e.target)) {
                    adminWrap.classList.remove('open');
                    adminBtn.setAttribute('aria-expanded', 'false');
                }
            });
            // Escape key closes the dropdown
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && adminWrap.classList.contains('open')) {
                    adminWrap.classList.remove('open');
                    adminBtn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // Auto-close mobile drawer on link tap
        if (menu) {
            menu.querySelectorAll('.bpq-nav-link').forEach(function(a) {
                a.addEventListener('click', function() {
                    if (menu.classList.contains('open')) {
                        menu.classList.remove('open');
                        if (btn) {
                            btn.setAttribute('aria-expanded', 'false');
                            btn.textContent = '\u2630';
                        }
                    }
                    // Also close the desktop admin dropdown if open
                    if (adminWrap && adminWrap.classList.contains('open')) {
                        adminWrap.classList.remove('open');
                        if (adminBtn) adminBtn.setAttribute('aria-expanded', 'false');
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
