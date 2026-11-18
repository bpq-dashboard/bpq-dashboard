/* =============================================================================
 * rf-modem-report.js — RF Connections → "Modem performance" tab (v1.5.63)
 *
 * How each HF modem (VARA HF, Mercury, Data2G) has done over the last 7, 14
 * or 30 days: success rate, how calls ended, S/N, bytes, success by band, a
 * timeline of every connection's S/N against the planetary Kp, and a
 * day-by-day table with NOAA's A index (planetary and Fredericksburg) and
 * the highest Kp. A few findings are worked out from the numbers.
 *
 * Data: rf-connections-api.php?action=sessions (the dashboard's own
 * rf_connections table; VHF rows left out) and geomag-proxy.php?data=dgd
 * (NOAA SWPC "Daily Geomagnetic Data", the last 30 days, cached by the
 * proxy). Nothing is written anywhere. Loaded only when the tab is opened.
 * ========================================================================== */
(function () {
    'use strict';

    const API = 'rf-connections-api.php';
    const MODEMS = [
        { id: 'vara', name: 'VARA HF', cls: 'mp-c-vara' },
        { id: 'mercury', name: 'Mercury', cls: 'mp-c-merc' },
        { id: 'data2g', name: 'Data2G', cls: 'mp-c-d2g' },
    ];
    const OUTCOMES = [
        { id: 'passed', label: 'Passed traffic', cls: 'mp-o-ok' },
        { id: 'nothing', label: 'Connected, nothing passed', cls: 'mp-o-warn' },
        { id: 'noanswer', label: 'No answer or busy', cls: 'mp-o-muted' },
        { id: 'other', label: 'Other (turned away, not completed, restart)', cls: 'mp-o-bad' },
    ];
    const BANDS = [[1800, 2000, '160m'], [3500, 4000, '80m'], [5330, 5410, '60m'], [7000, 7300, '40m'], [10100, 10150, '30m'],
        [14000, 14350, '20m'], [18068, 18168, '17m'], [21000, 21450, '15m'], [24890, 24990, '12m'], [28000, 29700, '10m']];
    const BAND_ORDER = BANDS.map((b) => b[2]);
    const MSG_BYTES = 500;   // a session that carried a message, not just a mail check

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const $ = (id) => document.getElementById(id);
    const fmtInt = (n) => Number(n || 0).toLocaleString('en-US');
    const pct = (a, b) => (b ? Math.round((100 * a) / b) : null);
    const median = (arr) => {
        const s = arr.filter((x) => x != null && !isNaN(x)).sort((a, b) => a - b);
        if (!s.length) return null;
        const m = s.length >> 1;
        return Math.round((s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2) * 10) / 10;
    };
    const medInt = (arr) => { const m = median(arr); return m == null ? null : Math.round(m); };
    const utcDay = (t) => new Date(t).toISOString().slice(0, 10);
    const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const fmtDay = (iso) => { const d = new Date(iso + 'T00:00:00Z'); return MON[d.getUTCMonth()] + ' ' + d.getUTCDate(); };
    const fmtTime = (t) => { const d = new Date(t); return fmtDay(utcDay(t)) + ' ' + String(d.getUTCHours()).padStart(2, '0') + ':' + String(d.getUTCMinutes()).padStart(2, '0') + ' UTC'; };

    function bandOf(row) {
        if (row.band && BAND_ORDER.includes(row.band)) return row.band;
        const f = parseFloat(row.freq_khz);
        if (!isNaN(f)) for (const [lo, hi, name] of BANDS) if (f >= lo && f <= hi) return name;
        return null;
    }
    function outcomeOf(row) {
        if (row.status === 'success') return 'passed';
        const r = row.failure_reason || '';
        if (['no_answer', 'channel_busy', 'busy', 'not_listening'].includes(r)) return 'noanswer';
        if (r === 'no_data') return 'nothing';
        return 'other';
    }

    /** NOAA DGD.txt → {date: {aP, aFred, kp: [8 × number|null]}} */
    function parseDGD(text) {
        const out = {};
        if (!text) return out;
        for (const line of text.split('\n')) {
            const m = line.match(/^(\d{4}) (\d\d) (\d\d)\s+(-?\d+)\s+(.{16})\s+(-?\d+)\s+(.{16})\s+(-?\d+)\s+(.*)$/);
            if (!m) continue;
            const kp = m[9].trim().split(/\s+/).map(Number).map((v) => (v < 0 || isNaN(v) ? null : v));
            out[`${m[1]}-${m[2]}-${m[3]}`] = { aP: +m[8] < 0 ? null : +m[8], aFred: +m[4] < 0 ? null : +m[4], kp };
        }
        return out;
    }
    function kpAt(dgd, t) {
        const d = dgd[utcDay(t)];
        if (!d) return null;
        return d.kp[Math.floor(new Date(t).getUTCHours() / 3)] ?? null;
    }

    let state = null, loading = false, resizeTimer = null;

    window.loadModemReport = async function (force) {
        const body = $('mpBody');
        if (!body || loading) return;
        const days = Number(($('mpDays') || {}).value || 14);
        if (state && state.days === days && !force) { render(); return; }
        loading = true;
        body.innerHTML = '<div class="rf-empty">Loading…</div>';
        try {
            const [sRes, gText] = await Promise.all([
                fetch(API + '?action=sessions&days=' + days + '&limit=5000&t=' + Date.now(), { cache: 'no-store' }).then((r) => r.json()),
                fetch('geomag-proxy.php?data=dgd').then((r) => (r.ok ? r.text() : null)).catch(() => null),
            ]);
            if (!sRes || !sRes.ok) throw new Error((sRes && sRes.error) || 'the connection data did not load');
            const dgd = parseDGD(gText);
            const rows = (sRes.data.sessions || [])
                .filter((r) => ['vara', 'mercury', 'data2g', null, undefined, ''].includes(r.modem) && r.modem !== 'vhf')
                .map((r) => {
                    const t = Date.parse(r.session_start);
                    return {
                        t, day: utcDay(t), modem: r.modem || 'vara', dir: r.direction === 'outgoing' ? 'out' : 'in', call: r.callsign,
                        band: bandOf(r), freq: r.freq_khz ? parseFloat(r.freq_khz) : null,
                        snr: r.avg_snr_db != null && r.avg_snr_db !== '' ? parseFloat(r.avg_snr_db) : null,
                        tx: parseInt(r.tx_bytes || 0), rx: parseInt(r.rx_bytes || 0), dur: parseInt(r.duration_sec || 0),
                        link: r.link_sec != null && r.link_sec !== '' ? parseInt(r.link_sec) : null,
                        outcome: outcomeOf(r), reason: r.failure_reason || '', mode: r.link_mode || '', kp: null,
                    };
                })
                .filter((r) => !isNaN(r.t))
                .sort((a, b) => a.t - b.t);
            rows.forEach((r) => {
                r.kp = kpAt(dgd, r.t);
                // v1.5.64: effective throughput, the same for every modem: bytes x 8 / seconds connected
                const secs = r.link != null ? r.link : r.dur, bytes = r.tx + r.rx;
                r.eff = r.outcome === 'passed' && bytes > 0 && secs > 0 ? Math.round((bytes * 8) / secs) : null;
                r.big = bytes >= MSG_BYTES;
            });
            state = { days, rows, dgd, geoOk: Object.keys(dgd).length > 0, at: Date.now() };
            render();
        } catch (e) {
            body.innerHTML = '<div class="rf-empty">The modem report is not available (' + esc(e.message) + ').</div>';
        } finally {
            loading = false;
        }
    };

    function summarise(rows) {
        const per = {};
        for (const m of MODEMS) {
            const r = rows.filter((x) => x.modem === m.id);
            if (!r.length) continue;
            const ok = r.filter((x) => x.outcome === 'passed');
            const cnt = (o) => r.filter((x) => x.outcome === o).length;
            per[m.id] = {
                ...m, n: r.length, ok: ok.length, rate: pct(ok.length, r.length),
                snr: median(ok.map((x) => x.snr)), bytes: ok.reduce((s, x) => s + x.tx + x.rx, 0),
                passed: ok.length, nothing: cnt('nothing'), noanswer: cnt('noanswer'), other: cnt('other'),
                calls: new Set(r.map((x) => x.call)).size,
                eff: medInt(ok.map((x) => x.eff)), effBig: medInt(ok.filter((x) => x.big).map((x) => x.eff)),
                nBig: ok.filter((x) => x.big && x.eff != null).length,
                bands: BAND_ORDER.map((b) => {
                    const rb = r.filter((x) => x.band === b);
                    if (!rb.length) return null;
                    const okb = rb.filter((x) => x.outcome === 'passed');
                    return { band: b, n: rb.length, ok: okb.length, rate: pct(okb.length, rb.length), snr: median(okb.map((x) => x.snr)), eff: medInt(okb.map((x) => x.eff)) };
                }).filter(Boolean),
            };
        }
        return per;
    }

    function render() {
        const body = $('mpBody');
        if (!body || !state) return;
        const { rows, dgd, days } = state;
        if (!rows.length) {
            body.innerHTML = '<div class="rf-empty">No VARA HF, Mercury or Data2G connections in the last ' + days + ' days.</div>';
            return;
        }
        const per = summarise(rows);
        const present = MODEMS.filter((m) => per[m.id]);
        let h = '';

        // ── takeaway ──
        h += '<p class="mp-lede">' + present.map((m) => {
            const p = per[m.id];
            return '<b>' + esc(m.name) + '</b> passed traffic on <b>' + p.rate + '%</b> of ' + fmtInt(p.n) + ' connection' + (p.n === 1 ? '' : 's');
        }).join('; ') + ' in the last ' + days + ' days.' + geoSentence() + '</p>';

        // ── tiles ──
        h += '<div class="mp-tiles">' + present.map((m) => {
            const p = per[m.id];
            return '<div class="mp-tile"><div class="mp-tile-h"><i class="mp-sw ' + m.cls + '"></i>' + esc(m.name) + '</div>'
                + '<div class="mp-big">' + p.rate + '%</div>'
                + '<div class="mp-kv"><span>Passed traffic</span><b>' + fmtInt(p.ok) + ' of ' + fmtInt(p.n) + '</b></div>'
                + '<div class="mp-kv"><span>Median S/N, good sessions</span><b>' + (p.snr == null ? 'not reported' : p.snr + ' dB') + '</b></div>'
                + '<div class="mp-kv" title="Bytes both ways x 8, over the time connected: worked out the same way for every modem"><span>Effective throughput, median</span><b>' + (p.eff == null ? '–' : p.eff + ' bps') + '</b></div>'
                + '<div class="mp-kv" title="Only sessions that moved ' + MSG_BYTES + ' bytes or more, so short mail checks don\'t pull the figure down"><span>… on message sessions (' + MSG_BYTES + ' B+)</span><b>' + (p.effBig == null ? 'none yet' : p.effBig + ' bps (' + p.nBig + ')') + '</b></div>'
                + '<div class="mp-kv"><span>Bytes moved</span><b>' + fmtInt(p.bytes) + '</b></div>'
                + '<div class="mp-kv"><span>Stations</span><b>' + p.calls + '</b></div></div>';
        }).join('') + '</div>';

        // ── timeline ──
        h += '<h3 class="rf-h3">Every connection, by S/N, against the planetary K index</h3>'
            + '<div class="rf-small rf-muted">Dots: connections that passed traffic, at their average S/N. Squares: passed, no S/N reported. Crosses: failed calls. Shading: the 3-hourly planetary Kp, darker when more disturbed (red from Kp 5, a storm). UTC.</div>'
            + '<div class="mp-legend">' + present.map((m) => '<span><i class="mp-sw ' + m.cls + '"></i>' + esc(m.name) + '</span>').join('')
            + '<span><i class="mp-key mp-kp-3"></i>Kp 2–4 (darker = higher)</span><span><i class="mp-key mp-kp-5"></i>Kp 5+</span></div>'
            + '<div class="mp-chart" id="mpTimeline"></div>';

        // ── outcomes + bands ──
        h += '<div class="mp-two"><div><h3 class="rf-h3">How each modem\'s calls ended</h3>'
            + '<div class="rf-small rf-muted">"No answer" usually means the other station wasn\'t on that frequency or was scanning. "Connected, nothing passed" is a link that came up but couldn\'t carry data.</div>'
            + '<div class="mp-legend">' + OUTCOMES.map((o) => '<span><i class="mp-key ' + o.cls + '"></i>' + esc(o.label) + '</span>').join('') + '</div>'
            + '<div class="mp-chart" id="mpOutcomes"></div></div>'
            + '<div><h3 class="rf-h3">Success rate by band</h3><div class="rf-small rf-muted">Share of connections that passed traffic, with passed / attempted beside each bar. Hover for the median S/N.</div>'
            + '<div class="mp-chart" id="mpBands"></div></div></div>';

        // ── head to head: same station, same band, more than one modem ──
        const h2h = headToHead();
        h += '<h3 class="rf-h3">Head to head: the same station on the same band</h3>'
            + '<div class="rf-small rf-muted">The fairest comparison: where one station has worked you on the same band with more than one modem. Effective throughput is bytes both ways × 8 over the time connected, the same sum for every modem. Medians of the good sessions; the figure in brackets is how many.</div>';
        h += h2h.length
            ? '<div class="mp-tw"><table class="mp-table"><thead><tr><th>Station</th><th>Band</th>' + present.map((m) => '<th>' + esc(m.name) + ' passed</th><th>' + esc(m.name) + ' effective</th>').join('') + '</tr></thead><tbody>'
              + h2h.map((g) => '<tr><td>' + esc(g.call) + '</td><td>' + g.band + '</td>' + present.map((m) => {
                    const v = g.by[m.id];
                    if (!v) return '<td>–</td><td>–</td>';
                    return '<td>' + v.ok + ' / ' + v.n + '</td><td>' + (v.eff == null ? '–' : v.eff + ' bps (' + v.k + ')') + '</td>';
                }).join('') + '</tr>').join('') + '</tbody></table></div>'
            : '<div class="rf-empty" style="padding:12px">No station has worked you on the same band with two modems in this period yet.</div>';

        // ── daily table ──
        const dayList = [...new Set(rows.map((r) => r.day))].sort();
        h += '<h3 class="rf-h3">Day by day, with the A and K indices</h3>'
            + '<div class="rf-small rf-muted">A index: planetary, and Fredericksburg (NOAA\'s mid-latitude station in Virginia). Quiet is below 8, unsettled 8–15, active 16–29, storm 30 and up. Per modem: passed / attempted, and the median S/N of the good sessions.</div>'
            + '<div class="mp-tw"><table class="mp-table"><thead><tr><th>Date (UTC)</th><th>A planetary</th><th>A Fredericksburg</th><th>Highest Kp</th>'
            + present.map((m) => '<th>' + esc(m.name) + '</th>' + (per[m.id].snr == null ? '' : '<th>' + esc(m.name) + ' S/N</th>')).join('') + '</tr></thead><tbody>'
            + dayList.slice().reverse().map((d) => {
                const g = dgd[d];
                const kps = g ? g.kp.filter((v) => v != null) : [];
                const kmax = kps.length ? Math.max(...kps) : null;
                return '<tr><td>' + fmtDay(d) + '</td><td class="' + aClass(g && g.aP) + '">' + (g && g.aP != null ? g.aP : '–') + '</td>'
                    + '<td class="' + aClass(g && g.aFred) + '">' + (g && g.aFred != null ? g.aFred : '–') + '</td>'
                    + '<td class="' + (kmax >= 5 ? 'mp-bad' : kmax >= 4 ? 'mp-warn' : '') + '">' + (kmax != null ? kmax.toFixed(2).replace(/\.?0+$/, '') : '–') + '</td>'
                    + present.map((m) => {
                        const r = rows.filter((x) => x.day === d && x.modem === m.id);
                        const ok = r.filter((x) => x.outcome === 'passed');
                        const s = median(ok.map((x) => x.snr));
                        return '<td>' + (r.length ? ok.length + ' / ' + r.length : '–') + '</td>' + (per[m.id].snr == null ? '' : '<td>' + (s == null ? '–' : s + ' dB') + '</td>');
                    }).join('') + '</tr>';
            }).join('') + '</tbody></table></div>';

        // ── findings ──
        h += '<h3 class="rf-h3">What the numbers say</h3><ul class="mp-findings">' + findings(per).map((f) => '<li>' + f + '</li>').join('') + '</ul>';

        h += '<details class="mp-notes"><summary>How to read these numbers</summary>'
            + '<p>S/N isn\'t comparable between modems: VARA measures it over a wider noise bandwidth, so its figures run lower than Data2G\'s for the same signal. Compare each modem with itself. Mercury doesn\'t report S/N.</p>'
            + '<p>An incoming VARA call only gets a row once it connects, so incoming VARA always counts as a success; failed incoming tries aren\'t seen. Data2G and Mercury record failed incoming calls too, so their rates include them.</p>'
            + '<p>Effective throughput is bytes both ways × 8 divided by the seconds connected: VARA\'s Session Time, and for Mercury and Data2G the time the link was up (rows stored before v1.5.64 use the whole call, connect tries included, so they read a little low until the logs are re-read with <code>--backfill-link</code>). It includes turnarounds, acknowledgements and idle time, so a short mail check reads far lower than the link could carry. The Modem rate column in Recent connections is each modem\'s own figure and is not comparable between modems.</p>'
            + '<p>Winlink sessions count under their band. VHF packet is left out. The Kp for a connection is the planetary Kp for the 3-hour period it started in. NOAA\'s daily file covers the last 30 days; the newest day is partial and is revised later.</p>'
            + '</details>';
        h += '<div class="mp-tip" id="mpTip" hidden></div>';

        body.innerHTML = h;
        drawTimeline(); drawOutcomes(per, present); drawBands(per, present);
    }

    function headToHead() {
        const groups = {};
        for (const r of state.rows) {
            if (!r.band) continue;
            const base = String(r.call || '').replace(/-\d+$/, '');
            const k = base + '|' + r.band;
            const g = groups[k] = groups[k] || { call: base, band: r.band, by: {} };
            const b = g.by[r.modem] = g.by[r.modem] || { n: 0, ok: 0, effs: [] };
            b.n++;
            if (r.outcome === 'passed') { b.ok++; if (r.eff != null) b.effs.push(r.eff); }
        }
        return Object.values(groups)
            .filter((g) => Object.values(g.by).filter((b) => b.n >= 1).length >= 2)
            .map((g) => { for (const b of Object.values(g.by)) { b.eff = medInt(b.effs); b.k = b.effs.length; } return g; })
            .sort((a, b) => a.call.localeCompare(b.call) || BAND_ORDER.indexOf(a.band) - BAND_ORDER.indexOf(b.band));
    }

    function aClass(a) { return a == null ? '' : a >= 30 ? 'mp-bad' : a >= 16 ? 'mp-warn' : ''; }

    function geoSentence() {
        const days = [...new Set(state.rows.map((r) => r.day))];
        const g = days.map((d) => state.dgd[d]).filter(Boolean);
        if (!g.length) return state.geoOk ? '' : ' (The A and K indices could not be loaded from NOAA.)';
        const aMax = Math.max(...g.map((x) => x.aP ?? 0));
        const kMax = Math.max(...g.flatMap((x) => x.kp.filter((v) => v != null)));
        const word = aMax >= 30 || kMax >= 5 ? 'there was at least one geomagnetic storm' : aMax >= 16 || kMax >= 4 ? 'the field was active at times' : aMax >= 8 ? 'the field was quiet to unsettled' : 'the field was quiet throughout';
        return ' Over the same days ' + word + ' (highest planetary A ' + aMax + ', highest Kp ' + kMax.toFixed(2).replace(/\.?0+$/, '') + ').';
    }

    function findings(per) {
        const out = [];
        const rows = state.rows;
        for (const m of MODEMS) {
            const p = per[m.id];
            if (!p) continue;
            const fails = p.n - p.ok;
            let s = '<strong>' + esc(m.name) + ':</strong> ' + p.ok + ' of ' + p.n + ' connections passed traffic (' + p.rate + '%), with ' + p.calls + ' station' + (p.calls === 1 ? '' : 's') + '.';
            const good = p.bands.filter((b) => b.n >= 3);
            if (good.length > 1) {
                const best = good.slice().sort((a, b) => b.rate - a.rate || (b.snr ?? -99) - (a.snr ?? -99))[0];
                s += ' Best band: ' + best.band + ', ' + best.rate + '% of ' + best.n + (best.snr != null ? ', median S/N ' + best.snr + ' dB' : '') + '.';
            }
            if (fails) {
                const na = pct(p.noanswer, fails);
                s += ' Of ' + fails + ' failure' + (fails === 1 ? '' : 's') + ', ' + na + '% got no answer' + (p.nothing ? ' and ' + p.nothing + ' connected without passing anything' : '') + '.';
                if (na >= 60) s += ' That points to schedules (the other station not on that frequency) more than link quality.';
                if (p.nothing >= 3 && pct(p.nothing, fails) >= 30) s += ' Links that come up but carry nothing usually mean weak or fading signals, or a transmit level or keying problem at one end.';
            }
            if (p.calls === 1 && p.n >= 3) s += ' Only one partner station, so treat the rate with care.';
            out.push(s);
        }
        // Throughput, the same sum for every modem
        const withBig = MODEMS.filter((m) => per[m.id] && per[m.id].nBig > 0);
        if (withBig.length >= 2) {
            out.push('<strong>Throughput on message sessions</strong> (' + MSG_BYTES + ' bytes or more, bytes × 8 over the time connected): '
                + withBig.map((m) => esc(m.name) + ' ' + per[m.id].effBig + ' bps median over ' + per[m.id].nBig + ' session' + (per[m.id].nBig === 1 ? '' : 's')).join(', ')
                + '. The modems\' own rate figures (VARA\'s peak speed level, Data2G\'s best minute) measure different things, so compare with these. They depend on the band, the hour and the stations worked as well as the modem: the head-to-head table above is the fairer test.');
        }
        // Kp comparison where there is a disturbed sample
        const withKp = rows.filter((r) => r.kp != null);
        const dist = withKp.filter((r) => r.kp >= 4), calm = withKp.filter((r) => r.kp < 3);
        if (dist.length >= 5 && calm.length >= 5) {
            const parts = MODEMS.map((m) => {
                const d = dist.filter((r) => r.modem === m.id), c = calm.filter((r) => r.modem === m.id);
                if (d.length < 3 || c.length < 3) return null;
                const rd = pct(d.filter((r) => r.outcome === 'passed').length, d.length), rc = pct(c.filter((r) => r.outcome === 'passed').length, c.length);
                const sd = median(d.filter((r) => r.outcome === 'passed').map((r) => r.snr)), sc = median(c.filter((r) => r.outcome === 'passed').map((r) => r.snr));
                return esc(m.name) + ' ' + rd + '% at Kp 4 or more (' + d.length + ' calls) against ' + rc + '% below Kp 3 (' + c.length + ')'
                    + (sd != null && sc != null ? ', median S/N ' + sd + ' against ' + sc + ' dB' : '');
            }).filter(Boolean);
            out.push('<strong>Geomagnetic effect:</strong> ' + (parts.length ? parts.join('; ') + '. Band and time of day differ between the two groups too, so read this as a hint, not proof.' : 'too few calls per modem in disturbed periods to compare.'));
        } else if (state.geoOk) {
            out.push('<strong>Geomagnetic effect:</strong> fewer than 5 calls fell in disturbed periods (Kp 4 or more), so there is nothing to compare yet. Look again after a geomagnetic storm, or with the 30-day range.');
        }
        return out;
    }

    // ── drawing helpers ─────────────────────────────────────────────────────
    function tipOn(svgHost, items) {
        const tip = $('mpTip');
        if (!tip || !svgHost) return;
        const wrap = $('mpBody');
        svgHost.addEventListener('pointermove', (ev) => {
            const el = ev.target.closest('[data-i]');
            if (!el) { tip.hidden = true; return; }
            const lines = items(+el.dataset.i);
            if (!lines) { tip.hidden = true; return; }
            tip.innerHTML = lines.map((l, i) => '<div' + (i === lines.length - 1 && lines.length > 2 ? ' class="rf-muted"' : '') + '>' + esc(l) + '</div>').join('');
            tip.hidden = false;
            const b = wrap.getBoundingClientRect(), w = tip.offsetWidth, hh = tip.offsetHeight;
            let x = ev.clientX - b.left + 14, y = ev.clientY - b.top + 14;
            if (x + w > b.width) x = Math.max(0, ev.clientX - b.left - w - 14);
            if (ev.clientY + hh + 20 > window.innerHeight) y = ev.clientY - b.top - hh - 14;
            tip.style.left = x + 'px'; tip.style.top = y + 'px';
        });
        svgHost.addEventListener('pointerleave', () => { tip.hidden = true; });
    }
    const modemCls = (id) => (MODEMS.find((m) => m.id === id) || MODEMS[0]).cls;

    function drawTimeline() {
        const host = $('mpTimeline');
        if (!host) return;
        const { rows, dgd, days } = state;
        const W = Math.max(300, host.clientWidth || 600), H = 320, m = { t: 10, r: 10, b: 30, l: 46 };
        const t1 = Date.now(), t0 = t1 - days * 86400e3;
        const X = (t) => m.l + ((t - t0) / (t1 - t0)) * (W - m.l - m.r);
        const snrs = rows.filter((r) => r.snr != null && r.outcome === 'passed').map((r) => r.snr);
        let lo = Math.min(-20, ...snrs), hi = Math.max(15, ...snrs);
        lo = Math.floor(lo / 5) * 5; hi = Math.ceil(hi / 5) * 5;
        const yBot = H - m.b - 26;
        const Y = (v) => m.t + ((hi - v) / (hi - lo)) * (yBot - m.t);
        let s = '<svg width="100%" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Each connection\'s S/N over the last ' + days + ' days by modem, over the 3-hourly planetary Kp; failed calls as crosses along the bottom.">';
        // Kp shading
        for (let d = new Date(utcDay(t0) + 'T00:00:00Z').getTime(); d < t1; d += 86400e3) {
            const g = dgd[utcDay(d)];
            if (!g) continue;
            g.kp.forEach((k, i) => {
                if (k == null) return;
                const a = d + i * 3 * 3600e3, b = a + 3 * 3600e3;
                if (b < t0 || a > t1) return;
                const x0 = X(Math.max(a, t0)), x1 = X(Math.min(b, t1));
                const op = k >= 5 ? 0.45 : k >= 4 ? 0.3 : k >= 3 ? 0.18 : k >= 2 ? 0.07 : 0;
                if (!op) return;
                s += '<rect x="' + x0.toFixed(1) + '" y="' + m.t + '" width="' + Math.max(0, x1 - x0).toFixed(1) + '" height="' + (H - m.b - m.t) + '" class="' + (k >= 5 ? 'mp-kpfill-5' : 'mp-kpfill') + '" style="opacity:' + op.toFixed(2) + '"><title>Kp ' + k + '</title></rect>';
            });
        }
        // grid
        for (let v = lo; v <= hi; v += 5) {
            s += '<line class="mp-grid" x1="' + m.l + '" x2="' + (W - m.r) + '" y1="' + Y(v) + '" y2="' + Y(v) + '"' + (v === 0 ? ' stroke-dasharray="3 3"' : '') + '/>';
            s += '<text class="mp-ax" x="' + (m.l - 6) + '" y="' + (Y(v) + 4) + '" text-anchor="end">' + v + ' dB</text>';
        }
        const step = Math.max(1, Math.ceil(days / Math.max(2, Math.floor((W - m.l) / 70))));
        let n = 0;
        for (let d = new Date(utcDay(t0) + 'T00:00:00Z').getTime() + 86400e3; d < t1; d += 86400e3, n++) {
            if (n % step) continue;
            s += '<line class="mp-grid" x1="' + X(d) + '" x2="' + X(d) + '" y1="' + m.t + '" y2="' + (H - m.b) + '"/>';
            s += '<text class="mp-ax" x="' + X(d) + '" y="' + (H - m.b + 16) + '" text-anchor="middle">' + fmtDay(utcDay(d)) + '</text>';
        }
        s += '<text class="mp-ax" x="' + (m.l - 6) + '" y="' + (yBot + 22) + '" text-anchor="end">failed</text>';
        rows.forEach((r, i) => {
            const x = X(r.t).toFixed(1), c = modemCls(r.modem);
            if (r.outcome !== 'passed') {
                const y = yBot + 18;
                s += '<path data-i="' + i + '" class="mp-x ' + c + '" d="M' + (x - 3.5) + ' ' + (y - 3.5) + 'l7 7m0 -7l-7 7"/>';
            } else if (r.snr == null) {
                s += '<rect data-i="' + i + '" class="mp-dot ' + c + '" x="' + (x - 3.5) + '" y="' + (yBot + 4) + '" width="7" height="7"/>';
            } else {
                s += '<circle data-i="' + i + '" class="mp-dot ' + c + '" cx="' + x + '" cy="' + Y(r.snr).toFixed(1) + '" r="' + (W < 500 ? 3 : 4) + '"/>';
            }
        });
        host.innerHTML = s + '</svg>';
        tipOn(host, (i) => {
            const r = rows[i];
            if (!r) return null;
            const name = (MODEMS.find((x) => x.id === r.modem) || {}).name;
            const res = { passed: 'passed traffic', nothing: 'connected, nothing passed', noanswer: 'no answer / busy', other: r.reason.replace(/_/g, ' ') || 'failed' }[r.outcome];
            return [fmtTime(r.t) + ' · ' + name,
                (r.dir === 'out' ? 'to ' : 'from ') + r.call + ' · ' + (r.band || '?') + (r.freq ? ' · ' + (r.freq / 1000).toFixed(4) + ' MHz' : ''),
                res + (r.snr != null ? ' · S/N ' + r.snr + ' dB' : '') + (r.mode ? ' · ' + r.mode : ''),
                fmtInt(r.tx) + ' / ' + fmtInt(r.rx) + ' bytes · ' + r.dur + ' s' + (r.kp != null ? ' · Kp ' + r.kp : '')];
        });
    }

    function drawOutcomes(per, present) {
        const host = $('mpOutcomes');
        if (!host) return;
        const W = Math.max(260, host.clientWidth || 360), rowH = 40, m = { l: 70, r: 6 }, H = rowH * present.length + 4;
        let s = '<svg width="100%" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="How each modem\'s calls ended.">';
        const items = [];
        present.forEach((md, ri) => {
            const p = per[md.id], y = ri * rowH + 6;
            s += '<text class="mp-lbl" x="0" y="' + (y + 18) + '">' + esc(md.name) + '</text>';
            let acc = 0;
            for (const o of OUTCOMES) {
                const v = p[o.id] / p.n;
                if (!v) continue;
                const x0 = m.l + acc * (W - m.l - m.r), w = v * (W - m.l - m.r);
                items.push([md.name + ': ' + o.label, p[o.id] + ' of ' + p.n + ' calls (' + Math.round(v * 100) + '%)']);
                s += '<rect data-i="' + (items.length - 1) + '" class="' + o.cls + '" x="' + x0.toFixed(1) + '" y="' + y + '" width="' + Math.max(0, w - 1).toFixed(1) + '" height="24" rx="2"/>';
                if (w > 22) s += '<text class="mp-in" x="' + (x0 + 5).toFixed(1) + '" y="' + (y + 16) + '">' + p[o.id] + '</text>';
                acc += v;
            }
        });
        host.innerHTML = s + '</svg>';
        tipOn(host, (i) => items[i]);
    }

    function drawBands(per, present) {
        const host = $('mpBands');
        if (!host) return;
        const bands = BAND_ORDER.filter((b) => present.some((md) => per[md.id].bands.some((x) => x.band === b)));
        if (!bands.length) { host.innerHTML = '<div class="rf-empty">No band information.</div>'; return; }
        const W = Math.max(260, host.clientWidth || 360), barH = 13, gap = 3, grpGap = 12, m = { l: 42, r: 48, b: 20 };
        const grpH = present.length * (barH + gap);
        const H = bands.length * (grpH + grpGap) + m.b;
        const X = (v) => m.l + (v / 100) * (W - m.l - m.r);
        let s = '<svg width="100%" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Success rate by band for each modem.">';
        for (const v of [0, 50, 100]) {
            s += '<line class="mp-grid" x1="' + X(v) + '" x2="' + X(v) + '" y1="0" y2="' + (H - m.b) + '"/><text class="mp-ax" x="' + X(v) + '" y="' + (H - 4) + '" text-anchor="middle">' + v + '%</text>';
        }
        const items = [];
        bands.forEach((b, bi) => {
            const y0 = bi * (grpH + grpGap);
            s += '<text class="mp-lbl" x="0" y="' + (y0 + grpH / 2 + 4) + '">' + b + '</text>';
            present.forEach((md, mi) => {
                const r = per[md.id].bands.find((x) => x.band === b);
                if (!r) return;
                const y = y0 + mi * (barH + gap);
                items.push([md.name + ' on ' + b, r.ok + ' of ' + r.n + ' passed traffic (' + r.rate + '%)', (r.eff != null ? 'median effective ' + r.eff + ' bps · ' : '') + (r.snr != null ? 'median S/N ' + r.snr + ' dB' : 'no S/N reported')]);
                s += '<rect data-i="' + (items.length - 1) + '" class="mp-dot ' + md.cls + '" x="' + m.l + '" y="' + y + '" width="' + Math.max(1.5, X(r.rate) - m.l).toFixed(1) + '" height="' + barH + '" rx="2"/>';
                s += '<text class="mp-ax" x="' + (X(r.rate) + 4).toFixed(1) + '" y="' + (y + barH - 3) + '">' + r.ok + '/' + r.n + '</text>';
            });
        });
        host.innerHTML = s + '</svg>';
        tipOn(host, (i) => items[i]);
    }

    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            const panel = $('rfTab-modems');
            if (state && panel && !panel.classList.contains('hidden')) render();
        }, 200);
    });
})();
