// bpq-send-once.js — send a BBS message exactly once (v1.5.76)
//
// Used by the Email Client (bbs-messages.html) and BBS Mail
// (admin/bbs-mail.js). Every message gets a one-time id (send_id). If the
// connection drops before the server's answer arrives (an iPad that locks
// its screen or switches apps, a Wi-Fi drop: Safari calls it "Load
// failed"), the message may already be on the BBS. Instead of reporting an
// error, this asks the server about that id (check_only) until it knows:
//   - done    → the first answer (it was sent, or why not)
//   - pending → still on the BBS: wait and ask again
//   - unknown → the earlier try never reached the server: send it now
//               (same id, so it still can't be posted twice)
// bbs-messages.php records each id in data/sends/ (outcome and a hash, not
// the text) and never posts the same id twice.
(function () {
    'use strict';

    function newId() {
        var a = new Uint8Array(16);
        // getRandomValues works on plain http too (randomUUID doesn't)
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    // One POST. Returns {data} with the parsed answer, or {uncertain: true}
    // when nothing usable came back (network error, gateway page, timeout).
    async function postOnce(url, payload, timeoutMs) {
        var ctl = ('AbortController' in window) ? new AbortController() : null;
        var t = ctl ? setTimeout(function () { ctl.abort(); }, timeoutMs) : null;
        try {
            var r = await fetch(url, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload), signal: ctl ? ctl.signal : undefined,
            });
            var text = await r.text();
            try { return { data: JSON.parse(text) }; } catch (e) { return { uncertain: true, why: 'HTTP ' + r.status }; }
        } catch (e) {
            return { uncertain: true, why: e && e.message || 'network error' };
        } finally { if (t) clearTimeout(t); }
    }

    // msg: {to, type, subject, body}; opts.sendId (reuse for the same
    // message), opts.onWait(text) for a status line, opts.url.
    // Resolves to the server's answer, or {success:false, unknown:true, error}
    // when it couldn't be confirmed within about 2½ minutes.
    async function sendOnce(msg, opts) {
        opts = opts || {};
        var url = opts.url || '/bbs-messages.php';
        var id = opts.sendId || newId();
        var payload = { action: 'send', to: msg.to, type: msg.type, subject: msg.subject, body: msg.body, send_id: id };
        var wait = opts.onWait || function () {};
        var deadline = Date.now() + (opts.patienceMs || 150000);
        var res = await postOnce(url, payload, 120000);
        var tries = 0;
        while (true) {
            if (res.data && !res.data.pending) {
                if (!res.data.unknown) { res.data.send_id = id; return res.data; }
                // the earlier try never arrived: send it now (same id)
                wait('Sending again (the first try never reached the server)…');
                res = await postOnce(url, payload, 120000);
                continue;
            }
            if (Date.now() > deadline) {
                return { success: false, unknown: true, send_id: id,
                    error: 'Couldn\'t confirm whether it was sent (' + (res.why || 'still sending') + '). Check Sent or the BBS list before sending again. Pressing Send again for this same message won\'t post a second copy.' };
            }
            wait(res.data && res.data.pending ? 'Still sending on the BBS…' : 'Connection lost; checking with the server whether it went…');
            await sleep(tries++ < 4 ? 3000 : 8000);
            res = await postOnce(url, Object.assign({}, payload, { check_only: true }), 20000);
            if (res.data && res.data.unsupported) {
                return { success: false, unknown: true, send_id: id,
                    error: 'The connection dropped and this server can\'t tell whether it was sent. Check Sent or the BBS list before sending again.' };
            }
        }
    }

    window.bpqSendOnce = sendOnce;
    window.bpqNewSendId = newId;
})();
