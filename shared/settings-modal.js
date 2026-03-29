/**
 * BPQ Dashboard Settings Modal
 * Version: 1.0.0
 *
 * Tabbed settings UI loaded on every dashboard page.
 * Injects a ⚙ gear icon into the nav bar, renders a full-screen modal
 * with 5 tabs: Station Info, Forwarding Partners, Prop Scheduler,
 * Storm Monitor, Paths & System.
 *
 * Usage: <script src="shared/settings-modal.js"></script>
 * Requires: settings-api.php at the same web root.
 */

(function () {
    'use strict';

    // =========================================================================
    // STATE
    // =========================================================================
    let _settings   = null;   // live copy loaded from API
    let _authed     = false;  // whether BBS password was verified this session
    let _dirtyTabs  = new Set();
    let _editingPartnerIdx = null; // index into _settings.partners or null for new

    const API = 'settings-api.php';

    // =========================================================================
    // INIT — inject gear icon after DOM ready
    // =========================================================================
    function init() {
        injectStyles();
        injectGearButton();
        loadCallsign();  // Only loads callsign (public) — full settings require auth
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // =========================================================================
    // GEAR BUTTON — appended to nav dark-mode toggle area
    // =========================================================================
    function injectGearButton() {
        // Try to find the clock/button row in nav
        const clocks = document.getElementById('navClocks');
        if (!clocks) return;

        const btn = document.createElement('button');
        btn.id        = 'settingsGearBtn';
        btn.title     = 'Dashboard Settings';
        btn.innerHTML = '⚙';
        btn.style.cssText = [
            'background:none', 'border:none', 'cursor:pointer',
            'font-size:1.2rem', 'padding:4px 6px', 'border-radius:6px',
            'color:var(--gear-color,#6b7280)',
            'transition:transform .2s,color .2s',
            'line-height:1',
        ].join(';');
        btn.addEventListener('mouseenter', () => { btn.style.transform = 'rotate(45deg)'; btn.style.color = '#7c3aed'; });
        btn.addEventListener('mouseleave', () => { btn.style.transform = '';              btn.style.color = '';       });
        btn.addEventListener('click', openModal);
        clocks.appendChild(btn);
    }

    // =========================================================================
    // LOAD SETTINGS
    // Full settings require auth. On page load we only fetch the callsign
    // (public endpoint) for nav bar display. Full settings load on modal open
    // after password is verified.
    // =========================================================================
    async function loadCallsign() {
        try {
            const res = await apiCall('get_callsign');
            if (res.ok && res.data?.callsign) {
                // Update any nav callsign element if present
                const el = document.getElementById('navCallsign');
                if (el) el.textContent = res.data.callsign;
            }
        } catch (e) { /* silent — callsign display is optional */ }
    }

    async function loadSettings() {
        try {
            const res = await apiCall('get');
            if (res.ok) {
                _settings = res.data;
            } else if (res.error && res.error.includes('Authentication')) {
                _authed = false;
            }
        } catch (e) {
            console.warn('[Settings] Failed to load settings:', e);
        }
    }

    // =========================================================================
    // MODAL OPEN / CLOSE
    // =========================================================================
    function openModal() {
        if (!document.getElementById('stnSettingsOverlay')) {
            buildModal();
        }
        document.getElementById('stnSettingsOverlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';

        if (_authed) {
            // Already authenticated this session — load and render
            loadSettings().then(() => renderAllTabs());
        } else {
            // Show locked state — password required before settings are loaded
            showLockedState();
            showAuthBanner();
        }
    }

    function closeModal() {
        const overlay = document.getElementById('stnSettingsOverlay');
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';
        _dirtyTabs.clear();
    }

    // =========================================================================
    // BUILD MODAL DOM
    // =========================================================================
    function buildModal() {
        const overlay = el('div', { id: 'stnSettingsOverlay' });
        overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });

        const modal = el('div', { id: 'stnSettingsModal' });

        // Header
        const hdr = el('div', { id: 'stnSettingsHeader' });
        hdr.innerHTML = '<span style="font-size:1.4rem">⚙</span> <span>Dashboard Settings</span>';
        const closeBtn = el('button', { id: 'stnSettingsClose' });
        closeBtn.innerHTML = '✕';
        closeBtn.addEventListener('click', closeModal);
        hdr.appendChild(closeBtn);
        modal.appendChild(hdr);

        // Auth banner (hidden by default)
        const authBanner = el('div', { id: 'stnAuthBanner' });
        authBanner.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span>🔒 BBS password required to view and save settings:</span>
                <input type="password" id="stnAuthPass" placeholder="BBS password"
                    style="padding:4px 8px;border:1px solid #ccc;border-radius:6px;font-size:.9rem;width:160px;">
                <button id="stnAuthBtn" class="stn-btn stn-btn-primary" style="padding:5px 14px;">Unlock</button>
                <span id="stnAuthMsg" style="color:#ef4444;font-size:.85rem;"></span>
            </div>`;
        authBanner.style.display = 'none';
        modal.appendChild(authBanner);
        document.getElementById && setTimeout(() => {
            const ab = document.getElementById('stnAuthBtn');
            if (ab) ab.addEventListener('click', attemptAuth);
            const ap = document.getElementById('stnAuthPass');
            if (ap) ap.addEventListener('keydown', e => { if (e.key === 'Enter') attemptAuth(); });
        }, 100);

        // Tab bar
        const tabs = ['Station', 'Partners', 'Prop Sched', 'Storm Mon', 'Paths'];
        const tabBar = el('div', { id: 'stnTabBar' });
        tabs.forEach((t, i) => {
            const btn = el('button', { class: 'stn-tab' + (i === 0 ? ' stn-tab-active' : ''), 'data-tab': i });
            btn.textContent = t;
            btn.addEventListener('click', () => switchTab(i));
            tabBar.appendChild(btn);
        });
        modal.appendChild(tabBar);

        // Tab panels
        const panels = el('div', { id: 'stnPanels' });
        const panelIds = ['stnTabStation', 'stnTabPartners', 'stnTabPropSched', 'stnTabStorm', 'stnTabPaths'];
        panelIds.forEach((pid, i) => {
            const p = el('div', { id: pid, class: 'stn-panel' + (i === 0 ? ' stn-panel-active' : '') });
            p.innerHTML = '<div style="color:#9ca3af;padding:20px">Loading…</div>';
            panels.appendChild(p);
        });
        modal.appendChild(panels);

        // Footer
        const footer = el('div', { id: 'stnFooter' });
        footer.innerHTML = `
            <span id="stnSaveMsg" style="font-size:.85rem;color:#10b981;"></span>
            <div style="display:flex;gap:10px;">
                <button class="stn-btn" onclick="window._stnModal && window._stnModal.cancel()">Cancel</button>
                <button class="stn-btn stn-btn-primary" onclick="window._stnModal && window._stnModal.save()">💾 Save All</button>
            </div>`;
        modal.appendChild(footer);

        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        // Expose interface
        window._stnModal = { cancel: closeModal, save: saveAll };

        // Auth banner listener (deferred)
        setTimeout(() => {
            const ab = document.getElementById('stnAuthBtn');
            if (ab && !ab._bound) { ab.addEventListener('click', attemptAuth); ab._bound = true; }
            const ap = document.getElementById('stnAuthPass');
            if (ap && !ap._bound) { ap.addEventListener('keydown', e => { if (e.key==='Enter') attemptAuth(); }); ap._bound = true; }
        }, 200);
    }

    // =========================================================================
    // TAB SWITCHING
    // =========================================================================
    function switchTab(i) {
        document.querySelectorAll('.stn-tab').forEach((t, j) => {
            t.classList.toggle('stn-tab-active', j === i);
        });
        document.querySelectorAll('.stn-panel').forEach((p, j) => {
            p.classList.toggle('stn-panel-active', j === i);
        });
    }

    // =========================================================================
    // RENDER ALL TABS
    // =========================================================================
    function renderAllTabs() {
        if (!_settings) return;
        renderStation();
        renderPartners();
        renderPropSched();
        renderStorm();
        renderPaths();
    }

    // =========================================================================
    // TAB 1 — STATION INFO
    // =========================================================================
    function renderStation() {
        const s = _settings.station ?? {};
        const b = _settings.bbs     ?? {};
        const panel = document.getElementById('stnTabStation');
        if (!panel) return;

        panel.innerHTML = `
        <div class="stn-section">
            <div class="stn-section-title">🏠 Home Station</div>
            <div class="stn-grid2">
                ${field('Callsign',   'stn_callsign', s.callsign ?? '',  'text',   'e.g. K1AJD')}
                ${field('Grid Square','stn_grid',     s.grid     ?? '',  'text',   '6-char, e.g. EM83al')}
                ${field('Latitude',   'stn_lat',      s.lat      ?? 0,   'number', '33.47')}
                ${field('Longitude',  'stn_lon',      s.lon      ?? 0,   'number', '-82.01')}
            </div>
            <div style="margin-top:12px;">
                <label class="stn-label">Notes / Description</label>
                <textarea id="stn_notes" class="stn-input" rows="2" placeholder="e.g. TPRFN Hub, Augusta GA">${escHtml(s.notes ?? '')}</textarea>
            </div>
        </div>
        <div class="stn-section">
            <div class="stn-section-title">📡 BBS Connection</div>
            <div class="stn-grid2">
                ${field('BBS Host',  'stn_bbs_host',    b.host    ?? 'localhost', 'text',   'localhost or IP')}
                ${field('BBS Port',  'stn_bbs_port',    b.port    ?? 8010,        'number', '8010')}
                ${field('Username',  'stn_bbs_user',    b.user    ?? '',          'text',   'Callsign or SYSOP')}
                ${field('Password',  'stn_bbs_pass',    b.pass    ?? '',          'password', '(stored encrypted)')}
                ${field('BBS Alias', 'stn_bbs_alias',   b.alias   ?? 'bbs',       'text',   'bbs')}
                ${field('Timeout',   'stn_bbs_timeout', b.timeout ?? 30,          'number', '30')}
            </div>
        </div>`;
    }

    function collectStation() {
        return {
            station: {
                callsign: val('stn_callsign'),
                grid:     val('stn_grid'),
                lat:      parseFloat(val('stn_lat'))  || 0,
                lon:      parseFloat(val('stn_lon'))  || 0,
                notes:    val('stn_notes'),
            },
            bbs: {
                host:    val('stn_bbs_host'),
                port:    parseInt(val('stn_bbs_port')) || 8010,
                user:    val('stn_bbs_user'),
                pass:    val('stn_bbs_pass'),
                alias:   val('stn_bbs_alias'),
                timeout: parseInt(val('stn_bbs_timeout')) || 30,
            }
        };
    }

    // =========================================================================
    // TAB 2 — FORWARDING PARTNERS
    // =========================================================================
    function renderPartners() {
        const partners = _settings.partners ?? [];
        const panel    = document.getElementById('stnTabPartners');
        if (!panel) return;

        let rows = partners.map((p, i) => {
            const bands = Object.keys(p.bands ?? {}).join(', ') || '—';
            return `<tr>
                <td>${escHtml(p.call)}${p.connect_call !== p.call ? '<span style="color:#9ca3af;font-size:.8rem"> (${escHtml(p.connect_call)})</span>':''}</td>
                <td>${escHtml(p.name ?? '')}</td>
                <td>${escHtml(p.location ?? '')}</td>
                <td>${escHtml(bands)}</td>
                <td>${p.fixed_schedule ? '🔒 Fixed' : '🔄 Auto'}</td>
                <td style="white-space:nowrap">
                    <button class="stn-btn stn-btn-xs" onclick="window._stnModal.editPartner(${i})">✏ Edit</button>
                    <button class="stn-btn stn-btn-xs stn-btn-danger" onclick="window._stnModal.removePartner(${i})">✕</button>
                </td>
            </tr>`;
        }).join('');

        panel.innerHTML = `
        <div class="stn-section">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <div class="stn-section-title" style="margin:0">📶 Forwarding Partners (${partners.length})</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="stn-btn stn-btn-primary" onclick="window._stnModal.editPartner(null)">＋ Add Partner</button>
                    <button class="stn-btn" onclick="window._stnModal.importLinmail()">📥 Import from linmail.cfg</button>
                </div>
            </div>
            ${partners.length ? `
            <div style="overflow-x:auto;">
                <table class="stn-table">
                    <thead><tr>
                        <th>Callsign</th><th>Name</th><th>Location</th><th>Bands</th><th>Schedule</th><th>Actions</th>
                    </tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>` : '<div style="color:#9ca3af;padding:20px;text-align:center">No partners configured. Add one or import from linmail.cfg.</div>'}
        </div>
        <div id="stnPartnerEditor" style="display:none"></div>`;

        window._stnModal.editPartner   = editPartner;
        window._stnModal.removePartner = removePartner;
        window._stnModal.importLinmail = importLinmail;
    }

    function editPartner(idx) {
        _editingPartnerIdx = idx;
        const p = idx !== null ? (_settings.partners[idx] ?? {}) : {};
        const bands = p.bands ?? {};
        const allBands = ['80m','40m','30m','20m','17m','15m','10m','6m','2m'];

        let bandRows = allBands.map(b => {
            const bd = bands[b] ?? {};
            const checked = bd.freq ? 'checked' : '';
            return `<tr id="stnBandRow_${b}">
                <td><label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox" ${checked} onchange="window._stnModal.toggleBand('${b}', this.checked)">
                    <strong>${b}</strong></label></td>
                <td><input type="text" id="stnBandFreq_${b}" class="stn-input stn-input-sm" value="${escHtml(bd.freq??'')}" placeholder="e.g. 3.596000" ${checked?'':'disabled'}></td>
                <td><input type="text" id="stnBandMode_${b}" class="stn-input stn-input-sm" value="${escHtml(bd.mode??'')}" placeholder="PKT-U" ${checked?'':'disabled'}></td>
            </tr>`;
        }).join('');

        const editor = document.getElementById('stnPartnerEditor');
        editor.style.display = 'block';
        editor.innerHTML = `
        <div class="stn-section" style="border:2px solid #7c3aed;margin-top:12px;">
            <div class="stn-section-title">${idx !== null ? '✏ Edit' : '＋ Add'} Partner</div>
            <div class="stn-grid2">
                ${field('Callsign',     'stnP_call',         p.call         ?? '', 'text', 'e.g. N3MEL')}
                ${field('Connect Call', 'stnP_connect_call', p.connect_call ?? '', 'text', 'e.g. N3MEL-2 (with SSID)')}
                ${field('Name',         'stnP_name',         p.name         ?? '', 'text', 'Operator first name')}
                ${field('Location',     'stnP_location',     p.location     ?? '', 'text', 'City, State')}
                ${field('Latitude',     'stnP_lat',          p.lat          ?? 0,  'number', '40.01')}
                ${field('Longitude',    'stnP_lon',          p.lon          ?? 0,  'number', '-75.71')}
                ${field('Attach Port',  'stnP_attach_port',  p.attach_port  ?? 3,  'number', '3')}
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer;">
                <input type="checkbox" id="stnP_fixed" ${p.fixed_schedule ? 'checked' : ''}>
                <span class="stn-label" style="margin:0">🔒 Fixed Schedule (don't let Prop Scheduler change time windows)</span>
            </label>
            <div style="margin-top:16px;">
                <div class="stn-section-title" style="font-size:.9rem">Bands &amp; Frequencies</div>
                <table class="stn-table" style="margin-top:8px;">
                    <thead><tr><th>Band</th><th>Frequency (MHz)</th><th>Mode</th></tr></thead>
                    <tbody>${bandRows}</tbody>
                </table>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">
                <button class="stn-btn stn-btn-primary" onclick="window._stnModal.savePartner()">✓ Save Partner</button>
                <button class="stn-btn" onclick="window._stnModal.cancelPartner()">Cancel</button>
            </div>
        </div>`;

        window._stnModal.toggleBand    = toggleBand;
        window._stnModal.savePartner   = savePartner;
        window._stnModal.cancelPartner = cancelPartner;

        editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function toggleBand(band, enabled) {
        const freq = document.getElementById('stnBandFreq_' + band);
        const mode = document.getElementById('stnBandMode_' + band);
        if (freq) freq.disabled = !enabled;
        if (mode) mode.disabled = !enabled;
    }

    function savePartner() {
        const allBands = ['80m','40m','30m','20m','17m','15m','10m','6m','2m'];
        const bands    = {};
        allBands.forEach(b => {
            const cb = document.querySelector(`#stnBandRow_${b} input[type=checkbox]`);
            if (cb && cb.checked) {
                const f = document.getElementById('stnBandFreq_' + b)?.value.trim();
                const m = document.getElementById('stnBandMode_' + b)?.value.trim();
                if (f) bands[b] = { freq: f, mode: m ?? '' };
            }
        });

        const call = val('stnP_call').toUpperCase().trim();
        if (!call) { alert('Callsign is required.'); return; }

        const p = {
            call,
            connect_call:   val('stnP_connect_call').toUpperCase().trim() || call,
            name:           val('stnP_name'),
            location:       val('stnP_location'),
            lat:            parseFloat(val('stnP_lat'))         || 0,
            lon:            parseFloat(val('stnP_lon'))         || 0,
            attach_port:    parseInt(val('stnP_attach_port'))   || 3,
            fixed_schedule: document.getElementById('stnP_fixed')?.checked ?? false,
            bands,
        };

        if (!_settings.partners) _settings.partners = [];
        if (_editingPartnerIdx !== null) {
            _settings.partners[_editingPartnerIdx] = p;
        } else {
            _settings.partners.push(p);
        }

        renderPartners();
    }

    function cancelPartner() {
        const editor = document.getElementById('stnPartnerEditor');
        if (editor) editor.style.display = 'none';
    }

    function removePartner(idx) {
        if (!confirm('Remove partner ' + (_settings.partners[idx]?.call ?? '') + '?')) return;
        _settings.partners.splice(idx, 1);
        renderPartners();
    }

    async function importLinmail() {
        if (!_authed) { showAuthBanner(); return; }
        const cfgPath = _settings.paths?.linmail_cfg ?? '';
        const pathInput = prompt('Path to linmail.cfg:', cfgPath);
        if (!pathInput) return;

        try {
            const res = await apiCall('import_linmail', { path: pathInput });
            if (!res.ok) { alert('Import failed: ' + res.error); return; }
            const imported = res.data.partners ?? [];
            if (!imported.length) { alert('No BBS partner entries found in linmail.cfg.'); return; }

            const msg = `Found ${imported.length} partner(s):\n` +
                imported.map(p => `  ${p.call} — ${Object.keys(p.bands ?? {}).join(', ')}`).join('\n') +
                '\n\nMerge into current partners list? (existing entries with same callsign will be updated)';

            if (!confirm(msg)) return;

            if (!_settings.partners) _settings.partners = [];
            imported.forEach(imp => {
                const existing = _settings.partners.findIndex(ep => ep.call === imp.call);
                if (existing >= 0) {
                    _settings.partners[existing] = { ..._settings.partners[existing], ...imp };
                } else {
                    _settings.partners.push(imp);
                }
            });
            renderPartners();
        } catch (e) {
            alert('Import error: ' + e.message);
        }
    }

    // =========================================================================
    // TAB 3 — PROPAGATION SCHEDULER
    // =========================================================================
    function renderPropSched() {
        const ps    = _settings.prop_scheduler ?? {};
        const panel = document.getElementById('stnTabPropSched');
        if (!panel) return;

        panel.innerHTML = `
        <div class="stn-section">
            <div class="stn-section-title">📡 Propagation Scheduler</div>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer;">
                <input type="checkbox" id="ps_enabled" ${ps.enabled ? 'checked' : ''}>
                <span class="stn-label" style="margin:0">Enable Propagation Scheduler (prop-scheduler.py)</span>
            </label>
            <div class="stn-grid2">
                ${field('Run Interval (hours)', 'ps_interval', ps.interval_hours ?? 48, 'number', '48')}
                ${field('Lookback Days',         'ps_lookback', ps.lookback_days  ?? 14, 'number', '14')}
                ${field('Min Sessions Threshold','ps_min_sessions', ps.min_sessions ?? 3, 'number', '3')}
            </div>

            <div style="margin-top:16px;">
                <div class="stn-section-title" style="font-size:.9rem">⚖ Scoring Weights (must sum to 1.0)</div>
                <div class="stn-grid3" style="margin-top:10px;">
                    ${slider('Propagation Model', 'ps_prop_weight',    ps.prop_weight    ?? 0.25)}
                    ${slider('S/N Ratio',          'ps_sn_weight',      ps.sn_weight      ?? 0.40)}
                    ${slider('Connection Success', 'ps_success_weight', ps.success_weight ?? 0.35)}
                </div>
                <div id="ps_weight_warn" style="color:#f59e0b;font-size:.82rem;margin-top:6px;display:none">
                    ⚠ Weights should ideally sum to 1.0
                </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-top:16px;cursor:pointer;">
                <input type="checkbox" id="ps_conserve" ${ps.conserve_mode ? 'checked' : ''}>
                <span class="stn-label" style="margin:0">Conservative mode — only apply if change is significant</span>
            </label>
            <div style="margin-left:28px;margin-top:8px;display:flex;align-items:center;gap:10px;">
                <label class="stn-label" style="margin:0">Threshold %:</label>
                <input type="number" id="ps_conserve_threshold" class="stn-input" style="width:80px;"
                    value="${ps.conserve_threshold ?? 80}" min="50" max="100">
            </div>
        </div>`;

        // Live weight sum watcher
        ['ps_prop_weight', 'ps_sn_weight', 'ps_success_weight'].forEach(id => {
            const inp = document.getElementById(id);
            if (inp) inp.addEventListener('input', checkWeightSum);
        });
    }

    function checkWeightSum() {
        const sum = ['ps_prop_weight','ps_sn_weight','ps_success_weight']
            .reduce((a, id) => a + (parseFloat(document.getElementById(id)?.value) || 0), 0);
        const warn = document.getElementById('ps_weight_warn');
        if (warn) warn.style.display = Math.abs(sum - 1.0) > 0.01 ? 'block' : 'none';
    }

    function collectPropSched() {
        return { prop_scheduler: {
            enabled:             document.getElementById('ps_enabled')?.checked ?? false,
            interval_hours:      parseInt(val('ps_interval'))           || 48,
            lookback_days:       parseInt(val('ps_lookback'))           || 14,
            min_sessions:        parseInt(val('ps_min_sessions'))       || 3,
            prop_weight:         parseFloat(val('ps_prop_weight'))      || 0.25,
            sn_weight:           parseFloat(val('ps_sn_weight'))        || 0.40,
            success_weight:      parseFloat(val('ps_success_weight'))   || 0.35,
            conserve_mode:       document.getElementById('ps_conserve')?.checked ?? true,
            conserve_threshold:  parseInt(val('ps_conserve_threshold')) || 80,
        }};
    }

    // =========================================================================
    // TAB 4 — STORM MONITOR
    // =========================================================================
    function renderStorm() {
        const sm    = _settings.storm_monitor ?? {};
        const panel = document.getElementById('stnTabStorm');
        if (!panel) return;

        panel.innerHTML = `
        <div class="stn-section">
            <div class="stn-section-title">⛈ Geomagnetic Storm Monitor</div>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer;">
                <input type="checkbox" id="sm_enabled" ${sm.enabled ? 'checked' : ''}>
                <span class="stn-label" style="margin:0">Enable Storm Monitor (storm-monitor.py)</span>
            </label>
            <div class="stn-grid2">
                ${field('Storm Kp Threshold',           'sm_kp_storm',   sm.kp_storm_threshold   ?? 5, 'number', '5 (G1+)')}
                ${field('Restore Kp Threshold',         'sm_kp_restore', sm.kp_restore_threshold ?? 3, 'number', '3')}
                ${field('Consecutive Calm Hours Needed','sm_calm',       sm.consecutive_calm     ?? 2, 'number', '2')}
            </div>
            <div class="stn-note" style="margin-top:16px;">
                <strong>ℹ G-Scale Reference:</strong><br>
                G1 (Kp=5) — HF fading at higher latitudes &nbsp;|&nbsp;
                G2 (Kp=6) — HF propagation degraded &nbsp;|&nbsp;
                G3 (Kp=7) — HF blackout possible &nbsp;|&nbsp;
                G4+ (Kp≥8) — HF blackout likely
            </div>
            <div class="stn-note" style="margin-top:8px;color:#9ca3af;font-size:.82rem;">
                Check interval is cron-managed (recommended: hourly).
                Cron: <code>0 * * * * /usr/bin/python3 /var/www/tprfn/scripts/storm-monitor.py &gt;&gt; .../storm-monitor.log 2&gt;&amp;1</code>
            </div>
        </div>`;
    }

    function collectStorm() {
        return { storm_monitor: {
            enabled:               document.getElementById('sm_enabled')?.checked ?? false,
            kp_storm_threshold:    parseFloat(val('sm_kp_storm'))   || 5,
            kp_restore_threshold:  parseFloat(val('sm_kp_restore')) || 3,
            consecutive_calm:      parseInt(val('sm_calm'))          || 2,
        }};
    }

    // =========================================================================
    // TAB 5 — PATHS & SYSTEM
    // =========================================================================
    function renderPaths() {
        const paths = _settings.paths ?? {};
        const panel = document.getElementById('stnTabPaths');
        if (!panel) return;

        panel.innerHTML = `
        <div class="stn-section">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
                <div class="stn-section-title" style="margin:0">🗂 Paths &amp; System</div>
                <button class="stn-btn stn-btn-primary" onclick="window._stnModal.detectPaths()">🔍 Auto-Detect</button>
            </div>
            <div class="stn-grid1">
                ${field('linmail.cfg path',     'path_linmail',   paths.linmail_cfg   ?? '', 'text', '/home/sysop/linbpq/linmail.cfg')}
                ${field('BPQ Stop Command',     'path_bpq_stop',  paths.bpq_stop_cmd  ?? '', 'text', 'systemctl stop bpq')}
                ${field('BPQ Start Command',    'path_bpq_start', paths.bpq_start_cmd ?? '', 'text', 'systemctl start bpq')}
                ${field('Log Directory',        'path_log_dir',   paths.log_dir       ?? '', 'text', '/var/www/tprfn/logs')}
                ${field('Backup Directory',     'path_backup_dir',paths.backup_dir    ?? '', 'text', '/var/www/tprfn/scripts/prop-backups')}
            </div>
        </div>`;

        window._stnModal.detectPaths = detectPaths;
    }

    async function detectPaths() {
        try {
            const res = await apiCall('detect');
            if (!res.ok) { alert('Detection failed: ' + res.error); return; }
            const p = res.data.paths ?? {};
            setVal('path_linmail',    p.linmail_cfg    ?? '');
            setVal('path_bpq_stop',  p.bpq_stop_cmd   ?? '');
            setVal('path_bpq_start', p.bpq_start_cmd  ?? '');
            setVal('path_log_dir',   p.log_dir        ?? '');
            setVal('path_backup_dir',p.backup_dir     ?? '');
        } catch (e) {
            alert('Detection error: ' + e.message);
        }
    }

    function collectPaths() {
        return { paths: {
            linmail_cfg:   val('path_linmail'),
            bpq_stop_cmd:  val('path_bpq_stop'),
            bpq_start_cmd: val('path_bpq_start'),
            log_dir:       val('path_log_dir'),
            backup_dir:    val('path_backup_dir'),
        }};
    }

    // =========================================================================
    // SAVE ALL
    // =========================================================================
    async function saveAll() {
        if (!_authed) { showAuthBanner(); return; }

        // Collect from all tabs
        const payload = Object.assign({},
            collectStation(),
            collectPropSched(),
            collectStorm(),
            collectPaths(),
            { partners: _settings.partners ?? [] }
        );

        try {
            const res = await apiCall('save', { settings: payload });
            if (!res.ok) {
                if (res.error && res.error.includes('Authentication')) { showAuthBanner(); return; }
                alert('Save failed: ' + res.error);
                return;
            }
            _settings = Object.assign(_settings, payload);
            const msg = document.getElementById('stnSaveMsg');
            if (msg) {
                msg.textContent = '✓ Saved at ' + new Date().toLocaleTimeString();
                setTimeout(() => { msg.textContent = ''; }, 4000);
            }
        } catch (e) {
            alert('Save error: ' + e.message);
        }
    }

    // =========================================================================
    // AUTH
    // =========================================================================
    function showLockedState() {
        // Replace all panel content with a locked message
        const panelIds = ['stnTabStation', 'stnTabPartners', 'stnTabPropSched', 'stnTabStorm', 'stnTabPaths'];
        panelIds.forEach(pid => {
            const p = document.getElementById(pid);
            if (p) p.innerHTML = `
                <div style="display:flex;flex-direction:column;align-items:center;
                            justify-content:center;height:200px;gap:12px;color:#9ca3af;">
                    <span style="font-size:2.5rem;">🔒</span>
                    <span style="font-size:1rem;">Enter BBS password to view settings</span>
                </div>`;
        });
    }

    function showAuthBanner() {
        const banner = document.getElementById('stnAuthBanner');
        if (banner) { banner.style.display = 'block'; document.getElementById('stnAuthPass')?.focus(); }
    }

    async function attemptAuth() {
        const passEl = document.getElementById('stnAuthPass');
        const msgEl  = document.getElementById('stnAuthMsg');
        const pass   = passEl?.value ?? '';
        if (!pass) return;

        try {
            const res = await apiCall('auth', { password: pass });
            if (res.ok && res.data?.authenticated) {
                _authed = true;
                const banner = document.getElementById('stnAuthBanner');
                if (banner) banner.style.display = 'none';
                if (msgEl)  msgEl.textContent = '';
                // Now load full settings and render tabs
                await loadSettings();
                renderAllTabs();
            } else {
                if (msgEl) msgEl.textContent = 'Incorrect password';
                passEl?.select();
            }
        } catch (e) {
            if (msgEl) msgEl.textContent = 'Auth error: ' + e.message;
        }
    }

    // =========================================================================
    // API CALL
    // =========================================================================
    async function apiCall(action, extra = {}) {
        const body = JSON.stringify({ action, ...extra });
        const res  = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body,
        });
        return res.json();
    }

    // =========================================================================
    // DOM HELPERS
    // =========================================================================
    function el(tag, attrs) {
        const e = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => {
            if (k === 'class') e.className = v;
            else e.setAttribute(k, v);
        });
        return e;
    }

    function field(label, id, value, type, placeholder) {
        const isPw = type === 'password';
        return `<div>
            <label class="stn-label" for="${id}">${escHtml(label)}</label>
            <input type="${isPw ? 'password' : type}" id="${id}" class="stn-input"
                value="${escHtml(String(value))}" placeholder="${escHtml(placeholder ?? '')}">
        </div>`;
    }

    function slider(label, id, value) {
        const pct = Math.round((value ?? 0) * 100);
        return `<div>
            <label class="stn-label" for="${id}">${escHtml(label)}: <strong id="${id}_lbl">${pct}%</strong></label>
            <input type="range" id="${id}" class="stn-slider" min="0" max="100" step="1" value="${pct}"
                oninput="document.getElementById('${id}_lbl').textContent=this.value+'%'">
            <input type="hidden" id="${id}_hid" value="${value}">
        </div>`;
    }

    function val(id) {
        const el = document.getElementById(id);
        if (!el) return '';
        if (el.tagName === 'TEXTAREA') return el.value;
        if (id.endsWith('_weight')) {
            // Range inputs store 0-100, convert to 0.00-1.00
            return String(parseFloat(el.value) / 100);
        }
        return el.value;
    }

    function setVal(id, v) {
        const e = document.getElementById(id);
        if (e) e.value = v;
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // =========================================================================
    // STYLES
    // =========================================================================
    function injectStyles() {
        if (document.getElementById('stnModalStyles')) return;
        const s = document.createElement('style');
        s.id = 'stnModalStyles';
        s.textContent = `
        #stnSettingsOverlay {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
            z-index:9999; justify-content:center; align-items:flex-start;
            padding:20px 16px; overflow-y:auto;
        }
        #stnSettingsModal {
            background:#fff; border-radius:14px; width:100%; max-width:820px;
            box-shadow:0 24px 60px rgba(0,0,0,.35); overflow:hidden;
            display:flex; flex-direction:column; min-height:400px;
        }
        [data-theme=dark] #stnSettingsModal { background:#1f2937; color:#f3f4f6; }
        [data-theme=dark] .stn-input { background:#374151; border-color:#4b5563; color:#f3f4f6; }
        [data-theme=dark] .stn-table th { background:#374151; color:#d1d5db; }
        [data-theme=dark] .stn-table td { border-color:#374151; color:#d1d5db; }
        [data-theme=dark] .stn-table tr:hover td { background:#374151; }
        [data-theme=dark] .stn-section { border-color:#374151; background:#111827; }
        [data-theme=dark] .stn-label { color:#9ca3af; }
        [data-theme=dark] #stnTabBar { border-color:#374151; background:#111827; }
        [data-theme=dark] .stn-tab { color:#9ca3af; }
        [data-theme=dark] .stn-tab:hover { background:#374151; }
        [data-theme=dark] .stn-tab-active { background:#1f2937; color:#7c3aed; border-bottom-color:#1f2937; }
        [data-theme=dark] #stnFooter { border-color:#374151; background:#111827; }
        [data-theme=dark] .stn-btn { background:#374151; color:#d1d5db; border-color:#4b5563; }
        [data-theme=dark] .stn-btn:hover { background:#4b5563; }
        [data-theme=dark] #stnAuthBanner { background:#1e3a5f; border-color:#3b82f6; }
        #stnSettingsHeader {
            background:linear-gradient(135deg,#7c3aed,#4f46e5); color:#fff;
            padding:16px 20px; font-size:1.1rem; font-weight:700;
            display:flex; justify-content:space-between; align-items:center;
        }
        #stnSettingsClose {
            background:rgba(255,255,255,.2); border:none; color:#fff;
            width:30px; height:30px; border-radius:50%; cursor:pointer;
            font-size:1rem; display:flex; align-items:center; justify-content:center;
        }
        #stnSettingsClose:hover { background:rgba(255,255,255,.35); }
        #stnAuthBanner {
            background:#eff6ff; border-bottom:1px solid #bfdbfe;
            padding:10px 20px; font-size:.9rem;
        }
        #stnTabBar {
            display:flex; border-bottom:1px solid #e5e7eb;
            background:#f9fafb; overflow-x:auto; flex-shrink:0;
        }
        .stn-tab {
            padding:10px 18px; border:none; background:none; cursor:pointer;
            font-size:.875rem; color:#6b7280; white-space:nowrap;
            border-bottom:2px solid transparent; transition:all .15s;
        }
        .stn-tab:hover { background:#f3f4f6; color:#374151; }
        .stn-tab-active { color:#7c3aed; border-bottom-color:#7c3aed; font-weight:600; background:#fff; }
        #stnPanels { flex:1; overflow-y:auto; }
        .stn-panel { display:none; padding:20px; }
        .stn-panel-active { display:block; }
        .stn-section {
            border:1px solid #e5e7eb; border-radius:10px;
            padding:16px; margin-bottom:16px; background:#fafafa;
        }
        .stn-section-title {
            font-weight:700; font-size:.95rem; color:#374151; margin-bottom:12px;
        }
        .stn-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .stn-grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
        .stn-grid1 { display:grid; grid-template-columns:1fr; gap:12px; }
        @media(max-width:560px) { .stn-grid2,.stn-grid3 { grid-template-columns:1fr; } }
        .stn-label {
            display:block; font-size:.8rem; font-weight:600; color:#6b7280;
            text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;
        }
        .stn-input {
            width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:7px;
            font-size:.9rem; box-sizing:border-box; background:#fff;
            transition:border-color .15s, box-shadow .15s;
        }
        .stn-input:focus { outline:none; border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.15); }
        .stn-input-sm { padding:4px 8px; font-size:.82rem; }
        .stn-input:disabled { background:#f3f4f6; color:#9ca3af; }
        .stn-slider { width:100%; accent-color:#7c3aed; cursor:pointer; }
        .stn-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .stn-table th {
            text-align:left; padding:8px 10px; background:#f3f4f6;
            font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:#6b7280;
        }
        .stn-table td { padding:8px 10px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .stn-table tr:hover td { background:#faf5ff; }
        .stn-btn {
            padding:6px 14px; border:1px solid #d1d5db; border-radius:7px;
            background:#fff; color:#374151; cursor:pointer; font-size:.875rem;
            transition:all .15s;
        }
        .stn-btn:hover { background:#f3f4f6; border-color:#9ca3af; }
        .stn-btn-primary { background:#7c3aed; color:#fff; border-color:#7c3aed; }
        .stn-btn-primary:hover { background:#6d28d9; border-color:#6d28d9; }
        .stn-btn-danger { background:#fef2f2; color:#dc2626; border-color:#fca5a5; }
        .stn-btn-danger:hover { background:#fee2e2; }
        .stn-btn-xs { padding:3px 8px; font-size:.78rem; }
        .stn-note { background:#fef9c3; border:1px solid #fde68a; border-radius:7px; padding:10px 14px; font-size:.85rem; color:#92400e; }
        #stnFooter {
            display:flex; justify-content:space-between; align-items:center;
            padding:14px 20px; border-top:1px solid #e5e7eb; background:#f9fafb;
        }
        `;
        document.head.appendChild(s);
    }

})();
