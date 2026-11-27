// ═══════════════════════════════════════════════════════════════════════
// bbs-mail.js — BBS Mail, the Email Client laid out like Thunderbird
// (BPQ Dashboard v1.5.71). Page: /admin/bbs-mail.html.
//
// Classic layout: folder pane | message list (sortable, resizable columns)
// over the message pane. Tabs (mail, messages, Write), unified toolbar,
// Quick Filter, search across folders, right-click menus, drag and drop to
// folders, Thunderbird shortcuts. Data: the BBS mail store over IMAP via
// /message-storage.php (action=ms*); sending: /bbs-messages.php (send).
// Deleting = to Trash; leaving Trash (Delete in Trash, Empty Trash) kills
// the message on the BBS (v1.5.70).
// ═══════════════════════════════════════════════════════════════════════
(function () {
'use strict';

const API = '/message-storage.php';
const BBS = '/bbs-messages.php';
const PAGE = 200;
const LS = 'bpq_tb_';
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const lsGet = (k, d) => { try { const v = localStorage.getItem(LS + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } };
const lsSet = (k, v) => { try { localStorage.setItem(LS + k, JSON.stringify(v)); } catch (e) { /* private window */ } };

// ── state ──────────────────────────────────────────────────────
const COLS = [
    { id: 'chk',     title: '',  label: 'Select boxes',  w: 34,  fixed: true, sort: null },   // v1.5.101
    { id: 'flag',    title: '★', label: 'Star',          w: 28,  fixed: true, sort: null },
    { id: 'read',    title: '●', label: 'Read status',   w: 28,  fixed: true, sort: null },
    { id: 'subject', title: 'Subject',      label: 'Subject',      w: 0,   sort: 'subject' },
    { id: 'corr',    title: 'Correspondents', label: 'Correspondents', w: 200, sort: 'from' },
    { id: 'date',    title: 'Date',         label: 'Date',         w: 130, sort: 'date' },
    { id: 'size',    title: 'Size',         label: 'Size',         w: 70,  sort: 'size' },
    { id: 'num',     title: 'BBS #',        label: 'BBS number',   w: 70,  sort: null },
    { id: 'type',    title: 'Type',         label: 'Type',         w: 90,  sort: null },
];
const S = {
    folders: [], folder: lsGet('folder', 'INBOX'), open: lsGet('open', { INBOX: true, Bulletins: true }),
    msgs: [], total: 0, unseen: 0, loading: false, error: '',
    sel: new Set(), anchor: null, cur: null,
    sort: lsGet('sort', { by: 'date', desc: true }),
    cols: lsGet('cols', null) || COLS.map(c => ({ id: c.id, w: c.w, on: c.id !== 'num' })),
    qf: { show: lsGet('qf', false), unseen: false, flagged: false, text: '', fields: ['from', 'to', 'subject'] },
    search: null,           // {q} when showing "search all folders" results
    focus: 'list',
    tabs: [{ id: 'mail', type: 'mail', title: 'Inbox' }], tab: 'mail', tabSeq: 0,
    shown: null,            // message open in the message pane {folder, uid, m}
    addresses: [], signature: null, user: '',
    contacts: [], abReady: false,
    prefs: Object.assign({ refreshMin: 2, notify: false, mono: true, names: true, msgFont: '', msgSize: 13, uiSize: 13 }, lsGet('prefs', {})),
};
for (const c of COLS) if (!S.cols.find(x => x.id === c.id)) {
    if (c.id === 'chk') S.cols.unshift({ id: c.id, w: c.w, on: true });     // v1.5.101: select boxes go first
    else S.cols.push({ id: c.id, w: c.w, on: true });
}

// ── tiny helpers ───────────────────────────────────────────────
async function get(params) {
    const r = await fetch(API + '?' + new URLSearchParams(params), { cache: 'no-store' });
    const t = await r.text();
    try { return JSON.parse(t); } catch (e) { return { success: false, error: r.status === 401 ? 'Please log in again' : 'HTTP ' + r.status }; }
}
async function post(body, url = API) {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const t = await r.text();
    try { return JSON.parse(t); } catch (e) { return { success: false, error: 'HTTP ' + r.status }; }
}
function toast(msg, kind) {
    const el = document.createElement('div');
    el.className = 'toast ' + (kind || '');
    el.textContent = msg;
    $('toast').appendChild(el);
    setTimeout(() => el.remove(), kind === 'err' ? (msg.length > 120 ? 15000 : 7000) : 3500);
}
const isTrash = name => (S.folders.find(f => f.name === name) || {}).special === 'trash';
const isUserFolder = f => f && f.name !== 'INBOX' && f.name !== 'Bulletins' && !f.name.startsWith('Bulletins/') && !f.special;
const prettyFolder = n => n === 'INBOX' ? 'Inbox' : String(n).replace(/^INBOX\//, 'Inbox ▸ ').replace(/\//g, ' ▸ ');
const leafName = n => n === 'INBOX' ? 'Inbox' : String(n).split('/').pop();
function fmtDate(iso, long) {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d)) return iso;
    const now = new Date();
    const p = n => String(n).padStart(2, '0');
    const t = `${p(d.getHours())}:${p(d.getMinutes())}`;
    if (long) return d.toLocaleString([], { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) +
        `  (${p(d.getUTCHours())}:${p(d.getUTCMinutes())}Z)`;
    if (d.toDateString() === now.toDateString()) return t;
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return 'Yesterday ' + t;
    return d.toLocaleDateString([], { year: 'numeric', month: '2-digit', day: '2-digit' }) + ' ' + t;
}
function fmtSize(n) { return n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(1) + ' KB' : (n / 1048576).toFixed(1) + ' MB'; }

// ── layout: sit under the dashboard menu; splitters ────────────
function placeUnderNav() {
    const nav = document.querySelector('.bpq-nav');
    const off = document.getElementById('bpqPageOffNote');
    let top = nav ? Math.max(0, nav.getBoundingClientRect().bottom) : 0;
    if (off) top = Math.max(top, off.getBoundingClientRect().bottom);
    $('tb').style.top = top + 'px';
}
function initSplitters() {
    const fp = $('folderPane'), mp = $('msgPane');
    const fw = lsGet('folderW', 240), mh = lsGet('msgH', null);
    fp.style.width = fw + 'px';
    if (mh) mp.style.height = mh + 'px';
    drag($('splitV'), (dx, s) => { const w = Math.max(150, Math.min(480, s.w + dx)); fp.style.width = w + 'px'; lsSet('folderW', w); },
         () => ({ w: fp.offsetWidth }), 'x');
    drag($('splitH'), (dy, s) => { const h = Math.max(80, Math.min($('threadPane').offsetHeight - 120, s.h - dy)); mp.style.height = h + 'px'; lsSet('msgH', h); },
         () => ({ h: mp.offsetHeight }), 'y');
}
function drag(handle, move, start, axis) {
    handle.addEventListener('mousedown', ev => {
        ev.preventDefault();
        const s = start(), x0 = ev.clientX, y0 = ev.clientY;
        const mm = e => move(axis === 'x' ? e.clientX - x0 : e.clientY - y0, s);
        const mu = () => { document.removeEventListener('mousemove', mm); document.removeEventListener('mouseup', mu); document.body.style.cursor = ''; };
        document.body.style.cursor = axis === 'x' ? 'col-resize' : 'row-resize';
        document.addEventListener('mousemove', mm);
        document.addEventListener('mouseup', mu);
    });
}

// ── context menus ──────────────────────────────────────────────
// v1.5.96: not the To-field suggestion boxes (.cm.ac): removing them on every click
// left the To field drawing into a box no longer on the page, so suggestions vanished
function closeMenus() { document.querySelectorAll('.cm:not(.ac)').forEach(m => m.remove()); }
// items: [{label, key, act, off, danger, sub: [items]}] or '-'
// v1.5.79: touch screens (iPad, phone) have no right-click: a long press
// (half a second, finger still) opens the same menu as a right-click.
(function longPress() {
    let t = null, sx = 0, sy = 0, target = null, fired = false;
    const clear = () => { if (t) clearTimeout(t); t = null; };
    document.addEventListener('touchstart', ev => {
        if (ev.touches.length !== 1) return clear();
        target = ev.target.closest('.row, .fld, .ab-card, [data-ctx]');
        if (!target) return;
        const p = ev.touches[0]; sx = p.clientX; sy = p.clientY;
        clear(); fired = false;
        t = setTimeout(() => {
            t = null; fired = true;
            target.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: sx, clientY: sy }));
            if (navigator.vibrate) navigator.vibrate(15);
        }, 550);
    }, { passive: true });
    document.addEventListener('touchmove', ev => {
        const p = ev.touches[0];
        if (p && (Math.abs(p.clientX - sx) > 10 || Math.abs(p.clientY - sy) > 10)) clear();
    }, { passive: true });
    // after a long press, stop the tap that follows from closing the menu
    document.addEventListener('touchend', ev => { clear(); if (fired) { fired = false; ev.preventDefault(); } }, { passive: false });
    document.addEventListener('touchcancel', clear, { passive: true });
})();

function menu(items, x, y, parent, fromRect) {
    if (!parent) closeMenus();
    const m = document.createElement('div');
    m.className = 'cm' + (items.length > 14 ? ' cm-scroll' : '');
    for (const it of items) {
        if (it === '-') { const s = document.createElement('div'); s.className = 'cm-sep'; m.appendChild(s); continue; }
        const d = document.createElement('div');
        d.className = 'cm-i' + (it.off ? ' off' : '') + (it.danger ? ' danger' : '') + (it.sub ? ' sub' : '');
        d.innerHTML = `<span>${esc(it.label)}</span>${it.key ? `<span class="k">${esc(it.key)}</span>` : ''}`;
        if (it.sub && !it.off) {
            const openSub = () => {
                if (d.classList.contains('open')) return;
                m.querySelectorAll(':scope > .cm-i.open').forEach(o => o.classList.remove('open'));
                document.querySelectorAll('.cm:not(.ac)').forEach(o => { if (o.dataset.lvl > (m.dataset.lvl || 0)) o.remove(); });
                d.classList.add('open');
                const r = d.getBoundingClientRect();
                const sm = menu(it.sub, r.right - 2, r.top - 4, m, r);
                sm.dataset.lvl = (+(m.dataset.lvl || 0)) + 1;
            };
            d.addEventListener('mouseenter', openSub);
            d.addEventListener('click', openSub);   // touch screens have no hover (v1.5.78)
        } else {
            d.addEventListener('mouseenter', () => {
                document.querySelectorAll('.cm:not(.ac)').forEach(o => { if (+o.dataset.lvl > +(m.dataset.lvl || 0)) o.remove(); });
                m.querySelectorAll(':scope > .cm-i.open').forEach(o => o.classList.remove('open'));
            });
            d.addEventListener('click', () => { if (it.off) return; closeMenus(); it.act && it.act(); });
        }
        m.appendChild(d);
    }
    document.body.appendChild(m);
    const w = m.offsetWidth, h = m.offsetHeight;
    // v1.5.78: a submenu with no room on the right opens on the left of its
    // item instead of covering it
    if (fromRect && x + w > innerWidth - 2) x = fromRect.left - w + 2;
    m.style.left = Math.max(2, Math.min(x, innerWidth - w - 2)) + 'px';
    m.style.top = Math.max(2, Math.min(y, innerHeight - h - 2)) + 'px';
    return m;
}
document.addEventListener('mousedown', ev => { if (!ev.target.closest('.cm')) closeMenus(); }, true);
window.addEventListener('blur', closeMenus);

// ── folders ────────────────────────────────────────────────────
async function loadFolders() {
    const d = await get({ action: 'msFolders' });
    if (!d.success) { $('folderPane').innerHTML = `<div class="acct">⚠ ${esc(d.error || 'Mail store not answering')}</div>`; return false; }
    S.folders = d.folders || [];
    if (!S.folders.find(f => f.name === S.folder && f.selectable)) S.folder = 'INBOX';
    renderFolders();
    status();
    return true;
}
function folderIcon(f) {
    if (f.name === 'Bulletins') return '📣';
    return { inbox: '📥', sent: '📤', trash: '🗑', drafts: '📝', junk: '🚫' }[f.special] || (f.name.startsWith('Bulletins/') ? '📰' : '📁');
}
function renderFolders() {
    const all = S.folders;
    const names = new Set(all.map(f => f.name));
    const kids = n => all.filter(f => f.name.startsWith(n + '/') && !f.name.slice(n.length + 1).includes('/'));
    const rank = f => f.name === 'INBOX' ? 0 : f.special === 'sent' ? 2 : f.name === 'Bulletins' ? 3 : f.special === 'trash' ? 9 : f.special ? 4 : 1;
    const roots = all.filter(f => { const p = f.name.lastIndexOf('/'); return p === -1 || !names.has(f.name.slice(0, p)); })
                     .sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
    // a parent with no row of its own (e.g. Bulletins without the \Noselect entry)
    if (!names.has('Bulletins') && all.some(f => f.name.startsWith('Bulletins/'))) {
        roots.splice(roots.findIndex(f => rank(f) > 3) === -1 ? roots.length : roots.findIndex(f => rank(f) > 3), 0,
            { name: 'Bulletins', selectable: false, special: '', messages: 0, unseen: 0, virtual: true });
    }
    const sumUnseen = n => all.filter(f => f.name === n || f.name.startsWith(n + '/')).reduce((a, f) => a + (f.unseen || 0), 0);
    let html = `<div class="acct">📬 ${esc(S.user ? S.user.toUpperCase() : 'BBS')} <span class="sub">BBS mail</span></div>`;
    const row = (f, depth) => {
        const ch = f.virtual ? all.filter(x => x.name.startsWith('Bulletins/')) : kids(f.name);
        const open = !!S.open[f.name];
        const un = ch.length && !open ? sumUnseen(f.name) : (f.unseen || 0);
        html += `<div class="fld ${un ? 'unread' : ''} ${S.folder === f.name && !S.search ? 'sel' : ''}" data-f="${esc(f.name)}"
                     style="padding-left:${6 + depth * 16}px" title="${esc(prettyFolder(f.name))}${f.selectable ? ` — ${f.messages} messages, ${f.unseen} unread` : ''}">
            <span class="tw" data-tw="${esc(f.name)}">${ch.length ? (open ? '▾' : '▸') : ''}</span>
            <span class="ic">${folderIcon(f)}</span><span class="nm">${esc(leafName(f.name))}</span>
            <span class="ct">${un ? un : ''}</span></div>`;
        if (ch.length && open) {
            const sorted = f.name === 'Bulletins' ? ch.sort((a, b) => (b.unseen > 0) - (a.unseen > 0) || a.name.localeCompare(b.name))
                                                  : ch.sort((a, b) => a.name.localeCompare(b.name));
            for (const k of sorted) row(k, depth + 1);
        }
    };
    for (const f of roots) row(f, 0);
    const fp = $('folderPane');
    fp.innerHTML = html;
    fp.querySelectorAll('.fld').forEach(el => {
        const name = el.dataset.f;
        el.addEventListener('click', ev => {
            if (ev.target.closest('[data-tw]')) { S.open[name] = !S.open[name]; lsSet('open', S.open); renderFolders(); return; }
            const f = S.folders.find(x => x.name === name);
            if (!f || !f.selectable) { S.open[name] = !S.open[name]; lsSet('open', S.open); renderFolders(); return; }
            S.focus = 'folders'; applyFocus();
            selectFolder(name);
        });
        el.addEventListener('contextmenu', ev => { ev.preventDefault(); folderMenu(name, ev.clientX, ev.clientY); });
        // drop target
        el.addEventListener('dragover', ev => {
            const f = S.folders.find(x => x.name === name);
            if (!f || !f.selectable || name === S.folder || !S.dragging) return;
            ev.preventDefault();
            ev.dataTransfer.dropEffect = ev.ctrlKey ? 'copy' : 'move';
            el.classList.add('drop');
        });
        el.addEventListener('dragleave', () => el.classList.remove('drop'));
        el.addEventListener('drop', ev => {
            ev.preventDefault();
            el.classList.remove('drop');
            if (S.dragging) moveTo(name, ev.ctrlKey ? 'copy' : 'move');
        });
    });
}
function selectFolder(name) {
    S.folder = name; lsSet('folder', name);
    S.search = null; $('tbSearch').value = '';
    S.sel.clear(); S.anchor = null; S.cur = null;
    showMessage(null);
    renderFolders();
    setTabTitle('mail', leafName(name));
    loadList(false);
}
function folderMenu(name, x, y) {
    const f = S.folders.find(v => v.name === name) || { name, selectable: false };
    const items = [];
    if (f.special === 'trash') {
        items.push({ label: 'Empty Trash… (kills them on the BBS too)', danger: true, off: !f.messages, act: () => emptyTrash() });
    } else if (name === 'INBOX' || isUserFolder(f)) {
        items.push({ label: 'New Folder…', act: () => newFolder(name) });
        if (isUserFolder(f)) {
            items.push({ label: 'Rename…', act: () => renameFolder(f) });
            items.push({ label: 'Delete Folder…', danger: true, act: () => deleteFolder(f) });
        }
    }
    // v1.5.73: mark a whole branch read (all of Bulletins, Inbox with its folders)
    const subUnseen = S.folders.filter(x => x.name.startsWith(name + '/')).reduce((a, x) => a + (x.unseen || 0), 0);
    if (f.selectable || subUnseen) {
        if (items.length) items.push('-');
        if (f.selectable) items.push({ label: 'Mark Folder Read', key: 'Shift+C', off: !f.unseen, act: () => markFolderRead(name) });
        if (S.folders.some(x => x.name.startsWith(name + '/')))
            items.push({ label: name === 'Bulletins' ? `Mark All Bulletins Read${subUnseen ? ` (${subUnseen})` : ''}` : `Mark ${leafName(name)} and Its Folders Read`,
                         off: !(subUnseen + (f.unseen || 0)), act: () => markFolderRead(name, true) });
    }
    if (f.selectable && f.special !== 'trash') items.push({ label: 'Run Filters on Folder', act: () => runFilters(name) });
    if (f.selectable || name === 'Bulletins') items.push({ label: 'Properties… (retention)', act: () => folderProps(name) });
    items.push('-');
    items.push({ label: 'New Folder at the top…', act: () => newFolder('') });
    menu(items, x, y);
}
async function newFolder(parent) {
    const name = prompt(parent ? `New folder in ${prettyFolder(parent)}:` : 'New folder:');
    if (!name || !name.trim()) return;
    const d = await post({ action: 'msCreateFolder', parent, name: name.trim() });
    if (!d.success) return toast(d.error || 'Could not make the folder', 'err');
    if (parent) { S.open[parent] = true; lsSet('open', S.open); }
    toast('Folder made: ' + prettyFolder(d.folder), 'ok');
    loadFolders();
}
async function renameFolder(f) {
    const cur = leafName(f.name);
    const name = prompt(`Rename "${cur}" to:`, cur);
    if (!name || !name.trim() || name.trim() === cur) return;
    const d = await post({ action: 'msRenameFolder', folder: f.name, name: name.trim() });
    if (!d.success) return toast(d.error || 'Could not rename it', 'err');
    if (S.folder === f.name) { S.folder = d.folder; lsSet('folder', d.folder); }
    await loadFolders();
    if (S.folder === d.folder) loadList(false);
}
async function deleteFolder(f) {
    const n = f.messages || 0;
    if (!confirm(`Delete the folder "${leafName(f.name)}"?` + (n ? `\n\nIts ${n} message${n === 1 ? '' : 's'} go to Trash (killed on the BBS when Trash is emptied).` : ''))) return;
    const d = await post({ action: 'msDeleteFolder', folder: f.name });
    if (!d.success) return toast(d.error || 'Could not delete it', 'err');
    toast('Folder deleted' + (d.movedToTrash ? `; ${d.movedToTrash} moved to Trash` : ''), 'ok');
    if (S.folder === f.name) selectFolder('INBOX');
    loadFolders();
}
async function markFolderRead(name, tree) {
    const d = await post({ action: 'msMarkFolderRead', folder: name, tree: !!tree });
    if (!d.success) return toast(d.error || 'Failed', 'err');
    if (name === S.folder || (tree && String(S.folder).startsWith(name + '/'))) { S.msgs.forEach(m => { m.seen = true; }); renderRows(true); }
    if (d.count != null) toast(`Marked ${d.count} message${d.count === 1 ? '' : 's'} read` + (d.folders > 1 ? ` in ${d.folders} folders` : ''), 'ok');
    loadFolders();
}
async function emptyTrash() {
    const t = S.folders.find(f => f.special === 'trash');
    if (!t || !t.messages) return;
    if (!confirm(`Empty Trash: delete ${t.messages} message${t.messages === 1 ? '' : 's'} for good?\n\nThey will also be KILLED ON THE BBS (within about 5 minutes) and disappear from Thunderbird.\nThis can't be undone.`)) return;
    const d = await post({ action: 'msEmptyTrash' });
    if (!d.success) return toast(d.error || 'Failed', 'err');
    toast(`Trash emptied: ${d.count} deleted` + killNote(d.kills), 'ok');
    if (isTrash(S.folder)) { S.msgs = []; S.total = 0; S.sel.clear(); showMessage(null); renderRows(); }
    loadFolders();
}
function killNote(k) {
    if (!k) return '';
    if (k.off) return ' (killing on the BBS is turned off on this dashboard)';
    return k.queued ? ` — ${k.queued} will be killed on the BBS within about 5 minutes` : '';
}

// ── message list ───────────────────────────────────────────────
function listParams(offset) {
    const p = { offset, limit: PAGE, sort: S.sort.by, dir: S.sort.desc ? 'desc' : 'asc' };
    if (S.qf.show) {
        if (S.qf.unseen) p.unseen = 1;
        if (S.qf.flagged) p.flagged = 1;
        if (S.qf.text) { p.q = S.qf.text; p.fields = S.qf.fields.join(','); }
    }
    return p;
}
async function loadList(more) {
    if (S.loading) return;
    S.loading = true; S.error = '';
    if (!more) { $('rows').scrollTop = 0; renderRows(); }
    let d;
    if (S.search) {
        d = await get(Object.assign({ action: 'msSearchAll', q: S.search.q }, S.qf.show && S.qf.unseen ? { unseen: 1 } : {}));
    } else {
        const want = S.folder;
        d = await get(Object.assign({ action: 'msList', folder: want }, listParams(more ? S.msgs.length : 0)));
        if (want !== S.folder) { S.loading = false; return; }
    }
    S.loading = false;
    if (!d.success) { S.error = d.error || 'The mail store did not answer'; S.msgs = more ? S.msgs : []; }
    else {
        const rows = (d.messages || []).map(m => Object.assign({ folder: m.folder || S.folder }, m));
        S.msgs = more ? S.msgs.concat(rows) : rows;
        S.total = d.total || 0;
        if (d.unseen != null) S.unseen = d.unseen;
    }
    renderRows();
    status();
}
function colTemplate() {
    return S.cols.filter(c => c.on).map(c => c.id === 'subject' ? 'minmax(160px,1fr)' : c.w + 'px').join(' ');
}
function renderHead() {
    const h = $('colHead');
    h.style.gridTemplateColumns = colTemplate();
    h.innerHTML = S.cols.filter(c => c.on).map(c => {
        const def = COLS.find(x => x.id === c.id);
        const title = c.id === 'corr' && S.folders.find(f => f.name === S.folder && f.special === 'sent') ? 'Recipient' : def.title;
        const sorted = def.sort && S.sort.by === def.sort;
        if (c.id === 'chk') return `<div class="ch c-chk" data-c="chk" title="Select all / none"><input type="checkbox" id="chkAll" aria-label="Select all messages"></div>`;
        return `<div class="ch ${sorted ? 'sorted' : ''}" data-c="${c.id}" title="${esc(def.label)}${def.sort ? ' — click to sort' : ''}">${esc(title)}${sorted ? (S.sort.desc ? ' ▾' : ' ▴') : ''}${def.fixed || c.id === 'subject' ? '' : '<span class="rz"></span>'}</div>`;
    }).join('');
    h.querySelectorAll('.ch').forEach(el => {
        const id = el.dataset.c, def = COLS.find(x => x.id === id);
        el.addEventListener('click', ev => {
            if (id === 'chk') { ev.preventDefault(); return selectAllToggle(); }
            if (ev.target.classList.contains('rz') || !def.sort || S.search) return;
            if (S.sort.by === def.sort) S.sort.desc = !S.sort.desc; else { S.sort.by = def.sort; S.sort.desc = def.sort === 'date' || def.sort === 'size'; }
            lsSet('sort', S.sort);
            renderHead(); loadList(false);
        });
        const rz = el.querySelector('.rz');
        if (rz) drag(rz, (dx, s) => { const c = S.cols.find(x => x.id === id); c.w = Math.max(40, s.w + dx); lsSet('cols', S.cols); renderHead(); renderRows(true); },
                     () => ({ w: S.cols.find(x => x.id === id).w }), 'x');
    });
    h.oncontextmenu = ev => {
        ev.preventDefault();
        menu(COLS.filter(c => !['subject'].includes(c.id)).map(c => {
            const cc = S.cols.find(x => x.id === c.id);
            return { label: (cc.on ? '✓ ' : '   ') + c.label, act: () => { cc.on = !cc.on; lsSet('cols', S.cols); renderHead(); renderRows(true); } };
        }).concat(['-', { label: 'Restore column defaults', act: () => { S.cols = COLS.map(c => ({ id: c.id, w: c.w, on: c.id !== 'num' })); lsSet('cols', S.cols); renderHead(); renderRows(true); } }]),
        ev.clientX, ev.clientY);
    };
}
function cell(c, m) {
    switch (c.id) {
        case 'chk': return `<div class="c-chk" data-act="chk" title="Select"><input type="checkbox" tabindex="-1" aria-label="Select this message" ${S.sel.has(keyOf(m)) ? 'checked' : ''}></div>`;
        case 'flag': return `<div class="c-flag" data-act="star" title="Star"><span class="star ${m.flagged ? 'on' : ''}">${m.flagged ? '★' : '☆'}</span></div>`;
        case 'read': return `<div class="c-read" data-act="read" title="${m.seen ? 'Mark as unread' : 'Mark as read'}"><span class="dot ${m.seen ? '' : 'on'}"></span></div>`;
        case 'subject': return `<div class="c-subject" title="${esc(m.subject)}">${esc(m.subject || '(no subject)')}${S.search ? `<span class="fl-name">${esc(prettyFolder(m.folder))}</span>` : ''}</div>`;
        case 'corr': {
            const sp = (S.folders.find(f => f.name === m.folder) || {}).special;
            const sent = sp === 'sent' || sp === 'drafts';
            const v = sent ? m.to : m.from;
            const shown = m.bbsType === 'Bulletin' && sent ? v : labelCall(v);
            return `<div class="c-corr" title="${esc(v)}">${m.winlink ? '<span class="wl-dot" title="Through Winlink">✉</span>' : ''}${esc(shown)}</div>`;
        }
        case 'date': return `<div class="c-date" title="${esc(fmtDate(m.date, true))}">${esc(fmtDate(m.date))}</div>`;
        case 'size': return `<div class="c-size">${fmtSize(m.size || 0)}</div>`;
        case 'num': return `<div class="c-num">${m.bbsNumber != null ? '#' + m.bbsNumber : ''}</div>`;
        case 'type': return `<div class="c-type">${m.winlink ? '<span class="wl" title="Through Winlink">Winlink</span> ' : ''}${esc(m.bbsType === 'Bulletin' ? 'Bulletin' + (m.to ? ' ' + m.to : '') : (m.winlink ? '' : m.bbsType || ''))}</div>`;
    }
    return '<div></div>';
}
const keyOf = m => m.folder + '::' + m.uid;
function renderRows(keepScroll) {
    const box = $('rows');
    const st = box.scrollTop;
    renderHead();
    const name = S.search ? `Search: “${S.search.q}”` : prettyFolder(S.folder);
    $('lhName').textContent = name;
    $('lhCount').textContent = S.loading && !S.msgs.length ? 'Loading…'
        : S.search ? `${S.total} found${S.total > S.msgs.length ? ` (showing ${S.msgs.length})` : ''}`
        : `${S.total} message${S.total === 1 ? '' : 's'}` + (S.qf.show && (S.qf.unseen || S.qf.flagged || S.qf.text) ? ' match the filter' : '');
    $('qfCount').textContent = S.qf.show && (S.qf.unseen || S.qf.flagged || S.qf.text) ? `${S.total} match` : '';
    if (S.error) { box.innerHTML = `<div id="listEmpty">⚠ ${esc(S.error)}</div>`; return; }
    if (!S.msgs.length) { box.innerHTML = `<div id="listEmpty">${S.loading ? 'Loading…' : 'No messages'}</div>`; return; }
    const tpl = colTemplate();
    const cols = S.cols.filter(c => c.on);
    let html = '';
    for (const m of S.msgs) {
        const k = keyOf(m);
        html += `<div class="row ${m.seen ? '' : 'unread'} ${S.sel.has(k) ? 'sel' : ''} ${S.cur === k ? 'cur' : ''}" draggable="true"
                     data-k="${esc(k)}" style="grid-template-columns:${tpl}">${cols.map(c => cell(c, m)).join('')}</div>`;
    }
    if (S.msgs.length < S.total && !S.search) html += `<div id="listMore">${S.loading ? 'Loading…' : 'Scroll for more'}</div>`;
    box.innerHTML = html;
    if (keepScroll) box.scrollTop = st;
    paintChecks();
}
// selection changes only touch classes, so a double-click's second click
// still lands on the same row element
function paintRow(m) {
    const el = document.querySelector(`#rows .row[data-k="${CSS.escape(keyOf(m))}"]`);
    if (!el) return;
    el.classList.toggle('unread', !m.seen);
    const dot = el.querySelector('.dot'); if (dot) dot.classList.toggle('on', !m.seen);
    const st = el.querySelector('.star'); if (st) { st.classList.toggle('on', !!m.flagged); st.textContent = m.flagged ? '★' : '☆'; }
}
function paintSel() {
    document.querySelectorAll('#rows .row').forEach(r => {
        r.classList.toggle('sel', S.sel.has(r.dataset.k));
        r.classList.toggle('cur', S.cur === r.dataset.k);
    });
    paintChecks();
}
// v1.5.101: tick boxes, the select-all box and the toolbar Delete follow the selection
function paintChecks() {
    document.querySelectorAll('#rows .row').forEach(r => {
        const c = r.querySelector('.c-chk input'); if (c) c.checked = S.sel.has(r.dataset.k);
    });
    const all = $('chkAll');
    if (all) {
        const n = S.msgs.filter(m => S.sel.has(keyOf(m))).length;
        all.checked = n > 0 && n === S.msgs.length;
        all.indeterminate = n > 0 && n < S.msgs.length;
    }
    const b = $('btnDel');
    if (b) {
        const n = S.sel.size;
        b.disabled = !n;
        b.querySelector('.lbl').textContent = n > 1 ? `Delete (${n})` : 'Delete';
        b.title = n ? `Delete the ${n === 1 ? 'selected message' : n + ' selected messages'} (Del)` : 'Tick messages to delete them';
    }
}
function selectAllToggle() {
    const n = S.msgs.filter(m => S.sel.has(keyOf(m))).length;
    if (n === S.msgs.length && n) { S.sel = new Set(); S.cur = null; }
    else { S.sel = new Set(S.msgs.map(keyOf)); S.cur = S.cur || (S.msgs[0] && keyOf(S.msgs[0])); }
    S.anchor = S.cur; paintSel(); showCurrent();
}
function msgByKey(k) { return S.msgs.find(m => keyOf(m) === k); }
function selectedMsgs() { return S.msgs.filter(m => S.sel.has(keyOf(m))); }

function wireList() {
    const box = $('rows');
    box.addEventListener('scroll', () => {
        if (!S.search && !S.loading && S.msgs.length < S.total && box.scrollTop + box.clientHeight > box.scrollHeight - 300) loadList(true);
    });
    box.addEventListener('mousedown', ev => {
        const r = ev.target.closest('.row');
        if (!r) return;
        S.focus = 'list'; applyFocus();
        const k = r.dataset.k;
        const act = ev.target.closest('[data-act]');
        if (act && ev.button === 0) {
            const m = msgByKey(k);
            if (act.dataset.act === 'chk') {          // v1.5.101: tick box = Ctrl+click (Shift+tick = range)
                if (ev.shiftKey && S.anchor) { rangeTo(k); ev.preventDefault(); return; }
                S.sel.has(k) ? S.sel.delete(k) : S.sel.add(k);
                S.anchor = k; S.cur = S.sel.has(k) ? k : (S.sel.size ? [...S.sel][S.sel.size - 1] : null);
                paintSel(); showCurrent(); ev.preventDefault(); return;
            }
            if (act.dataset.act === 'star') setFlag([m], !m.flagged);
            else setSeen([m], !m.seen);
            ev.preventDefault();
            return;
        }
        if (ev.button === 2) { if (!S.sel.has(k)) { S.sel = new Set([k]); S.anchor = k; S.cur = k; paintSel(); showCurrent(); } return; }
        if (ev.ctrlKey || ev.metaKey) { S.sel.has(k) ? S.sel.delete(k) : S.sel.add(k); S.anchor = k; S.cur = k; paintSel(); showCurrent(); return; }
        if (ev.shiftKey && S.anchor) { rangeTo(k); return; }
        if (S.sel.has(k) && S.sel.size > 1) { S.pendingSingle = k; return; }   // maybe a drag of the selection
        S.sel = new Set([k]); S.anchor = k; S.cur = k; paintSel(); showCurrent();
    });
    box.addEventListener('click', ev => {
        const r = ev.target.closest('.row');
        if (r && S.pendingSingle === r.dataset.k && !ev.ctrlKey && !ev.shiftKey) {
            S.sel = new Set([S.pendingSingle]); S.anchor = S.cur = S.pendingSingle; paintSel(); showCurrent();
        }
        S.pendingSingle = null;
    });
    box.addEventListener('dblclick', ev => {
        const r = ev.target.closest('.row');
        if (r && !ev.target.closest('[data-act]')) openInTab(msgByKey(r.dataset.k));
    });
    box.addEventListener('contextmenu', ev => {
        const r = ev.target.closest('.row');
        if (!r) return;
        ev.preventDefault();
        messageMenu(ev.clientX, ev.clientY);
    });
    box.addEventListener('dragstart', ev => {
        const r = ev.target.closest('.row');
        if (!r) return;
        if (!S.sel.has(r.dataset.k)) { S.sel = new Set([r.dataset.k]); S.cur = r.dataset.k; paintSel(); }
        S.dragging = true;
        S.pendingSingle = null;
        ev.dataTransfer.effectAllowed = 'copyMove';
        ev.dataTransfer.setData('text/plain', `${S.sel.size} message(s)`);
        const g = document.createElement('div');
        g.className = 'drag-ghost'; g.textContent = S.sel.size === 1 ? (selectedMsgs()[0] || {}).subject || '1 message' : `${S.sel.size} messages`;
        document.body.appendChild(g);
        ev.dataTransfer.setDragImage(g, 10, 10);
        setTimeout(() => g.remove(), 0);
    });
    box.addEventListener('dragend', () => { S.dragging = false; document.querySelectorAll('.fld.drop').forEach(e => e.classList.remove('drop')); });
}
function rangeTo(k) {
    const a = S.msgs.findIndex(m => keyOf(m) === S.anchor), b = S.msgs.findIndex(m => keyOf(m) === k);
    if (a === -1 || b === -1) return;
    S.sel = new Set(S.msgs.slice(Math.min(a, b), Math.max(a, b) + 1).map(keyOf));
    S.cur = k; paintSel(); showCurrent();
}
function showCurrent() {
    if (S.sel.size === 1) { const m = msgByKey([...S.sel][0]); if (m) return showMessage(m); }
    if (S.sel.size > 1) return showSelection();
    showMessage(null, '');
}
// v1.5.101: several messages ticked — say how many, and offer what can be done to all of them
function showSelection() {
    ++showSeq; S.shown = null;
    const ms = selectedMsgs(), n = ms.length, unread = ms.filter(m => !m.seen).length;
    const pane = $('msgPane');
    pane.innerHTML = `<div class="mv-empty sel-acts"><div class="sel-n">${n} messages selected</div>
        <div class="sel-btns">
          <button class="tbtn danger" data-sa="del">🗑 Delete ${n}</button>
          <button class="tbtn" data-sa="read" ${unread ? '' : 'disabled'}>Mark read</button>
          <button class="tbtn" data-sa="unread" ${unread === n ? 'disabled' : ''}>Mark unread</button>
          <button class="tbtn" data-sa="star">★ Star</button>
          <button class="tbtn" data-sa="none">Clear selection</button>
        </div>
        <div class="k">Tick more with the boxes on the left, or use the box at the top of the list to select every message shown.${ms.some(m => isTrash(m.folder)) ? ' Messages already in Trash are deleted for good.' : ' Deleted messages go to Trash.'}</div></div>`;
    pane.querySelectorAll('[data-sa]').forEach(b => b.onclick = () => {
        const a = b.dataset.sa, cur = selectedMsgs();
        if (a === 'del') del(cur);
        else if (a === 'read') setSeen(cur, true);
        else if (a === 'unread') setSeen(cur, false);
        else if (a === 'star') setFlag(cur, true);
        else { S.sel = new Set(); S.cur = null; paintSel(); showCurrent(); }
        if (a === 'read' || a === 'unread' || a === 'star') setTimeout(showSelection, 300);
    });
}
function scrollCurIntoView() {
    const el = document.querySelector(`#rows .row[data-k="${CSS.escape(S.cur)}"]`);
    if (el) el.scrollIntoView({ block: 'nearest' });
}
function moveCursor(delta, extend) {
    if (!S.msgs.length) return;
    let i = S.msgs.findIndex(m => keyOf(m) === S.cur);
    i = i === -1 ? 0 : Math.max(0, Math.min(S.msgs.length - 1, i + delta));
    const k = keyOf(S.msgs[i]);
    if (extend && S.anchor) { rangeTo(k); scrollCurIntoView(); return; }
    S.sel = new Set([k]); S.anchor = k; S.cur = k;
    paintSel(); scrollCurIntoView(); showCurrent();
    if (!S.search && i > S.msgs.length - 20 && S.msgs.length < S.total) loadList(true);
}
function nextUnread() {
    const i = S.msgs.findIndex(m => keyOf(m) === S.cur);
    const j = S.msgs.findIndex((m, x) => x > i && !m.seen);
    if (j === -1) return toast('No more unread messages in this folder');
    const k = keyOf(S.msgs[j]); S.sel = new Set([k]); S.anchor = S.cur = k; paintSel(); scrollCurIntoView(); showCurrent();
}

function messageMenu(x, y) {
    const ms = selectedMsgs();
    if (!ms.length) return;
    const one = ms.length === 1 ? ms[0] : null;
    const inTrash = ms.every(m => isTrash(m.folder));
    const targets = S.folders.filter(f => f.selectable);
    const folderItems = kind => targets.filter(f => kind === 'copy' || !ms.every(m => m.folder === f.name))
        .map(f => ({ label: prettyFolder(f.name), act: () => moveTo(f.name, kind) }));
    menu([
        { label: 'Open Message in New Tab', key: 'Enter', off: !one, act: () => openInTab(one) },
        '-',
        { label: 'Reply', key: 'Ctrl+R', off: !one, act: () => compose('reply', one) },
        { label: 'Forward', key: 'Ctrl+L', off: !one, act: () => compose('forward', one) },
        '-',
        { label: 'Mark', sub: [
            { label: 'As Read', key: 'M', act: () => setSeen(ms, true) },
            { label: 'As Unread', key: 'M', act: () => setSeen(ms, false) },
            { label: 'Folder Read', act: () => markFolderRead(S.folder), off: !!S.search },
            '-',
            { label: 'Starred', key: 'S', act: () => setFlag(ms, true) },
            { label: 'Not Starred', act: () => setFlag(ms, false) },
        ] },
        { label: 'Move To', sub: folderItems('move') },
        { label: 'Copy To', sub: folderItems('copy') },
        { label: 'Create Filter From Message…', off: !one, act: () => filterFromMessage(one) },
        '-',
        inTrash ? { label: 'Delete for good + kill on BBS', key: 'Del', danger: true, act: () => del(ms) }
                : { label: 'Delete', key: 'Del', act: () => del(ms) },
    ], x, y);
}

// ── actions on messages ────────────────────────────────────────
function byFolder(ms) {
    const g = {};
    for (const m of ms) (g[m.folder] = g[m.folder] || []).push(m);
    return g;
}
async function setSeen(ms, seen) {
    const todo = ms.filter(m => m.seen !== seen);
    if (!todo.length) return;
    for (const [folder, list] of Object.entries(byFolder(todo))) {
        const d = await post({ action: 'msSeen', folder, uids: list.map(m => m.uid), seen });
        if (!d.success) return toast(d.error || 'Failed', 'err');
        list.forEach(m => { m.seen = seen; paintRow(m); });
        bumpUnseen(folder, seen ? -list.length : list.length);
    }
}
async function setFlag(ms, flagged) {
    for (const [folder, list] of Object.entries(byFolder(ms))) {
        const d = await post({ action: 'msFlag', folder, uids: list.map(m => m.uid), flagged });
        if (!d.success) return toast(d.error || 'Failed', 'err');
        list.forEach(m => { m.flagged = flagged; paintRow(m); });
    }
    if (S.shown && ms.includes(S.shown.m)) renderMessage($('msgPane'), S.shown.m, S.shown.body);
}
function bumpUnseen(folder, d) {
    const f = S.folders.find(x => x.name === folder);
    if (f) f.unseen = Math.max(0, (f.unseen || 0) + d);
    if (folder === S.folder) S.unseen = Math.max(0, S.unseen + d);
    renderFolders(); status();
}
function dropFromList(ms) {
    const ks = new Set(ms.map(keyOf));
    const i = S.msgs.findIndex(m => ks.has(keyOf(m)));
    S.msgs = S.msgs.filter(m => !ks.has(keyOf(m)));
    S.total = Math.max(0, S.total - ms.length);
    // like Thunderbird: select the next message
    const next = S.msgs[Math.min(i, S.msgs.length - 1)];
    S.sel = next ? new Set([keyOf(next)]) : new Set();
    S.cur = S.anchor = next ? keyOf(next) : null;
    renderRows(true);
    showCurrent();
    if (S.msgs.length < Math.min(50, S.total) && !S.search) loadList(true);
}
async function moveTo(to, kind) {
    const ms = selectedMsgs().filter(m => kind === 'copy' || m.folder !== to);
    if (!ms.length) return;
    for (const [folder, list] of Object.entries(byFolder(ms))) {
        const d = await post({ action: kind === 'copy' ? 'msCopy' : 'msMove', folder, uids: list.map(m => m.uid), to });
        if (!d.success) return toast(d.error || 'Failed', 'err');
    }
    toast(`${kind === 'copy' ? 'Copied' : 'Moved'} ${ms.length} to ${prettyFolder(to)}` + (isTrash(to) ? ' (killed on the BBS when Trash is emptied)' : ''), 'ok');
    if (kind !== 'copy') dropFromList(ms);
    loadFolders();
}
async function del(ms, forceForGood) {
    if (!ms || !ms.length) return;
    const inTrash = ms.filter(m => isTrash(m.folder));
    const others = ms.filter(m => !isTrash(m.folder));
    if (inTrash.length || forceForGood) {
        const list = forceForGood ? ms : inTrash;
        if (!confirm(`Delete ${list.length} message${list.length === 1 ? '' : 's'} for good?\n\nThey will also be KILLED ON THE BBS (within about 5 minutes) and disappear from Thunderbird.\nThis can't be undone.`)) return;
        if (forceForGood && others.length) {
            // Shift+Delete outside Trash: via Trash, then for good
            const trash = (S.folders.find(f => f.special === 'trash') || { name: 'Trash' }).name;
            toast('Shift+Delete outside Trash moves them to Trash first; empty Trash (or Delete there) to kill them on the BBS.', '');
            return moveToFolderList(others, trash);
        }
        for (const [folder, l] of Object.entries(byFolder(list))) {
            const d = await post({ action: 'msDelete', folder, uids: l.map(m => m.uid) });
            if (!d.success) return toast(d.error || 'Failed', 'err');
            toast(`Deleted ${l.length} for good` + killNote(d.kills), 'ok');
        }
        dropFromList(list);
        loadFolders();
        return;
    }
    for (const [folder, l] of Object.entries(byFolder(others))) {
        const d = await post({ action: 'msTrash', folder, uids: l.map(m => m.uid) });
        if (!d.success) return toast(d.error || 'Failed', 'err');
    }
    dropFromList(others);
    loadFolders();
}
async function moveToFolderList(ms, to) {
    S.sel = new Set(ms.map(keyOf));
    return moveTo(to, 'move');
}

// ── message pane / message tabs ────────────────────────────────
let showSeq = 0;
async function showMessage(m, note) {
    const pane = $('msgPane');
    const seq = ++showSeq;
    if (!m) { S.shown = null; pane.innerHTML = `<div class="mv-empty">${esc(note || '')}</div>`; return; }
    pane.innerHTML = `<div class="mv-empty">Loading…</div>`;
    const d = await get({ action: 'msMessage', folder: m.folder, uid: m.uid });
    if (seq !== showSeq) return;
    if (!d.success) { pane.innerHTML = `<div class="mv-empty">⚠ ${esc(d.error || 'Could not open it')}</div>`; return; }
    if (!m.seen) { m.seen = true; bumpUnseen(m.folder, -1); paintRow(m); }
    S.shown = { folder: m.folder, uid: m.uid, m, body: d.message.body || '' };
    renderMessage(pane, m, d.message.body || '');
}
// v1.5.79: where this BBS forwarded the message, from LinBPQ's BBS log
// (bbs-forwards.php for_bid: the last 7 days of logs). Hidden when none.
const FWD_CACHE = new Map();
async function showForwards(el, bid) {
    let d = FWD_CACHE.get(bid);
    if (!d || Date.now() - d.at > 60000) {
        try {
            const r = await fetch('/bbs-forwards.php?action=for_bid&bid=' + encodeURIComponent(bid), { cache: 'no-store' });
            d = { at: Date.now(), j: await r.json() };
        } catch (e) { return; }
        FWD_CACHE.set(bid, d);
    }
    if (!el.isConnected || !d.j.success || !(d.j.events || []).length) return;
    const by = {};
    for (const e of d.j.events) {
        const k = e.dest_bbs || '?';
        if (!by[k] || e.time > by[k].time) by[k] = e;
    }
    const list = Object.values(by).sort((a, b) => a.time - b.time);
    const when = t => new Date(t * 1000).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    el.innerHTML = '↗ Forwarded to ' + list.map(e =>
        `<b title="${esc(e.type === 'FC' ? 'Compressed forward (B2)' : 'Forward proposal')}${e.size ? ', ' + esc(fmtSize(e.size)) : ''}">${esc(e.dest_bbs)}</b> <span class="t">${esc(when(e.time))}</span>`).join(', ');
    el.hidden = false;
}

function renderMessage(host, m, body) {
    const inTrash = isTrash(m.folder);
    host.innerHTML = `<div class="mv">
      <div class="mv-head">
        <div class="mv-top">
          <div class="mv-from"><span class="who" data-a="who" title="Click for the address book">${esc(labelCall(m.from) || '(unknown)')}</span>${contactFor(m.from) && contactFor(m.from).starred ? ' <span class="star on">★</span>' : ''}</div>
          <div class="mv-btns">
            <button class="tbtn" data-a="reply" title="Reply (Ctrl+R)">↩ Reply</button>
            <button class="tbtn" data-a="forward" title="Forward (Ctrl+L)">↪ Forward</button>
            <button class="tbtn" data-a="star" title="Star (S)">${m.flagged ? '★' : '☆'}</button>
            <button class="tbtn" data-a="del" title="${inTrash ? 'Delete for good + kill on BBS' : 'Delete (to Trash)'}">🗑 ${inTrash ? 'Delete + kill' : 'Delete'}</button>
            <button class="tbtn" data-a="more" title="More">⋯</button>
          </div>
        </div>
        <div class="mv-subj">${esc(m.subject || '(no subject)')}</div>
        <div class="mv-meta"><span>To <b>${esc(m.to || '')}</b></span><span>${esc(fmtDate(m.date, true))}</span>
          <span>${m.winlink ? '<span class="wl">Winlink</span> ' : ''}${esc(m.bbsType || '')}${m.bbsNumber != null ? ' · BBS #' + m.bbsNumber : ''}${m.bid ? ' · BID ' + esc(m.bid) : ''}</span>
          <span>📁 ${esc(prettyFolder(m.folder))}</span></div>
        <div class="mv-fwd" hidden></div>
      </div>
      <pre class="mv-body"></pre></div>`;
    host.querySelector('.mv-body').textContent = body || '(no text)';
    if (m.bid) showForwards(host.querySelector('.mv-fwd'), m.bid);   // v1.5.79
    host.querySelectorAll('[data-a]').forEach(b => b.addEventListener('click', ev => {
        const a = b.dataset.a;
        if (a === 'who') {
            const r = b.getBoundingClientRect();
            const k = contactFor(m.from);
            return menu([
                k ? { label: 'Edit Contact…', act: () => openAddressBook(k.id, true) }
                  : { label: 'Add to Address Book…', act: () => openAddressBook(null, true, { callsign: String(m.from || '').replace(/-\d+$/, ''), book: 'personal' }) },
                k ? { label: 'Show in Address Book', act: () => openAddressBook(k.id) } : null,
                { label: 'Write Message to ' + (nameOf(k) || m.from), act: () => compose('new', null, null, k ? `${nameOf(k) ? nameOf(k) + ' <' + (k.address || k.callsign) + '>' : k.address || k.callsign}` : m.from) },
                { label: 'Search Messages from ' + m.from, act: () => { switchTab('mail'); $('tbSearch').value = m.from; $('tbSearch').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' })); } },
                { label: 'Copy Callsign', act: () => navigator.clipboard && navigator.clipboard.writeText(m.from) },
            ].filter(Boolean), r.left, r.bottom);
        }
        if (a === 'reply') compose('reply', m, body);
        else if (a === 'forward') compose('forward', m, body);
        else if (a === 'star') setFlag([m], !m.flagged);
        else if (a === 'del') { if (S.msgs.includes(m)) { S.sel = new Set([keyOf(m)]); } del([m]); if (S.tab !== 'mail') closeTab(S.tab); }
        else if (a === 'more') {
            const r = b.getBoundingClientRect();
            menu([
                { label: 'Open Message in New Tab', off: S.tab !== 'mail', act: () => openInTab(m) },
                { label: m.seen ? 'Mark as Unread' : 'Mark as Read', act: () => setSeen([m], !m.seen) },
                { label: 'Move To', sub: S.folders.filter(f => f.selectable && f.name !== m.folder).map(f => ({ label: prettyFolder(f.name), act: () => { S.sel = new Set([keyOf(m)]); moveTo(f.name, 'move'); } })) },
                { label: 'Copy To', sub: S.folders.filter(f => f.selectable && f.name !== m.folder).map(f => ({ label: prettyFolder(f.name), act: () => { S.sel = new Set([keyOf(m)]); moveTo(f.name, 'copy'); } })) },
                '-',
                { label: 'Print…', key: 'Ctrl+P', act: () => printMessage(m, body) },
                { label: 'Save As Text…', key: 'Ctrl+S', act: () => saveMessage(m, body) },
                { label: 'Copy text', act: () => navigator.clipboard && navigator.clipboard.writeText(body).then(() => toast('Copied')) },
            ], r.left, r.bottom);
        }
    }));
}
async function openInTab(m) {
    if (!m) return;
    if (isDraftsFolder(m.folder)) return openDraft(m);   // v1.5.78
    const existing = S.tabs.find(t => t.type === 'msg' && t.folder === m.folder && t.uid === m.uid);
    if (existing) return switchTab(existing.id);
    const id = 't' + (++S.tabSeq);
    S.tabs.push({ id, type: 'msg', folder: m.folder, uid: m.uid, title: m.subject || '(no subject)', icon: '✉' });
    const pane = document.createElement('div');
    pane.className = 'tab-pane'; pane.dataset.tab = id;
    pane.innerHTML = '<div class="mv-empty">Loading…</div>';
    $('tbBody').appendChild(pane);
    switchTab(id);
    const d = await get({ action: 'msMessage', folder: m.folder, uid: m.uid });
    if (!d.success) { pane.innerHTML = `<div class="mv-empty">⚠ ${esc(d.error)}</div>`; return; }
    if (!m.seen) { m.seen = true; bumpUnseen(m.folder, -1); paintRow(m); }
    const tt = S.tabs.find(x => x.id === id); if (tt) tt.shown = { m, body: d.message.body || '' };
    renderMessage(pane, m, d.message.body || '');
}

// ── tabs ───────────────────────────────────────────────────────
function renderTabs() {
    $('tbTabs').innerHTML = S.tabs.map(t => `<div class="tb-tab ${S.tab === t.id ? 'active' : ''}" data-t="${t.id}" title="${esc(t.title)}">
        <span>${t.type === 'mail' ? '📥' : t.icon || '✉'}</span><span class="t">${esc(t.title)}</span>
        ${t.type === 'mail' ? '' : `<button class="x" data-x="${t.id}" title="Close (Ctrl+W)">✕</button>`}</div>`).join('');
    $('tbTabs').querySelectorAll('.tb-tab').forEach(el => {
        el.addEventListener('mousedown', ev => {
            if (ev.target.closest('[data-x]')) return;
            if (ev.button === 1 && el.dataset.t !== 'mail') { ev.preventDefault(); return closeTab(el.dataset.t); }
            switchTab(el.dataset.t);
        });
    });
    $('tbTabs').querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', () => closeTab(b.dataset.x)));
}
function switchTab(id) {
    S.tab = id;
    document.querySelectorAll('#tbBody > .tab-pane').forEach(p => p.classList.toggle('active', p.dataset.tab === id));
    const t = S.tabs.find(x => x.id === id);
    ['btnQF', 'tbSearch'].forEach(b => { $(b).disabled = t.type !== 'mail'; });
    renderTabs();
    if (t.type === 'compose') { const f = document.querySelector(`.tab-pane[data-tab="${id}"] [data-focus]`); if (f) f.focus(); }
}
function closeTab(id, opts) {
    const t = S.tabs.find(x => x.id === id);
    if (!t || t.type === 'mail') return;
    // v1.5.78: a message being written is kept in Drafts when its tab closes
    if (t.type === 'compose' && !(opts && opts.discarded) && t.saveDraft && t.changed && t.changed() && t.hasText()) {
        t.saveDraft(true).then(ok => toast(ok ? 'Kept in Drafts' : 'Could not save to Drafts', ok ? 'ok' : 'err'));
    }
    if (t.draftTimer) clearInterval(t.draftTimer);
    if (t.onHide) document.removeEventListener('visibilitychange', t.onHide);
    if (t.cleanup) t.cleanup();
    S.tabs = S.tabs.filter(x => x.id !== id);
    const p = document.querySelector(`.tab-pane[data-tab="${id}"]`);
    if (p) p.remove();
    if (S.tab === id) switchTab('mail'); else renderTabs();
}
function setTabTitle(id, title) { const t = S.tabs.find(x => x.id === id); if (t) { t.title = title; renderTabs(); } }

// ── Write / Reply / Forward (sent over the BBS) ────────────────
async function addressFor(call) {
    if (!call) return '';
    const c = String(call).toUpperCase().replace(/-\d+$/, '');
    const k = contactFor(c);
    if (k && k.address) return k.address;
    const hit = S.addresses.find(a => String(a).toUpperCase().split('@')[0] === c && a.includes('@'));
    return hit || c;
}
// v1.5.72: the address book behind names, To: suggestions and lists
function contactFor(call) {
    const c = String(call || '').toUpperCase().split('@')[0].replace(/-\d+$/, '');
    if (!c) return null;
    return S.contacts.find(k => k.kind === 'contact' && k.book === 'personal' && k.callsign.replace(/-\d+$/, '') === c)
        || S.contacts.find(k => k.kind === 'contact' && k.callsign.replace(/-\d+$/, '') === c) || null;
}
function nameOf(k) {
    if (!k) return '';
    return k.display_name || [k.first_name, k.last_name].filter(Boolean).join(' ') || k.nickname || '';
}
function labelCall(call) {
    if (!S.prefs.names) return call || '';
    const k = contactFor(call);
    const n = nameOf(k);
    return n ? `${n} (${call})` : (call || '');
}
// "Glenn (N3MEL)", "N3MEL", a list's name, "N3MEL@N3MEL.#..." -> addresses
// v1.5.77: RMS:user@domain → rms:user@domain (BPQ sends it through Winlink),
// SMTP:… stays, CALL@winlink.org stays; anything else → '' (a BBS call)
function netReplyAddr(a) {
    a = String(a || '').trim();
    let m = a.match(/^(RMS|SMTP):\s*(\S+@\S+)$/i);
    if (m) return m[1].toLowerCase() + ':' + m[2];
    if (/^[A-Z0-9\/-]+@winlink\.org$/i.test(a)) return a.split('@')[0].toUpperCase() + '@winlink.org';
    return '';
}
function expandRecipients(text) {
    const out = [], bad = [];
    for (let t of String(text).split(/[,;]+/)) {
        t = t.trim();
        if (!t) continue;
        const inner = t.match(/<([^>]+)>\s*$/) || t.match(/\(([^)]+)\)\s*$/);
        const lst = S.contacts.find(k => k.kind === 'list' && k.display_name.toLowerCase() === t.replace(/^list:\s*/i, '').toLowerCase());
        if (lst) {
            for (const id of lst.members) { const k = S.contacts.find(x => x.id === id); if (k) out.push(k.address || k.callsign); }
            continue;
        }
        let a = inner ? inner[1].trim() : t;
        // v1.5.77: internet mail through Winlink (rms:user@domain) or BPQ's
        // own gateway (smtp:user@domain) keeps its case
        const net = a.match(/^(rms|smtp):\s*([^\s@,;]+@[^\s@,;]+\.[^\s@,;]+)$/i);
        if (net) { out.push(net[1].toLowerCase() + ':' + net[2]); continue; }
        const byName = S.contacts.find(k => k.kind === 'contact' && (nameOf(k).toLowerCase() === t.toLowerCase() || (k.nickname && k.nickname.toLowerCase() === t.toLowerCase())));
        if (byName) { out.push(byName.address || byName.callsign); continue; }
        a = a.toUpperCase().replace(/\s+/g, '');
        if (!/^[A-Z0-9\/-]+(@[A-Z0-9.#-]+)?$/.test(a)) { bad.push(t); continue; }
        if (!a.includes('@')) { const k = contactFor(a); if (k && k.address) a = k.address; }
        out.push(a);
    }
    return { list: [...new Set(out)], bad };
}
// Thunderbird-like suggestions under the To field (the part after the last comma)
const WP_CACHE = {};   // v1.5.96: White Pages answers, by what was typed
function attachSuggest(input) {
    const box = document.createElement('div');
    box.className = 'cm ac'; box.style.display = 'none';
    document.body.appendChild(box);
    let items = [], idx = -1;
    const hide = () => { box.style.display = 'none'; idx = -1; };
    const token = () => { const v = input.value; const i = Math.max(v.lastIndexOf(','), v.lastIndexOf(';')); return { pre: v.slice(0, i + 1), q: v.slice(i + 1).trim() }; };
    const pick = it => {
        const { pre } = token();
        input.value = (pre ? pre.trim() + ' ' : '') + it.value + ', ';
        hide(); input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    };
    // v1.5.89: callsigns that START with what is typed come first; the
    // routing part of an address (after @: .#REGION.ST.USA.NOAM) no longer
    // matches single letters; an empty box lists the most used contacts.
    const rankOf = (k, ql) => {
        if (k.kind === 'list') { const n = k.display_name.toLowerCase(); return n.split(/\s+/).some(w => w.startsWith(ql)) ? 1 : (ql.length >= 2 && n.includes(ql)) ? 4 : 9; }
        const call = String(k.callsign || '').toLowerCase();
        const words = [nameOf(k), k.nickname].join(' ').toLowerCase().split(/[\s,.'-]+/).filter(Boolean);
        const addr = String(k.address || '').toLowerCase(), local = addr.split('@')[0];
        const email = String(k.email || '').toLowerCase();
        if (call.startsWith(ql)) return 0;
        if (words.some(w => w.startsWith(ql))) return 1;
        if (local && local.startsWith(ql)) return 2;
        if (email.startsWith(ql)) return 2;
        if (ql.length >= 2 && (call.includes(ql) || [nameOf(k), k.nickname].join(' ').toLowerCase().includes(ql))) return 3;
        if (/[@.#]/.test(ql) && (addr.includes(ql) || email.includes(ql))) return 4;
        return 9;
    };
    const row = k => k.kind === 'list'
        ? { value: k.display_name, html: `👥 <b>${esc(k.display_name)}</b> <span class="k">list · ${k.members.length}</span>` }
        : { value: nameOf(k) ? `${nameOf(k)} <${k.address || k.callsign}>` : (k.address || k.callsign),
            html: `<b class="mono">${esc(k.callsign)}</b>${nameOf(k) ? ' ' + esc(nameOf(k)) : ''} <span class="k">${esc(k.address || k.callsign)}</span>` };
    // v1.5.96: LinBPQ's White Pages (every station the BBS has heard of, with its
    // home BBS) after the contacts, from the second letter of a callsign; and a
    // note when nothing matches instead of the list vanishing.
    let wpTimer = null, wpSeq = 0;
    const wpKey = q => (q.length >= 2 && /^[A-Za-z0-9\/]+$/.test(q)) ? q.toUpperCase() : '';
    const wpFetch = key => {
        clearTimeout(wpTimer);
        const seq = ++wpSeq;
        wpTimer = setTimeout(async () => {
            let d = null;
            try { d = await get({ action: 'wp', q: key }); } catch (e) {}
            WP_CACHE[key] = d && d.success ? { entries: d.entries || [], note: d.note || '' } : { entries: [], note: 'White Pages lookup failed' };
            if (seq === wpSeq && document.activeElement === input && wpKey(token().q) === key) show();
        }, 250);
    };
    const show = () => {
        const { q } = token();
        const ql = q.toLowerCase();
        const score = k => (k.starred ? 2 : 0) + (k.book === 'personal' ? 1 : 0) + Math.min(5, (k.use_count || 0) / 5);
        if (!ql) {
            // Empty box (or just after a comma): the most used contacts
            items = S.contacts.filter(k => k.kind === 'contact' && (k.starred || k.use_count > 0 || k.book === 'personal'))
                .sort((a, b) => score(b) - score(a) || String(a.callsign).localeCompare(String(b.callsign))).slice(0, 8).map(row);
        } else {
            items = S.contacts.map(k => ({ k, r: rankOf(k, ql) })).filter(x => x.r < 9)
                .sort((a, b) => a.r - b.r || score(b.k) - score(a.k) || String(a.k.callsign || a.k.display_name).localeCompare(String(b.k.callsign || b.k.display_name)))
                .slice(0, 8).map(x => row(x.k));
            const extra = S.addresses.map(a => String(a)).filter(a => {
                const al = a.toLowerCase(), local = al.split('@')[0];
                return (local.startsWith(ql) || (ql.length >= 2 && local.includes(ql)) || (/[@.#]/.test(ql) && al.includes(ql)))
                    && !items.some(i => i.value.toUpperCase().includes(a.toUpperCase()));
            }).sort((a, b) => (b.toLowerCase().startsWith(ql)) - (a.toLowerCase().startsWith(ql)) || a.localeCompare(b));
            for (const a of extra) { if (items.length >= 10) break; items.push({ value: a, html: `${esc(a)} <span class="k">Email Client</span>` }); }
        }
        // v1.5.96: White Pages
        const key = wpKey(q);
        const wp = key ? WP_CACHE[key] : null;
        if (key && !wp) wpFetch(key);
        if (wp) {
            const have = items.map(i => i.value.toUpperCase());
            for (const e of wp.entries) {
                if (items.length >= 12) break;
                if (have.some(v => v.includes(String(e.fullAddress).toUpperCase()) || v.split('@')[0].replace(/^.*</, '') === e.callsign)) continue;
                items.push({ value: e.fullAddress, html: `<b class="mono">${esc(e.callsign)}</b>${e.displayName ? ' ' + esc(e.displayName) : ''} <span class="k">${esc(e.fullAddress)}</span> <span class="wp-tag" title="From LinBPQ's White Pages">WP</span>` });
            }
        }
        let note = '';
        if (key && !wp) note = 'Looking in the White Pages…';
        else if (!items.length && q && !/[@<]/.test(q)) note = `No contact${key ? ' or White Pages entry' : ''} starts with <b>${esc(q.toUpperCase())}</b>. Type the full address, e.g. <span class="mono">CALL@BBS.#AREA.ST.USA.NOAM</span>` + (wp && wp.note ? `<br>${esc(wp.note)}` : '');
        if (!items.length && !note) return hide();
        if (!box.isConnected) document.body.appendChild(box);   // v1.5.96
        idx = items.length ? 0 : -1;
        box.innerHTML = items.map((it, i) => `<div class="cm-i ${i === idx ? 'open' : ''}" data-i="${i}">${it.html}</div>`).join('')
            + (note ? `<div class="k" style="padding:6px 10px;font-size:12px">${note}</div>` : '');
        const r = input.getBoundingClientRect();
        Object.assign(box.style, { display: 'block', left: r.left + 'px', top: r.bottom + 2 + 'px', minWidth: Math.min(r.width, 480) + 'px' });
        box.querySelectorAll('[data-i]').forEach(el => el.addEventListener('mousedown', ev => { ev.preventDefault(); pick(items[+el.dataset.i]); }));
    };
    input.addEventListener('input', show);
    input.addEventListener('focus', show);
    input.addEventListener('click', show);
    input.addEventListener('blur', () => setTimeout(hide, 150));
    input.addEventListener('keydown', ev => {
        if (box.style.display === 'none') return;
        if (!items.length) { if (ev.key === 'Escape') { ev.stopPropagation(); hide(); } return; }   // just the note showing
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            ev.preventDefault(); idx = (idx + (ev.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            box.querySelectorAll('[data-i]').forEach((el, i) => el.classList.toggle('open', i === idx));
        } else if ((ev.key === 'Enter' || ev.key === 'Tab') && idx >= 0 && !ev.ctrlKey) { ev.preventDefault(); pick(items[idx]); }
        else if (ev.key === 'Escape') { ev.stopPropagation(); hide(); }
    });
    return () => box.remove();
}
// v1.5.78: plain text for the BBS. BBS mail is 7-bit text in practice:
// curly quotes, long dashes, accents and emoji (common when text is pasted
// from Word or a web page) can arrive on other BBSes and over packet as junk.
const PLAIN = { '\u2018': "'", '\u2019': "'", '\u201A': "'", '\u201B': "'", '\u2032': "'", '\u201C': '"', '\u201D': '"', '\u201E': '"', '\u2033': '"',
    '\u2013': '-', '\u2014': '--', '\u2015': '--', '\u2212': '-', '\u2010': '-', '\u2011': '-', '\u2026': '...', '\u00A0': ' ', '\u2009': ' ', '\u200A': ' ', '\u202F': ' ',
    '\u200B': '', '\uFEFF': '', '\u2022': '*', '\u00B7': '*', '\u00D7': 'x', '\u00F7': '/', '\u00B0': ' deg', '\u00BD': '1/2', '\u00BC': '1/4', '\u00BE': '3/4',
    '\u2122': '(TM)', '\u00AE': '(R)', '\u00A9': '(C)', '\u20AC': 'EUR', '\u00A3': 'GBP', '\u00DF': 'ss', '\u00C6': 'AE', '\u00E6': 'ae', '\u00D8': 'O', '\u00F8': 'o', '\u2192': '->', '\u2190': '<-' };
function oddChars(t) {
    const seen = new Set();
    for (const ch of String(t)) if (!/^[\x20-\x7E\t\r\n]$/.test(ch)) seen.add(ch);
    return [...seen];
}
function toPlain(t) {
    let out = '';
    for (const ch of String(t)) {
        if (/^[\x20-\x7E\t\r\n]$/.test(ch)) { out += ch; continue; }
        if (ch in PLAIN) { out += PLAIN[ch]; continue; }
        const base = ch.normalize('NFKD').replace(/[\u0300-\u036f]/g, '');   // é → e
        out += /^[\x20-\x7E]+$/.test(base) ? base : '?';
    }
    return out;
}
function plainTextDialog(odd) {
    return new Promise(done => {
        const show = odd.slice(0, 24).map(c => {
            const p = toPlain(c);
            const name = c === '\u00A0' ? '(no-break space)' : /\s/.test(c) ? '(odd space)' : c;
            return `<span class="pt-c"><b>${esc(name)}</b> → ${p === '' ? '(removed)' : esc(p)}</span>`;
        }).join('');
        const dlg = document.createElement('div');
        dlg.className = 'tb-modal';
        dlg.innerHTML = `<div class="tb-dlg" style="width:min(560px,94vw)"><div class="tb-dlg-h">Characters other BBSes may garble</div>
          <div style="padding:12px 16px"><div class="k" style="margin-bottom:8px">BBS mail travels as plain text. These may show as junk on other BBSes or over packet (often from text pasted from Word or a web page):</div>
          <div class="pt-list">${show}${odd.length > 24 ? ` <span class="k">and ${odd.length - 24} more</span>` : ''}</div></div>
          <div class="ab-acts" style="justify-content:flex-end;padding:10px 16px;gap:8px"><button class="tbtn" data-y="back">Go back</button><button class="tbtn" data-y="asis">Send as written</button><button class="tbtn primary" data-y="fix">Use plain characters and send</button></div></div>`;
        document.body.appendChild(dlg);
        const end = v => { dlg.remove(); done(v); };
        dlg.querySelectorAll('[data-y]').forEach(b => b.onclick = () => end(b.dataset.y));
        dlg.addEventListener('keydown', ev => { if (ev.key === 'Escape') { ev.stopPropagation(); end('back'); } });
        dlg.querySelector('[data-y="fix"]').focus();
    });
}

// v1.5.78: Drafts
function isDraftsFolder(name) {
    const f = S.folders.find(x => x.name === name);
    return !!f && (f.special === 'drafts' || /^drafts$/i.test(f.name));
}
async function openDraft(m) {
    const open = S.tabs.find(t => t.type === 'compose' && t.draftUid === m.uid);
    if (open) return switchTab(open.id);
    const d = await get({ action: 'msMessage', folder: m.folder, uid: m.uid, peek: 1 });
    if (!d.success) return toast(d.error || 'Could not open the draft', 'err');
    const x = d.message, dr = x.draft || { to: x.to || '', type: 'P' };
    compose('draft', null, null, '', { to: dr.to, type: dr.type, subject: x.subject === '(no subject)' ? '' : x.subject, body: (x.body || '').replace(/\r?\n$/, ''), draftUid: m.uid });
}
function quoteText(body) {
    return String(body || '').replace(/\r\n/g, '\n').split('\n').map(l => '> ' + l).join('\n');
}
async function compose(kind, m, body, preTo, pre) {
    if ((kind === 'reply' || kind === 'forward') && !m) return;
    if (kind !== 'new' && kind !== 'fixed' && kind !== 'draft' && body == null) {
        const d = await get({ action: 'msMessage', folder: m.folder, uid: m.uid, peek: 1 });
        body = d.success ? d.message.body || '' : '';
    }
    const sig = S.signature && S.signature.enabled && S.signature.text && (kind === 'new' || S.signature.replies)
        ? (S.signature.separator ? '\n-- \n' : '\n') + S.signature.text : '';
    let to = '', subject = '', text = '', type = 'P';
    if (kind === 'reply') {
        // v1.5.77: Winlink / internet senders are answered at their own address
        const net = netReplyAddr(m.fromAddr);
        to = net || await addressFor(m.from);
        if (!net && nameOf(contactFor(m.from))) to = `${nameOf(contactFor(m.from))} <${to}>`;
        subject = /^re:/i.test(m.subject || '') ? m.subject : 'Re: ' + (m.subject || '');
        text = `\n${sig}\n\nOn ${fmtDate(m.date, true)}, ${m.from} wrote:\n${quoteText(body)}\n`;
    } else if (kind === 'forward') {
        subject = 'Fwd: ' + (m.subject || '');
        text = `\n${sig}\n\n-------- Forwarded Message --------\nSubject: ${m.subject || ''}\nDate: ${fmtDate(m.date, true)}\nFrom: ${m.from}\nTo: ${m.to}\n\n${body}\n`;
    } else if (kind === 'draft') {
        // v1.5.78: a saved draft, as it was
        to = pre.to || ''; subject = pre.subject || ''; text = pre.body || ''; type = pre.type || 'P';
    } else if (kind === 'fixed') {
        // v1.5.75: a prepared message (Quick Check-In): no signature
        to = pre.to || ''; subject = pre.subject || ''; text = pre.body || ''; type = pre.type || 'P';
    } else {
        text = sig ? '\n' + sig : '';
    }
    const id = 't' + (++S.tabSeq);
    const title = kind === 'reply' ? 'Reply: ' + (m.subject || '') : kind === 'forward' ? 'Forward: ' + (m.subject || '') : kind === 'fixed' ? (pre.title || 'Write') + ': ' + (subject || '(no subject)') : kind === 'draft' ? 'Write: ' + (subject || '(no subject)') : 'Write: (no subject)';
    S.tabs.push({ id, type: 'compose', title, icon: '✎' });
    const pane = document.createElement('div');
    pane.className = 'tab-pane'; pane.dataset.tab = id;
    pane.innerHTML = `<div class="cmp">
      <div class="cmp-bar">
        <button class="tbtn primary" data-a="send" title="Send over the BBS (Ctrl+Enter)">➤ Send</button>
        <span class="note">Sent through the BBS as ${esc((S.user || '').toUpperCase())}. It shows in Sent once the BBS lists it (a few minutes).</span>
        <span class="note draft-note" title="Saved to the Drafts folder as you write"></span>
        <span class="spacer"></span>
        <button class="tbtn" data-a="cancel">Cancel</button>
      </div>
      <div class="cmp-fields">
        <label>From</label><div>${esc((S.user || '').toUpperCase())} (BBS)</div>
        <label>To</label><input data-f="to" autocomplete="off" placeholder="Name, CALL, CALL@BBS.#REGION.ST.USA.NOAM, a list — or for a bulletin WX@ALLUS (commas between several)" ${kind === 'reply' ? '' : 'data-focus'}>
        <label>Type</label><select data-f="type"><option value="P">Personal (SP)</option><option value="B">Bulletin (SB)</option><option value="T">NTS traffic (ST)</option></select>
        <label>Subject</label><input data-f="subject" maxlength="60">
      </div>
      <textarea data-f="body" spellcheck="true" ${kind === 'reply' ? 'data-focus' : ''}></textarea></div>`;
    $('tbBody').appendChild(pane);
    const f = n => pane.querySelector(`[data-f="${n}"]`);
    f('to').value = preTo ? preTo + ', ' : to; f('subject').value = subject; f('body').value = text; f('type').value = type;
    const tab = S.tabs.find(t => t.id === id);
    tab.cleanup = attachSuggest(f('to'));
    // v1.5.78: drafts. Saved to the mail store's Drafts every 20 seconds
    // while it changes, when the page is hidden (an iPad switching apps) and
    // when the tab closes; removed once sent or discarded.
    tab.draftUid = (pre && pre.draftUid) || 0;
    const draftNote = pane.querySelector('.draft-note');
    const snapshot = () => JSON.stringify([f('to').value, f('type').value, f('subject').value, f('body').value]);
    let savedAs = kind === 'fixed' ? '' : snapshot();   // a prepared check-in is worth keeping at once
    tab.changed = () => snapshot() !== savedAs;
    tab.hasText = () => !!(f('subject').value.trim() || f('body').value.replace(sig, '').trim() || (f('to').value.trim() && kind !== 'reply'));
    tab.saveDraft = async keepalive => {
        if (tab.sent) return true;
        const snap = snapshot();
        if (snap === savedAs) return true;
        if (!tab.hasText()) return true;
        const body = { action: 'msDraftSave', uid: tab.draftUid,
                       draft: { to: f('to').value, type: f('type').value, subject: f('subject').value, body: f('body').value } };
        try {
            const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), keepalive: !!keepalive });
            const d = await r.json();
            if (!d.success) { draftNote.textContent = 'Draft not saved: ' + (d.error || ''); return false; }
            tab.draftUid = d.uid || 0; savedAs = snap;
            draftNote.textContent = `Draft saved ${new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;
            if (!S.folders.find(x => x.name === d.folder)) loadFolders();   // Drafts was just made
            else if (isDraftsFolder(S.folder)) loadList(false);
            return true;
        } catch (e) { draftNote.textContent = 'Draft not saved (no connection)'; return false; }
    };
    tab.draftTimer = setInterval(() => { if (snapshot() !== savedAs) tab.saveDraft(); }, 20000);
    tab.onHide = () => { if (document.visibilityState === 'hidden' && snapshot() !== savedAs) tab.saveDraft(true); };
    document.addEventListener('visibilitychange', tab.onHide);
    addEventListener('pagehide', () => { if (S.tabs.includes(tab) && snapshot() !== savedAs) tab.saveDraft(true); });
    // v1.5.74: the To hint follows the type
    const toHint = () => {
        const t = f('type').value;
        f('to').placeholder = t === 'B' ? 'Group@area for a bulletin, e.g. WX@ALLUS or NEWS@USA (one address)'
            : t === 'T' ? 'NTS traffic: ZIP@NTSst, e.g. 30301@NTSGA (several = one ST each)'
            : 'Name, CALL, CALL@BBS.#REGION.ST.USA.NOAM, a list (commas between several)';
    };
    f('type').addEventListener('change', toHint);
    toHint();
    pane.addEventListener('input', () => { tab.dirty = true; const s = f('subject').value.trim(); setTabTitle(id, (kind === 'reply' ? 'Reply: ' : kind === 'forward' ? 'Forward: ' : kind === 'fixed' ? (pre.title || 'Write') + ': ' : 'Write: ') + (s || '(no subject)')); });
    const send = async () => {
        const v = { type: f('type').value, subject: f('subject').value.trim(), body: f('body').value.replace(/\s+$/, '') };
        const r = expandRecipients(f('to').value);
        if (r.bad.length) return toast('Not an address: ' + r.bad.join(', '), 'err');
        if (!r.list.length) return toast('Who is it to?', 'err');
        if (v.type === 'B' && r.list.length > 1) return toast('A bulletin goes to one address (e.g. WX@ALLUS)', 'err');
        if (r.list.length > 25) return toast('At most 25 recipients at a time', 'err');
        if (!v.subject) return toast('It needs a subject', 'err');
        if (!v.body.trim()) return toast('There is no text', 'err');
        // v1.5.78: characters other BBSes and packet may garble
        const odd = oddChars(v.subject + '\n' + v.body);
        if (odd.length) {
            const choice = await plainTextDialog(odd);
            if (choice === 'back') return;
            if (choice === 'fix') {
                v.subject = toPlain(v.subject); v.body = toPlain(v.body);
                f('subject').value = v.subject; f('body').value = v.body;
            }
        }
        if (r.list.length > 1 && !confirm(`Send ${r.list.length} separate BBS messages?\n\n${r.list.join('\n')}`)) return;
        const b = pane.querySelector('[data-a="send"]'); b.disabled = true;
        const ok = [], failed = [];
        // v1.5.76: each recipient is sent exactly once (bpq-send-once.js):
        // the same text to the same address keeps its send id in this tab,
        // so a dropped connection or a second press can't post it twice.
        tab.sendIds = tab.sendIds || {};
        const unsure = [];
        for (const [i, to] of r.list.entries()) {
            const label = r.list.length > 1 ? `Sending ${i + 1}/${r.list.length}…` : 'Sending…';
            b.textContent = label;
            const key = [to, v.type, v.subject, v.body].join('\u0001');
            tab.sendIds[key] = tab.sendIds[key] || window.bpqNewSendId();
            const d = await window.bpqSendOnce({ to, type: v.type, subject: v.subject, body: v.body },
                { url: BBS, sendId: tab.sendIds[key], onWait: msg => { b.textContent = 'Checking…'; $('stLeft').textContent = msg; } });
            if (d.success || d.stale) delete tab.sendIds[key];
            if (d.success) ok.push(to);
            else if (d.unknown || d.stale) unsure.push(to);
            else failed.push(`${to} (${d.error || 'no answer'})`);
        }
        if (unsure.length) {
            b.disabled = false; b.textContent = '➤ Send';
            f('to').value = r.list.filter(a => !ok.includes(a)).join(', ');
            return toast(`Not confirmed for ${unsure.join(', ')}: the connection dropped and the server couldn't say whether it went. Check Sent before sending again (Send again for the same text won't post a second copy).` + (ok.length ? ` Sent to ${ok.join(', ')}.` : ''), 'err');
        }
        b.disabled = false; b.textContent = '➤ Send';
        if (ok.length && v.type !== 'B') post({ action: 'abUsed', addresses: ok }).then(() => loadContacts());
        if (failed.length) {
            f('to').value = r.list.filter(a => !ok.includes(a)).join(', ');
            return toast(`Not sent to ${failed.join('; ')}` + (ok.length ? ` (sent to ${ok.join(', ')})` : ''), 'err');
        }
        tab.dirty = false; tab.sent = true;
        if (tab.draftUid) post({ action: 'msDraftDelete', uid: tab.draftUid }).then(() => { if (isDraftsFolder(S.folder)) loadList(false); });
        toast(`Sent to ${ok.join(', ')}`, 'ok');
        closeTab(id, { discarded: true });
    };
    pane.querySelector('[data-a="send"]').addEventListener('click', send);
    pane.querySelector('[data-a="cancel"]').addEventListener('click', () => {
        // v1.5.78: Cancel discards (the ✕ on the tab keeps it in Drafts)
        if ((tab.hasText() || tab.draftUid) && !confirm('Discard this message?' + (tab.draftUid ? ' It is also removed from Drafts.' : '') + '\n\nTo keep it for later, close the tab instead (✕): it stays in Drafts.')) return;
        if (tab.draftUid) post({ action: 'msDraftDelete', uid: tab.draftUid }).then(() => { if (isDraftsFolder(S.folder)) loadList(false); });
        tab.sent = true;
        closeTab(id, { discarded: true });
    });
    pane.addEventListener('keydown', ev => { if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); send(); } });
    switchTab(id);
    if (kind === 'reply') { const ta = f('body'); ta.focus(); ta.setSelectionRange(0, 0); }
}

// ── quick filter, search ───────────────────────────────────────
function wireFilters() {
    const bar = $('qfBar');
    const sync = () => {
        bar.classList.toggle('show', S.qf.show);
        $('btnQF').classList.toggle('on', S.qf.show);
        bar.querySelectorAll('[data-qf]').forEach(b => b.classList.toggle('on', !!S.qf[b.dataset.qf]));
        bar.querySelectorAll('[data-qff]').forEach(b => b.classList.toggle('on', S.qf.fields.includes(b.dataset.qff)));
    };
    S.qfSync = sync;
    sync();
    $('btnQF').addEventListener('click', () => { S.qf.show = !S.qf.show; lsSet('qf', S.qf.show); sync(); if (S.qf.show) $('qfText').focus(); loadList(false); });
    bar.querySelectorAll('[data-qf]').forEach(b => b.addEventListener('click', () => { S.qf[b.dataset.qf] = !S.qf[b.dataset.qf]; sync(); loadList(false); }));
    bar.querySelectorAll('[data-qff]').forEach(b => b.addEventListener('click', () => {
        const k = b.dataset.qff;
        S.qf.fields = S.qf.fields.includes(k) ? S.qf.fields.filter(x => x !== k) : S.qf.fields.concat(k);
        if (!S.qf.fields.length) S.qf.fields = [k];
        sync(); if (S.qf.text) loadList(false);
    }));
    let t;
    $('qfText').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { S.qf.text = $('qfText').value.trim(); loadList(false); }, 300); });
    $('qfText').addEventListener('keydown', ev => { if (ev.key === 'Escape') { $('qfText').value = ''; S.qf.text = ''; loadList(false); } });
    $('tbSearch').addEventListener('keydown', ev => {
        if (ev.key === 'Enter') {
            const q = $('tbSearch').value.trim();
            if (!q) { if (S.search) selectFolder(S.folder); return; }
            S.search = { q }; S.sel.clear(); S.cur = null; showMessage(null); renderFolders();
            setTabTitle('mail', 'Search: ' + q);
            loadList(false);
        } else if (ev.key === 'Escape') { $('tbSearch').value = ''; if (S.search) selectFolder(S.folder); }
    });
}

// ── keyboard (Thunderbird's) ───────────────────────────────────
function typing(ev) { const t = ev.target; return t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable); }
function wireKeys() {
    document.addEventListener('keydown', ev => {
        const ctrl = ev.ctrlKey || ev.metaKey;
        if (ev.key === 'Escape') closeMenus();
        if (ctrl && ev.key.toLowerCase() === 'w' && S.tab !== 'mail') { ev.preventDefault(); return closeTab(S.tab); }
        if (ctrl && ev.key === 'Tab') { ev.preventDefault(); const i = S.tabs.findIndex(t => t.id === S.tab); return switchTab(S.tabs[(i + (ev.shiftKey ? -1 : 1) + S.tabs.length) % S.tabs.length].id); }
        if (ctrl && (ev.key.toLowerCase() === 'n' || ev.key.toLowerCase() === 'm') && !ev.shiftKey) { ev.preventDefault(); return compose('new'); }
        if (ev.key === 'F5') { ev.preventDefault(); return getMessages(); }
        if (ctrl && ev.shiftKey && ev.key.toLowerCase() === 'b') { ev.preventDefault(); return openAddressBook(); }
        if (ctrl && (ev.key.toLowerCase() === 'p' || ev.key.toLowerCase() === 's') && !ev.shiftKey) {
            const t = S.tabs.find(x => x.id === S.tab);
            const sh = t.type === 'mail' ? S.shown : (t.type === 'msg' ? t.shown : null);
            if (sh) { ev.preventDefault(); return ev.key.toLowerCase() === 'p' ? printMessage(sh.m, sh.body) : saveMessage(sh.m, sh.body); }
        }
        if (typing(ev)) return;
        if (S.tab !== 'mail') {
            const t = S.tabs.find(x => x.id === S.tab);
            if (t.type === 'msg') {
                const m = S.msgs.find(x => x.folder === t.folder && x.uid === t.uid) || { folder: t.folder, uid: t.uid, subject: t.title };
                if (ctrl && ev.key.toLowerCase() === 'r') { ev.preventDefault(); compose('reply', m); }
                else if (ctrl && ev.key.toLowerCase() === 'l') { ev.preventDefault(); compose('forward', m); }
            }
            return;
        }
        if (ctrl && ev.shiftKey && ev.key.toLowerCase() === 'k') { ev.preventDefault(); S.qf.show = true; lsSet('qf', true); S.qfSync(); $('qfText').focus(); return; }
        if (ctrl && ev.key.toLowerCase() === 'k') { ev.preventDefault(); $('tbSearch').focus(); $('tbSearch').select(); return; }
        if (S.focus === 'folders') {
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                const list = [...document.querySelectorAll('#folderPane .fld')].map(e => e.dataset.f).filter(n => (S.folders.find(f => f.name === n) || {}).selectable);
                const i = list.indexOf(S.folder);
                const n = list[Math.max(0, Math.min(list.length - 1, i + (ev.key === 'ArrowDown' ? 1 : -1)))];
                if (n && n !== S.folder) selectFolder(n);
                return;
            }
            if (ev.key === 'Tab') { ev.preventDefault(); S.focus = 'list'; applyFocus(); return; }
        }
        const ms = selectedMsgs();
        const one = ms.length === 1 ? ms[0] : null;
        if (ctrl && ev.key.toLowerCase() === 'a') { ev.preventDefault(); S.sel = new Set(S.msgs.map(keyOf)); paintSel(); showCurrent(); return; }
        if (ctrl && ev.key.toLowerCase() === 'r') { ev.preventDefault(); if (one) compose('reply', one); return; }
        if (ctrl && ev.key.toLowerCase() === 'l') { ev.preventDefault(); if (one) compose('forward', one); return; }
        if (ctrl) return;
        switch (ev.key) {
            case 'ArrowDown': ev.preventDefault(); moveCursor(1, ev.shiftKey); break;
            case 'ArrowUp': ev.preventDefault(); moveCursor(-1, ev.shiftKey); break;
            case 'Home': ev.preventDefault(); moveCursor(-1e9, ev.shiftKey); break;
            case 'End': ev.preventDefault(); moveCursor(1e9, ev.shiftKey); break;
            case 'PageDown': ev.preventDefault(); moveCursor(15, ev.shiftKey); break;
            case 'PageUp': ev.preventDefault(); moveCursor(-15, ev.shiftKey); break;
            case 'Enter': if (one) openInTab(one); break;
            case 'Delete': case 'Backspace': ev.preventDefault(); del(ms, ev.shiftKey); break;
            case 'Tab': ev.preventDefault(); S.focus = ev.shiftKey ? 'folders' : 'list'; applyFocus(); break;
            default:
                switch (ev.key.toLowerCase()) {
                    case 'f': moveCursor(1); break;
                    case 'b': moveCursor(-1); break;
                    case 'n': nextUnread(); break;
                    case 'm': if (ms.length) setSeen(ms, !ms[0].seen); break;
                    case 's': if (ms.length) setFlag(ms, !ms[0].flagged); break;
                    case 'r': if (ev.shiftKey && one) compose('reply', one); break;
                    case 'c': if (ev.shiftKey) markFolderRead(S.folder); break;
                }
        }
    });
}
function applyFocus() {
    $('folderPane').classList.toggle('focus-folders', S.focus === 'folders');
    $('listWrap').classList.toggle('focus-list', S.focus === 'list');
    document.body.classList.toggle('focus-folders', S.focus === 'folders');
    $('threadPane').classList.toggle('focus-list', S.focus === 'list');
}

// ── toolbar, status, refresh ───────────────────────────────────
function status() {
    const f = S.folders.find(x => x.name === S.folder);
    $('stLeft').textContent = `BBS mail store — ${(S.user || '').toUpperCase()}` + (S.lastRefresh ? ` · checked ${S.lastRefresh.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : '');
    $('stUnread').textContent = f && !S.search ? `Unread: ${f.unseen}` : '';
    $('stTotal').textContent = f && !S.search ? `Total: ${f.messages}` : '';
    updateTitle();
}
async function getMessages() {
    $('btnGet').disabled = true;
    const before = S.folders.reduce((a, f) => a + (f.messages || 0), 0);
    await loadFolders();
    const after = S.folders.reduce((a, f) => a + (f.messages || 0), 0);
    if (!S.search && S.tab === 'mail') {
        const keep = new Set(S.sel), cur = S.cur;
        await loadList(false);
        S.sel = new Set([...keep].filter(k => S.msgs.some(m => keyOf(m) === k))); S.cur = S.sel.has(cur) ? cur : null;
        renderRows(true);
    }
    S.lastRefresh = new Date(); status();
    $('btnGet').disabled = false;
    if (after > before) toast(`${after - before} new message${after - before === 1 ? '' : 's'}`, 'ok');
}
function wireToolbar() {
    $('btnGet').addEventListener('click', getMessages);
    $('btnWrite').addEventListener('click', () => compose('new'));
    $('btnAB').addEventListener('click', () => openAddressBook());
    $('btnDel').addEventListener('click', () => del(selectedMsgs()));      // v1.5.101
    $('btnNts').addEventListener('click', () => window.open('/nts-forwarding-report.php', '_blank'));
    $('btnForms').addEventListener('click', () => window.open('/pktnet-forms/index.html', '_blank'));
    $('btnCheckIn').addEventListener('click', () => openCheckIn());
    $('btnFolders').addEventListener('click', () => $('folderPane').classList.toggle('show'));
    $('btnMenu').addEventListener('click', ev => {
        const r = ev.currentTarget.getBoundingClientRect();
        menu([
            { label: 'Write a New Message', key: 'Ctrl+N', act: () => compose('new') },
            { label: 'Get Messages', key: 'F5', act: getMessages },
            '-',
            { label: 'Mark All Read', sub: [
                { label: 'Inbox', act: () => markFolderRead('INBOX') },
                { label: 'Inbox and Its Folders', act: () => markFolderRead('INBOX', true) },
                { label: 'All Bulletins', act: () => markFolderRead('Bulletins', true) },
                { label: 'Inbox and All Bulletins', act: async () => { await markFolderRead('INBOX', true); await markFolderRead('Bulletins', true); } },
            ] },
            { label: 'Message Filters…', act: () => openFilters() },
            { label: 'Run Retention Cleanup Now…', act: () => runRetention() },
            '-',
            { label: 'Address Book', key: 'Ctrl+Shift+B', act: () => openAddressBook() },
            { label: 'Tools', sub: [
                { label: 'Forwarding Activity…', act: () => toolForwarding() },
                { label: 'BBSes Seen…', act: () => toolBbsSeen() },
                { label: 'Statistics…', act: () => toolStats() },
                { label: 'Killed on the BBS…', act: () => toolKills() },
            ] },
            { label: 'Settings…', act: openSettings },
            '-',
            { label: 'Empty Trash… (kills on the BBS)', danger: true, act: emptyTrash },
            { label: 'New Folder in Inbox…', act: () => newFolder('INBOX') },
            '-',
            { label: 'Keyboard Shortcuts', act: shortcutsHelp },
            { label: 'Quick Check-In…', act: () => openCheckIn() },
            { label: 'NTS Report', act: () => $('btnNts').click() },
            { label: 'PKTNET Forms', act: () => $('btnForms').click() },
            { label: 'User Guide (§9)', act: () => window.open('/BPQ-Dashboard-v1.6.0-User-Guide.pdf', '_blank') },
        ], r.right - 220, r.bottom);
    });
}
function shortcutsHelp() {
    alert([
        'Thunderbird keys in BBS Mail', '',
        'Ctrl+N  Write          Ctrl+R  Reply          Ctrl+L  Forward',
        'Ctrl+Shift+B  Address Book    Ctrl+P  Print    Ctrl+S  Save as text',
        'F5      Get Messages   Ctrl+K  Search all folders',
        'Ctrl+Shift+K  Quick Filter     Esc  clear filter / close menu',
        'Up/Down  next/previous (Shift extends)   F / B  next / previous',
        'N  next unread     M  read/unread     S  star',
        'Delete  to Trash (in Trash: for good + kill on BBS)',
        'Enter  open in a tab     Ctrl+W  close tab     Ctrl+Tab  next tab',
        'Ctrl+A  select all      Shift+C  mark folder read',
        'Tab / Shift+Tab  list / folder pane',
        'Right-click messages, folders and column headings for more.',
    ].join('\n'));
}

// ── print / save (v1.5.72) ─────────────────────────────────────
function headerText(m) {
    return `From: ${m.from}\nTo: ${m.to}\nSubject: ${m.subject || ''}\nDate: ${fmtDate(m.date, true)}\n` +
        (m.bbsNumber != null ? `BBS: #${m.bbsNumber}${m.bid ? '  BID ' + m.bid : ''}  ${m.bbsType || ''}\n` : '');
}
function printMessage(m, body) {
    const w = window.open('', '_blank', 'width=800,height=900');
    if (!w) return toast('The browser blocked the print window', 'err');
    w.document.write(`<!doctype html><meta charset="utf-8"><title>${esc(m.subject || 'Message')}</title>
        <style>body{font:13px/1.45 ui-monospace,Menlo,Consolas,monospace;margin:24px}h1{font:600 16px system-ui,sans-serif;margin:0 0 6px}
        .h{font:12px system-ui,sans-serif;color:#444;border-bottom:1px solid #999;padding-bottom:8px;margin-bottom:12px;white-space:pre-wrap}
        pre{white-space:pre-wrap;word-wrap:break-word;margin:0}</style>
        <h1>${esc(m.subject || '(no subject)')}</h1><div class="h">${esc(headerText(m))}</div><pre>${esc(body)}</pre>`);
    w.document.close();
    w.focus();
    setTimeout(() => { w.print(); }, 250);
}
function saveMessage(m, body) {
    const text = headerText(m) + '\n' + body + '\n';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    a.download = ((m.subject || 'message').replace(/[^\w.-]+/g, '_').slice(0, 60) || 'message') + (m.bbsNumber != null ? '_' + m.bbsNumber : '') + '.txt';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

// ── Address Book (v1.5.72), laid out like Thunderbird's ────────
const AB = { book: 'all', listId: null, sel: new Set(), cur: null, q: '', sort: lsGet('abSort', 'name'), edit: null, editData: null };
async function loadContacts() {
    const d = await get({ action: 'abList' });
    if (d.success) { S.contacts = d.contacts || []; S.abReady = true; }
    else if (!S.abError) { S.abError = d.error; }
    if (S.tabs.some(t => t.type === 'ab')) renderAB();
    if (S.msgs.length) renderRows(true);
    return d.success;
}
function initials(k) {
    const n = nameOf(k) || k.callsign || '?';
    const p = n.trim().split(/\s+/);
    return (p.length > 1 ? p[0][0] + p[p.length - 1][0] : n.slice(0, 2)).toUpperCase();
}
function avatarColor(k) {
    let h = 0; for (const ch of (k.callsign || k.display_name || 'x')) h = (h * 31 + ch.charCodeAt(0)) % 360;
    return `hsl(${h} 55% 45%)`;
}
function abVisible() {
    let list = S.contacts.slice();
    if (AB.listId) {
        const l = S.contacts.find(k => k.id === AB.listId);
        const ids = new Set(l ? l.members : []);
        list = list.filter(k => ids.has(k.id));
    } else if (AB.book === 'personal' || AB.book === 'collected') {
        list = list.filter(k => k.book === AB.book && (AB.book === 'personal' || k.kind === 'contact'));
    } else if (AB.book === 'starred') {
        list = list.filter(k => k.starred);
    }
    const q = AB.q.toLowerCase();
    if (q) list = list.filter(k => [k.callsign, nameOf(k), k.nickname, k.address, k.email, k.phone, k.location, k.grid, k.notes]
        .some(v => String(v || '').toLowerCase().includes(q)));
    const key = k => AB.sort === 'call' ? (k.callsign || nameOf(k)) : AB.sort === 'used' ? -(+new Date(k.last_used_at || 0)) : (nameOf(k) || k.callsign).toLowerCase();
    list.sort((a, b) => (a.kind === 'list') - (b.kind === 'list') || (key(a) < key(b) ? -1 : key(a) > key(b) ? 1 : 0));
    return list;
}
function openAddressBook(id, edit, preset) {
    let t = S.tabs.find(x => x.type === 'ab');
    if (!t) {
        t = { id: 'ab', type: 'ab', title: 'Address Book', icon: '📇' };
        S.tabs.push(t);
        const pane = document.createElement('div');
        pane.className = 'tab-pane ab'; pane.dataset.tab = 'ab';
        pane.innerHTML = `
          <aside class="ab-books" id="abBooks"></aside>
          <section class="ab-mid">
            <div class="ab-bar">
              <button class="tbtn primary" id="abNew" title="New Contact">＋ <span class="lbl">Contact</span></button>
              <button class="tbtn" id="abNewList" title="New List">👥 <span class="lbl">List</span></button>
              <input id="abSearch" type="search" placeholder="Search contacts…" autocomplete="off">
              <button class="tbtn" id="abSortBtn" title="Sort">⇅</button>
              <button class="tbtn" id="abMore" title="Import, export">⋯</button>
            </div>
            <div class="ab-list" id="abList" tabindex="0"></div>
            <div class="ab-foot" id="abFoot"></div>
          </section>
          <section class="ab-detail" id="abDetail"></section>`;
        $('tbBody').appendChild(pane);
        $('abNew').addEventListener('click', () => abEdit(null, { book: AB.book === 'collected' ? 'collected' : 'personal' }));
        $('abNewList').addEventListener('click', () => abEdit(null, { kind: 'list', book: 'personal' }));
        $('abSearch').addEventListener('input', () => { AB.q = $('abSearch').value.trim(); renderAB(); });
        $('abSortBtn').addEventListener('click', ev => {
            const r = ev.currentTarget.getBoundingClientRect();
            const set = v => () => { AB.sort = v; lsSet('abSort', v); renderAB(); };
            menu([{ label: (AB.sort === 'name' ? '✓ ' : '   ') + 'Sort by Name', act: set('name') },
                  { label: (AB.sort === 'call' ? '✓ ' : '   ') + 'Sort by Callsign', act: set('call') },
                  { label: (AB.sort === 'used' ? '✓ ' : '   ') + 'Sort by Last Written To', act: set('used') }], r.left, r.bottom);
        });
        $('abMore').addEventListener('click', ev => { const r = ev.currentTarget.getBoundingClientRect(); abMoreMenu(r.right - 260, r.bottom); });
        $('abList').addEventListener('keydown', ev => {
            const list = abVisible();
            const i = list.findIndex(k => k.id === AB.cur);
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                const n = list[Math.max(0, Math.min(list.length - 1, i + (ev.key === 'ArrowDown' ? 1 : -1)))];
                if (n) { AB.cur = n.id; AB.sel = new Set([n.id]); AB.edit = null; renderAB(); }
            } else if (ev.key === 'Delete') { ev.preventDefault(); abDelete([...AB.sel]); }
            else if (ev.key === 'Enter' && AB.cur) { abEdit(AB.cur); }
        });
    }
    switchTab('ab');
    if (id) { const k = S.contacts.find(x => x.id === id); if (k) { AB.book = 'all'; AB.listId = null; AB.cur = id; AB.sel = new Set([id]); } }
    AB.edit = null;
    renderAB();
    if (edit) abEdit(id, preset);
}
function renderAB() {
    if (!$('abBooks')) return;
    const cnt = b => S.contacts.filter(k => b === 'all' ? true : b === 'starred' ? k.starred : k.book === b && (b === 'personal' || k.kind === 'contact')).length;
    const lists = S.contacts.filter(k => k.kind === 'list').sort((a, b) => a.display_name.localeCompare(b.display_name));
    const item = (key, icon, label, n, depth) => `<div class="fld ${(!AB.listId && AB.book === key) || AB.listId === key ? 'sel' : ''}" data-book="${esc(key)}" style="padding-left:${8 + (depth || 0) * 16}px">
        <span class="ic">${icon}</span><span class="nm">${esc(label)}</span><span class="ct">${n}</span></div>`;
    $('abBooks').innerHTML = `<div class="acct">📇 Address Books</div>` +
        item('all', '📚', 'All Address Books', cnt('all')) +
        item('personal', '📒', 'Personal Address Book', cnt('personal')) +
        lists.map(l => item(l.id, '👥', l.display_name, l.members.length, 1)).join('') +
        item('collected', '📥', 'Collected Addresses', cnt('collected')) +
        item('starred', '★', 'Starred', cnt('starred')) +
        (S.abError ? `<div class="ab-note">⚠ ${esc(S.abError)}</div>` : '');
    $('abBooks').querySelectorAll('[data-book]').forEach(el => el.addEventListener('click', () => {
        const v = el.dataset.book;
        if (/^\d+$/.test(v)) { AB.listId = +v; } else { AB.listId = null; AB.book = v; }
        AB.sel.clear(); AB.cur = AB.listId || null; AB.edit = null; renderAB();
    }));
    const list = abVisible();
    $('abList').innerHTML = list.length ? list.map(k => `
        <div class="ab-card ${AB.sel.has(k.id) ? 'sel' : ''}" data-id="${k.id}" draggable="${k.kind === 'contact'}">
          <div class="av" style="background:${k.kind === 'list' ? 'var(--muted)' : avatarColor(k)}">${k.kind === 'list' ? '👥' : esc(initials(k))}</div>
          <div class="ab-txt"><div class="n">${esc(k.kind === 'list' ? k.display_name : (nameOf(k) || k.callsign))}${k.starred ? ' <span class="star on">★</span>' : ''}</div>
            <div class="a">${esc(k.kind === 'list' ? `${k.members.length} member${k.members.length === 1 ? '' : 's'}` : (nameOf(k) ? k.callsign + (k.address && k.address !== k.callsign ? ' · ' + k.address : '') : (k.address || k.callsign)))}</div></div>
          ${k.book === 'collected' && AB.book === 'all' ? '<span class="ab-tag">collected</span>' : ''}
        </div>`).join('')
        : `<div class="mv-empty" style="padding:30px">${S.abReady ? (AB.q ? 'No contacts match' : 'No contacts here yet. ＋ New Contact, or ⋯ → Import.') : 'Loading…'}</div>`;
    $('abFoot').textContent = `${list.length} contact${list.length === 1 ? '' : 's'}${AB.sel.size > 1 ? ` · ${AB.sel.size} selected` : ''}`;
    $('abList').querySelectorAll('.ab-card').forEach(el => {
        const id = +el.dataset.id;
        el.addEventListener('mousedown', ev => {
            if (ev.button === 2 && AB.sel.has(id)) return;
            if (ev.ctrlKey || ev.metaKey) AB.sel.has(id) ? AB.sel.delete(id) : AB.sel.add(id);
            else if (ev.shiftKey && AB.cur) {
                const ids = list.map(k => k.id), a = ids.indexOf(AB.cur), b = ids.indexOf(id);
                AB.sel = new Set(ids.slice(Math.min(a, b), Math.max(a, b) + 1));
            } else AB.sel = new Set([id]);
            AB.cur = id; AB.edit = null; renderAB();
        });
        el.addEventListener('dblclick', () => abEdit(id));
        el.addEventListener('contextmenu', ev => { ev.preventDefault(); abCardMenu(ev.clientX, ev.clientY); });
        el.addEventListener('dragstart', ev => { ev.dataTransfer.setData('text/x-bpq-contacts', JSON.stringify([...AB.sel.has(id) ? AB.sel : [id]])); });
    });
    // drop contacts on a list to add them
    $('abBooks').querySelectorAll('[data-book]').forEach(el => {
        if (!/^\d+$/.test(el.dataset.book)) return;
        el.addEventListener('dragover', ev => { if ([...ev.dataTransfer.types].includes('text/x-bpq-contacts')) { ev.preventDefault(); el.classList.add('drop'); } });
        el.addEventListener('dragleave', () => el.classList.remove('drop'));
        el.addEventListener('drop', ev => {
            ev.preventDefault(); el.classList.remove('drop');
            let ids = []; try { ids = JSON.parse(ev.dataTransfer.getData('text/x-bpq-contacts')); } catch (e) { return; }
            abAddToList(+el.dataset.book, ids);
        });
    });
    renderABDetail();
}
function abCardMenu(x, y) {
    const ks = S.contacts.filter(k => AB.sel.has(k.id));
    if (!ks.length) return;
    const one = ks.length === 1 ? ks[0] : null;
    const lists = S.contacts.filter(k => k.kind === 'list');
    menu([
        { label: 'Write', act: () => abWrite(ks) },
        { label: 'Edit', off: !one, act: () => abEdit(one.id) },
        '-',
        { label: 'Add to List', off: !lists.length || ks.some(k => k.kind === 'list'), sub: lists.map(l => ({ label: l.display_name, act: () => abAddToList(l.id, ks.map(k => k.id)) })) },
        AB.listId ? { label: 'Remove from This List', act: () => abRemoveFromList(AB.listId, ks.map(k => k.id)) } : null,
        ks.every(k => k.book === 'collected') ? { label: 'Move to Personal Address Book', act: () => abMove(ks.map(k => k.id), 'personal') } : null,
        { label: ks.every(k => k.starred) ? 'Unstar' : 'Star', act: () => abStar(ks, !ks.every(k => k.starred)) },
        '-',
        { label: 'Export as vCard…', act: () => abExport(ks, 'vcf') },
        { label: 'Delete', danger: true, act: () => abDelete(ks.map(k => k.id)) },
    ].filter(Boolean), x, y);
}
function abMoreMenu(x, y) {
    menu([
        { label: 'Import from the Email Client\'s address book', act: abImportClassic },
        { label: 'Import everyone who has written to you', act: abImportSenders },
        { label: 'Import vCard or CSV file…', act: abImportFile },
        '-',
        { label: 'Export all as vCard (.vcf)…', act: () => abExport(S.contacts.filter(k => k.kind === 'contact'), 'vcf') },
        { label: 'Export all as CSV…', act: () => abExport(S.contacts.filter(k => k.kind === 'contact'), 'csv') },
    ], x, y);
}
function abWrite(ks) {
    const parts = [];
    for (const k of ks) parts.push(k.kind === 'list' ? k.display_name : (nameOf(k) ? `${nameOf(k)} <${k.address || k.callsign}>` : k.address || k.callsign));
    compose('new', null, null, parts.join(', '));
}
function renderABDetail() {
    const host = $('abDetail');
    if (AB.edit) return renderABEdit(host);
    if (AB.sel.size > 1) { host.innerHTML = `<div class="mv-empty">${AB.sel.size} contacts selected<br><br><button class="tbtn" id="abWriteSel">✎ Write to them</button></div>`; $('abWriteSel').onclick = () => abWrite(S.contacts.filter(k => AB.sel.has(k.id))); return; }
    const k = S.contacts.find(x => x.id === AB.cur);
    if (!k) { host.innerHTML = '<div class="mv-empty">Select a contact</div>'; return; }
    const row = (lbl, v, href) => v ? `<div class="ab-f"><div class="l">${lbl}</div><div class="v">${href ? `<a href="${esc(href)}" target="_blank" rel="noopener">${esc(v)}</a>` : esc(v)}</div></div>` : '';
    const inLists = S.contacts.filter(l => l.kind === 'list' && l.members.includes(k.id));
    if (k.kind === 'list') {
        const mem = k.members.map(id => S.contacts.find(x => x.id === id)).filter(Boolean);
        host.innerHTML = `<div class="ab-head"><div class="av big" style="background:var(--muted)">👥</div><div><div class="ab-name">${esc(k.display_name)}</div>
            <div class="ab-sub">Mailing list · ${mem.length} member${mem.length === 1 ? '' : 's'}</div></div></div>
          <div class="ab-acts"><button class="tbtn primary" data-x="write">✎ Write</button><button class="tbtn" data-x="edit">Edit</button><button class="tbtn" data-x="del">Delete</button></div>
          ${k.notes ? `<div class="ab-sec"><div class="ab-st">Description</div><div class="ab-notes">${esc(k.notes)}</div></div>` : ''}
          <div class="ab-sec"><div class="ab-st">Members</div>${mem.map(x => `<div class="ab-mem"><span class="av sm" style="background:${avatarColor(x)}">${esc(initials(x))}</span>${esc(nameOf(x) || x.callsign)} <span class="k">${esc(x.address)}</span></div>`).join('') || '<div class="k">No members yet: drag contacts onto the list, or Edit.</div>'}</div>
          <div class="ab-note">Writing to a list sends one BBS message to each member.</div>`;
    } else {
        host.innerHTML = `<div class="ab-head"><div class="av big" style="background:${avatarColor(k)}">${esc(initials(k))}</div><div>
            <div class="ab-name">${esc(nameOf(k) || k.callsign)} <span class="star ${k.starred ? 'on' : ''}" data-x="star" title="Star" style="cursor:pointer">${k.starred ? '★' : '☆'}</span></div>
            <div class="ab-sub">${esc(k.callsign)}${k.nickname ? ' · “' + esc(k.nickname) + '”' : ''}${k.book === 'collected' ? ' · Collected Addresses' : ''}</div></div></div>
          <div class="ab-acts"><button class="tbtn primary" data-x="write">✎ Write</button><button class="tbtn" data-x="edit">Edit</button>
            ${k.book === 'collected' ? '<button class="tbtn" data-x="keep">Move to Personal</button>' : ''}
            <button class="tbtn" data-x="msgs">Messages</button><button class="tbtn" data-x="del">Delete</button></div>
          <div class="ab-sec"><div class="ab-st">Contact</div>
            ${row('BBS address', k.address)}${row('Email', k.email, k.email ? 'mailto:' + k.email : '')}${row('Phone', k.phone, k.phone ? 'tel:' + k.phone : '')}
            ${row('Grid', k.grid)}${row('Location', k.location)}
            ${row('QRZ.com', 'Look up ' + k.callsign.replace(/-\d+$/, ''), 'https://www.qrz.com/db/' + encodeURIComponent(k.callsign.replace(/-\d+$/, '')))}</div>
          ${k.notes ? `<div class="ab-sec"><div class="ab-st">Notes</div><div class="ab-notes">${esc(k.notes)}</div></div>` : ''}
          ${inLists.length ? `<div class="ab-sec"><div class="ab-st">Lists</div>${inLists.map(l => esc(l.display_name)).join(', ')}</div>` : ''}
          <div class="ab-sec"><div class="ab-st">Recent messages</div><div id="abRecent" class="k">…</div></div>
          <div class="ab-note">${k.use_count ? `Written to ${k.use_count} time${k.use_count === 1 ? '' : 's'}` + (k.last_used_at ? `, last ${esc(fmtDate(String(k.last_used_at).replace(' ', 'T')))}` : '') + '. ' : ''}Added ${esc(fmtDate(String(k.created_at).replace(' ', 'T')))}.</div>`;
        abRecent(k);
    }
    host.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', () => {
        const x = b.dataset.x;
        if (x === 'write') abWrite([k]);
        else if (x === 'edit') abEdit(k.id);
        else if (x === 'del') abDelete([k.id]);
        else if (x === 'keep') abMove([k.id], 'personal');
        else if (x === 'star') abStar([k], !k.starred);
        else if (x === 'msgs') { switchTab('mail'); $('tbSearch').value = k.callsign; $('tbSearch').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' })); }
    }));
}
let abRecentSeq = 0;
async function abRecent(k) {
    const seq = ++abRecentSeq;
    const d = await get({ action: 'msSearchAll', q: k.callsign.replace(/-\d+$/, ''), fields: 'from,to' });
    if (seq !== abRecentSeq || !$('abRecent')) return;
    if (!d.success) { $('abRecent').textContent = d.error || ''; return; }
    const ms = (d.messages || []).slice(0, 8);
    $('abRecent').innerHTML = ms.length ? ms.map((m, i) => `<div class="ab-msg" data-i="${i}"><span>${esc(m.subject || '(no subject)')}</span><span class="k">${esc(fmtDate(m.date))} · ${esc(prettyFolder(m.folder))}</span></div>`).join('')
        + (d.total > ms.length ? `<div class="k">… ${d.total - ms.length} more (Messages)</div>` : '') : 'None in the mail store';
    $('abRecent').querySelectorAll('[data-i]').forEach(el => el.addEventListener('click', () => openInTab(Object.assign({}, ms[+el.dataset.i]))));
}
function abEdit(id, preset) {
    const k = id ? S.contacts.find(x => x.id === id) : null;
    AB.edit = k ? k.id : 'new';
    AB.editData = Object.assign({ kind: 'contact', book: 'personal', members: [] }, k || {}, preset || {});
    if (!$('abDetail')) return openAddressBook(id, true, preset);
    renderABDetail();
}
function renderABEdit(host) {
    const c = AB.editData;
    const fld = (key, lbl, ph, attrs) => `<label>${lbl}</label><input data-k="${key}" value="${esc(c[key] || '')}" placeholder="${esc(ph || '')}" ${attrs || ''}>`;
    if (c.kind === 'list') {
        const cands = S.contacts.filter(k => k.kind === 'contact').sort((a, b) => (nameOf(a) || a.callsign).localeCompare(nameOf(b) || b.callsign));
        host.innerHTML = `<div class="ab-st" style="margin:12px 16px 4px">${AB.edit === 'new' ? 'New List' : 'Edit List'}</div>
          <div class="ab-form">${fld('display_name', 'List name', 'e.g. Net members', 'maxlength="100" data-first')}
            <label>Description</label><textarea data-k="notes" rows="2">${esc(c.notes || '')}</textarea>
            <label>Members</label><div><input id="abMemQ" placeholder="Filter…" style="width:100%;margin-bottom:4px">
              <div class="ab-mems">${cands.map(k => `<label class="ab-chk"><input type="checkbox" value="${k.id}" ${c.members.includes(k.id) ? 'checked' : ''}> ${esc(nameOf(k) || k.callsign)} <span class="k">${esc(k.callsign)}</span></label>`).join('') || '<span class="k">Add some contacts first.</span>'}</div></div></div>
          <div class="ab-acts" style="padding:8px 16px"><button class="tbtn primary" data-y="save">Save</button><button class="tbtn" data-y="cancel">Cancel</button></div>`;
        const q = $('abMemQ');
        q.addEventListener('input', () => host.querySelectorAll('.ab-chk').forEach(l => { l.style.display = l.textContent.toLowerCase().includes(q.value.toLowerCase()) ? '' : 'none'; }));
    } else {
        host.innerHTML = `<div class="ab-st" style="margin:12px 16px 4px">${AB.edit === 'new' ? 'New Contact' : 'Edit Contact'}</div>
          <div class="ab-form">
            ${fld('callsign', 'Callsign', 'N3MEL', 'maxlength="20" data-first style="text-transform:uppercase"')}
            ${fld('first_name', 'First name', '', 'maxlength="60"')}${fld('last_name', 'Last name', '', 'maxlength="60"')}
            ${fld('display_name', 'Display name', 'shown in the message list', 'maxlength="100"')}${fld('nickname', 'Nickname', '', 'maxlength="60"')}
            ${fld('address', 'BBS address', 'CALL@BBS.#REGION.ST.USA.NOAM (blank = callsign)', 'maxlength="120" style="text-transform:uppercase"')}
            ${fld('email', 'Email', 'internet email (for your notes)', 'maxlength="120" type="email"')}
            ${fld('phone', 'Phone', '', 'maxlength="40"')}${fld('grid', 'Grid', 'FM07', 'maxlength="10" style="text-transform:uppercase"')}
            ${fld('location', 'Location', 'City, State', 'maxlength="120"')}
            <label>Notes</label><textarea data-k="notes" rows="4">${esc(c.notes || '')}</textarea>
            <label></label><label class="ab-chk"><input type="checkbox" data-k="starred" ${c.starred ? 'checked' : ''}> Starred</label>
          </div>
          <div class="ab-acts" style="padding:8px 16px"><button class="tbtn primary" data-y="save">Save</button><button class="tbtn" data-y="cancel">Cancel</button>
            <span class="k" style="margin-left:8px">The BBS address is where mail to this contact goes; leave it blank to let the BBS route by callsign.</span></div>`;
        // first/last fill the display name, as Thunderbird does
        const dn = host.querySelector('[data-k="display_name"]');
        let dnTouched = !!c.display_name;
        dn.addEventListener('input', () => { dnTouched = true; });
        host.querySelectorAll('[data-k="first_name"],[data-k="last_name"]').forEach(i => i.addEventListener('input', () => {
            if (!dnTouched) dn.value = [host.querySelector('[data-k="first_name"]').value, host.querySelector('[data-k="last_name"]').value].filter(Boolean).join(' ');
        }));
    }
    const first = host.querySelector('[data-first]'); if (first) first.focus();
    const save = async () => {
        const data = Object.assign({}, c);
        host.querySelectorAll('[data-k]').forEach(i => { data[i.dataset.k] = i.type === 'checkbox' ? i.checked : i.value; });
        if (c.kind === 'list') data.members = [...host.querySelectorAll('.ab-mems input:checked')].map(i => +i.value);
        const d = await post({ action: 'abSave', contact: data });
        if (!d.success) return toast(d.error || 'Not saved', 'err');
        AB.edit = null; AB.cur = d.contact.id; AB.sel = new Set([d.contact.id]);
        await loadContacts();
        toast('Saved', 'ok');
        if (S.tab === 'mail' || S.shown) renderRows(true);
    };
    host.querySelector('[data-y="save"]').addEventListener('click', save);
    host.querySelector('[data-y="cancel"]').addEventListener('click', () => { AB.edit = null; renderABDetail(); });
    host.addEventListener('keydown', ev => { if (ev.key === 'Enter' && (ev.ctrlKey || ev.target.tagName === 'INPUT')) { ev.preventDefault(); save(); } else if (ev.key === 'Escape') { AB.edit = null; renderABDetail(); } });
}
async function abDelete(ids) {
    if (!ids.length) return;
    const names = S.contacts.filter(k => ids.includes(k.id)).map(k => nameOf(k) || k.callsign || k.display_name);
    if (!confirm(`Delete ${ids.length === 1 ? '“' + names[0] + '”' : ids.length + ' contacts'} from the address book?`)) return;
    const d = await post({ action: 'abDelete', ids });
    if (!d.success) return toast(d.error || 'Failed', 'err');
    AB.sel.clear(); AB.cur = null; AB.edit = null;
    loadContacts();
}
async function abMove(ids, book) { const d = await post({ action: 'abMove', ids, book }); if (!d.success) return toast(d.error, 'err'); loadContacts(); }
async function abStar(ks, on) {
    for (const k of ks) await post({ action: 'abSave', contact: Object.assign({}, k, { starred: on }) });
    loadContacts();
}
async function abAddToList(listId, ids) {
    const l = S.contacts.find(k => k.id === listId);
    if (!l) return;
    const members = [...new Set(l.members.concat(ids.filter(id => (S.contacts.find(k => k.id === id) || {}).kind === 'contact')))];
    const d = await post({ action: 'abSave', contact: Object.assign({}, l, { members }) });
    if (!d.success) return toast(d.error, 'err');
    toast(`Added to ${l.display_name}`, 'ok'); loadContacts();
}
async function abRemoveFromList(listId, ids) {
    const l = S.contacts.find(k => k.id === listId);
    if (!l) return;
    const d = await post({ action: 'abSave', contact: Object.assign({}, l, { members: l.members.filter(x => !ids.includes(x)) }) });
    if (!d.success) return toast(d.error, 'err'); loadContacts();
}
async function abImport(contacts, what) {
    if (!contacts.length) return toast('Nothing to import', 'err');
    const d = await post({ action: 'abImport', book: 'personal', contacts });
    if (!d.success) return toast(d.error || 'Import failed', 'err');
    toast(`${what}: ${d.added} added, ${d.merged} updated, ${d.skipped} already there or not usable`, 'ok');
    loadContacts();
}
function abImportClassic() {
    let book = {};
    try { book = JSON.parse(localStorage.getItem('bbs_v2_address_book') || '{}') || {}; } catch (e) { book = {}; }
    const fromLocal = Object.values(book).map(e => ({ callsign: e.callsign, display_name: e.displayName || '', address: e.fullAddress || e.callsign, notes: e.notes || '' }));
    const fromServer = S.addresses.filter(a => /^[A-Z0-9\/-]+@/i.test(a) && !/^(WX|NEWS|ALL|ARRL|DX|TECH|SYSOP)@/i.test(a))
        .map(a => ({ callsign: a.split('@')[0], address: a }));
    abImport(fromLocal.concat(fromServer), 'From the Email Client');
}
async function abImportSenders() {
    const d = await get({ action: 'abSenders' });
    if (!d.success) return toast(d.error || 'Failed', 'err');
    const list = (d.senders || []).map(r => ({ callsign: r.callsign, address: r.callsign }));
    if (!confirm(`Add ${list.length} station${list.length === 1 ? '' : 's'} that have written to you? Ones already in the address book are left as they are.`)) return;
    abImport(list, 'Senders');
}
function abImportFile() {
    const inp = document.createElement('input');
    inp.type = 'file'; inp.accept = '.vcf,.csv,text/vcard,text/csv';
    inp.onchange = async () => {
        const f = inp.files[0]; if (!f) return;
        const text = await f.text();
        abImport(/BEGIN:VCARD/i.test(text) ? parseVcf(text) : parseCsv(text), f.name);
    };
    inp.click();
}
function parseVcf(text) {
    const out = [];
    for (const card of text.replace(/\r\n[ \t]/g, '').split(/BEGIN:VCARD/i).slice(1)) {
        const g = re => { const m = card.match(re); return m ? m[1].trim().replace(/\\([,;\\])/g, '$1').replace(/\\n/gi, '\n') : ''; };
        const n = g(/^N(?:;[^:]*)?:(.*)$/mi).split(';');
        const call = g(/^X-CALLSIGN(?:;[^:]*)?:(.*)$/mi) || g(/^NICKNAME(?:;[^:]*)?:(.*)$/mi);
        const addr = g(/^X-BBS-ADDRESS(?:;[^:]*)?:(.*)$/mi);
        out.push({ callsign: (call || addr.split('@')[0] || '').toUpperCase(), display_name: g(/^FN(?:;[^:]*)?:(.*)$/mi), last_name: n[0] || '', first_name: n[1] || '',
                   address: addr, email: g(/^EMAIL(?:;[^:]*)?:(.*)$/mi), phone: g(/^TEL(?:;[^:]*)?:(.*)$/mi), notes: g(/^NOTE(?:;[^:]*)?:(.*)$/mi),
                   grid: g(/^X-GRID(?:;[^:]*)?:(.*)$/mi), location: g(/^ADR(?:;[^:]*)?:(.*)$/mi).split(';').filter(Boolean).join(', ') });
    }
    return out;
}
function parseCsv(text) {
    const rows = [];
    let row = [], cell = '', q = false;
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (q) { if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (ch === '"') q = false; else cell += ch; }
        else if (ch === '"') q = true;
        else if (ch === ',') { row.push(cell); cell = ''; }
        else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = ''; }
        else cell += ch;
    }
    if (cell || row.length) { row.push(cell); rows.push(row); }
    if (rows.length < 2) return [];
    const head = rows[0].map(h => h.trim().toLowerCase());
    const col = (...names) => head.findIndex(h => names.includes(h));
    const ix = { callsign: col('callsign', 'call', 'nickname'), display_name: col('display name', 'name', 'display_name'),
                 first_name: col('first name', 'first_name'), last_name: col('last name', 'last_name'),
                 address: col('bbs address', 'address', 'bbs'), email: col('primary email', 'email', 'e-mail'),
                 phone: col('work phone', 'home phone', 'mobile number', 'phone'), grid: col('grid'), location: col('location', 'home city', 'city'), notes: col('notes') };
    return rows.slice(1).filter(r => r.some(c => c.trim())).map(r => {
        const o = {};
        for (const [k, i] of Object.entries(ix)) if (i >= 0) o[k] = (r[i] || '').trim();
        if (!o.callsign && o.address) o.callsign = o.address.split('@')[0];
        return o;
    });
}
function abExport(ks, fmt) {
    const vesc = v => String(v || '').replace(/\\/g, '\\\\').replace(/\n/g, '\\n').replace(/([,;])/g, '\\$1');
    const cesc = v => /[",\n]/.test(String(v || '')) ? '"' + String(v).replace(/"/g, '""') + '"' : String(v || '');
    const list = ks.filter(k => k.kind === 'contact');
    const text = fmt === 'vcf'
        ? list.map(k => ['BEGIN:VCARD', 'VERSION:3.0', `FN:${vesc(nameOf(k) || k.callsign)}`, `N:${vesc(k.last_name)};${vesc(k.first_name)};;;`,
            `NICKNAME:${vesc(k.callsign)}`, `X-CALLSIGN:${vesc(k.callsign)}`, `X-BBS-ADDRESS:${vesc(k.address)}`,
            k.email ? `EMAIL;TYPE=INTERNET:${k.email}` : '', k.phone ? `TEL:${vesc(k.phone)}` : '', k.grid ? `X-GRID:${vesc(k.grid)}` : '',
            k.location ? `ADR:;;${vesc(k.location)};;;;` : '', k.notes ? `NOTE:${vesc(k.notes)}` : '', 'END:VCARD'].filter(Boolean).join('\r\n')).join('\r\n') + '\r\n'
        : ['Callsign,Display Name,First Name,Last Name,BBS Address,Email,Phone,Grid,Location,Notes']
            .concat(list.map(k => [k.callsign, nameOf(k), k.first_name, k.last_name, k.address, k.email, k.phone, k.grid, k.location, k.notes].map(cesc).join(','))).join('\r\n') + '\r\n';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: fmt === 'vcf' ? 'text/vcard' : 'text/csv' }));
    a.download = `bbs-address-book.${fmt}`;
    document.body.appendChild(a); a.click(); a.remove();
}

// ── retention policy (v1.5.74), like Thunderbird's ────────────
async function loadRetention() {
    const d = await get({ action: 'msRetentionGet' });
    if (!d.success || !d.policy || d.policy.ready === false) return null;
    S.retention = d.policy;
    return d.policy;
}
async function runRetention() {
    const pol = S.retention || await loadRetention();
    if (!pol) return toast('Retention is not available (run update.sh)', 'err');
    if (!confirm(`Delete from the mail store every message past its folder's retention now?\n\nBulletins: ${pol.default_days ? 'older than ' + pol.default_days + ' days' : 'kept'}${pol.keep_starred ? ', starred ones kept' : ''}.` +
                 `\nThis removes the copies here and in Thunderbird; nothing on the BBS is touched. It can't be undone.`)) return;
    toast('Running retention cleanup…');
    const d = await post({ action: 'msRetentionRun' });
    if (!d.success) return toast(d.error || 'Failed', 'err');
    const per = Object.entries(d.folders || {}).map(([k, n]) => `${prettyFolder(k)} ${n}`).join(', ');
    toast(d.deleted ? `Removed ${d.deleted} old message${d.deleted === 1 ? '' : 's'}: ${per}` : 'Nothing past its retention', 'ok');
    await loadFolders();
    if (!S.search) loadList(false);
}
async function folderProps(name) {
    const pol = await loadRetention();
    const f = S.folders.find(x => x.name === name) || { name, messages: 0, unseen: 0 };
    const isBull = name === 'Bulletins';
    const bullGroup = name.startsWith('Bulletins/');
    const own = pol && Object.prototype.hasOwnProperty.call(pol.folders, name) ? pol.folders[name] : null;
    const defText = bullGroup ? (pol && pol.default_days ? `the bulletin default: delete after ${pol.default_days} days` : 'the bulletin default: keep') : 'the default: keep everything';
    const dlg = document.createElement('div');
    dlg.className = 'tb-modal';
    dlg.innerHTML = `<div class="tb-dlg" style="width:min(560px,94vw)"><div class="tb-dlg-h">${esc(prettyFolder(name))} — Properties</div>
      <div class="ab-form">
        <label>Folder</label><div>${esc(prettyFolder(name))}${isBull ? '' : ` · ${f.messages} message${f.messages === 1 ? '' : 's'}, ${f.unseen} unread`}</div>
        <label style="align-self:start;padding-top:4px">Retention</label><div>${!pol ? '<span class="k">Not available (run update.sh)</span>' : isBull ? `
          <label class="ab-chk"><input type="radio" name="fpr" data-v="days" ${pol.default_days ? 'checked' : ''}> Delete bulletins more than
            <input data-v="n" type="number" min="1" max="3650" value="${pol.default_days || 90}" style="width:70px;margin:0 4px"> days old</label>
          <label class="ab-chk"><input type="radio" name="fpr" data-v="never" ${pol.default_days ? '' : 'checked'}> Don't delete bulletins</label>
          <div class="k">The default for every bulletin group; a group's own Properties can override it.</div>` : `
          <label class="ab-chk"><input type="radio" name="fpr" data-v="default" ${own === null ? 'checked' : ''}> Use ${esc(defText)}</label>
          <label class="ab-chk"><input type="radio" name="fpr" data-v="never" ${own === 0 ? 'checked' : ''}> Don't delete any messages</label>
          <label class="ab-chk"><input type="radio" name="fpr" data-v="days" ${own ? 'checked' : ''}> Delete messages more than
            <input data-v="n" type="number" min="1" max="3650" value="${own || (pol.default_days || 30)}" style="width:70px;margin:0 4px"> days old</label>`}
          ${pol ? `<label class="ab-chk"><input type="checkbox" data-v="star" ${pol.keep_starred ? 'checked' : ''}> Always keep starred messages (all folders)</label>
          <div class="k">Removes the mail-store copies by the message's date, once a day; never anything on the BBS.</div>` : ''}</div>
      </div>
      <div class="ab-acts" style="justify-content:flex-end;padding:10px 16px"><button class="tbtn" data-y="cancel">Cancel</button><button class="tbtn primary" data-y="save" ${pol ? '' : 'disabled'}>OK</button></div></div>`;
    document.body.appendChild(dlg);
    const close = () => dlg.remove();
    dlg.addEventListener('mousedown', ev => { if (ev.target === dlg) close(); });
    dlg.querySelector('[data-y="cancel"]').onclick = close;
    dlg.querySelector('[data-y="save"]').onclick = async () => {
        const sel = (dlg.querySelector('input[name="fpr"]:checked') || {}).dataset || {};
        const n = Math.max(1, +dlg.querySelector('[data-v="n"]').value || 30);
        const p2 = { default_days: pol.default_days, keep_starred: dlg.querySelector('[data-v="star"]').checked, folders: Object.assign({}, pol.folders) };
        if (isBull) p2.default_days = sel.v === 'never' ? 0 : n;
        else if (sel.v === 'default') delete p2.folders[name];
        else p2.folders[name] = sel.v === 'never' ? 0 : n;
        const d = await post({ action: 'msRetentionSave', policy: p2 });
        if (!d.success) return toast(d.error || 'Not saved', 'err');
        S.retention = d.policy;
        close();
        toast('Retention saved — it runs once a day (≡ → Settings → Run retention cleanup now to run it now)', 'ok');
    };
}

// ── settings, unread in the title, notifications (v1.5.72) ─────
function openSettings() {
    closeMenus();
    const sig = S.signature || { enabled: false, text: '', replies: true, separator: true };
    const dlg = document.createElement('div');
    dlg.className = 'tb-modal';
    dlg.innerHTML = `<div class="tb-dlg"><div class="tb-dlg-h">BBS Mail Settings</div>
      <div class="ab-form">
        <label>Check for new mail</label><select data-p="refreshMin">${[1, 2, 5, 10, 0].map(v => `<option value="${v}" ${S.prefs.refreshMin === v ? 'selected' : ''}>${v ? 'every ' + v + ' minute' + (v > 1 ? 's' : '') : 'never (Get Messages only)'}</option>`).join('')}</select>
        <label>New mail</label><label class="ab-chk"><input type="checkbox" data-p="notify" ${S.prefs.notify ? 'checked' : ''}> Show a desktop notification (Inbox)</label>
        <label>Message font</label><select data-ps="msgFont">${FONTS.map(f => `<option value="${f[0]}" ${msgFontId() === f[0] ? 'selected' : ''}>${esc(f[1])}</option>`).join('')}</select>
        <label>Message text size</label><select data-p="msgSize">${[11, 12, 13, 14, 15, 16, 18, 20].map(v => `<option value="${v}" ${+S.prefs.msgSize === v ? 'selected' : ''}>${v}${v === 13 ? ' (standard)' : ''}</option>`).join('')}</select>
        <label></label><div class="font-sample" id="fontSample">R:261127/0508Z 23084@GB7BED.#21.GBR.EU<br>73 de K1AJD — The quick brown fox 0123456789</div>
        <label>Page text size</label><select data-p="uiSize">${[[12, 'Small'], [13, 'Standard'], [14, 'Large'], [16, 'Larger'], [18, 'Largest (tablets)']].map(([v, t]) => `<option value="${v}" ${+S.prefs.uiSize === v ? 'selected' : ''}>${t}</option>`).join('')}</select>
        <label>Names</label><label class="ab-chk"><input type="checkbox" data-p="names" ${S.prefs.names ? 'checked' : ''}> Show address-book names next to callsigns</label>
        <label style="align-self:start;padding-top:4px">Signature</label><div><label class="ab-chk"><input type="checkbox" data-s="enabled" ${sig.enabled ? 'checked' : ''}> Add to new messages</label>
          <label class="ab-chk"><input type="checkbox" data-s="replies" ${sig.replies ? 'checked' : ''}> … and to replies and forwards</label>
          <label class="ab-chk"><input type="checkbox" data-s="separator" ${sig.separator ? 'checked' : ''}> Put “-- ” above it</label>
          <textarea data-s="text" rows="4" maxlength="1000" placeholder="73, …">${esc(sig.text || '')}</textarea>
          <div class="k">Shared with the Email Client (Preferences), and every browser.</div></div>
        <label style="align-self:start;padding-top:4px">Other accounts</label><div><input data-acc placeholder="e.g. TPRFN" autocomplete="off" style="text-transform:uppercase">
          <div class="k">Other BBS users whose mail you also read here, commas between several (up to 10): a club or tactical call the BBS collects Winlink mail for, say. Each gets its own folder, Inbox ▸ <i>CALL</i>; what they send goes in Sent. Mail already on the BBS for them is copied within a few minutes.</div></div>
        <label style="align-self:start;padding-top:4px">Bulletin retention</label><div id="retBox"><span class="k">Loading…</span></div>
      </div>
      <div class="ab-acts" style="justify-content:flex-end;padding:10px 16px"><button class="tbtn" data-y="cancel">Cancel</button><button class="tbtn primary" data-y="save">Save</button></div></div>`;
    document.body.appendChild(dlg);
    const close = () => dlg.remove();
    dlg.addEventListener('mousedown', ev => { if (ev.target === dlg) close(); });
    dlg.querySelector('[data-y="cancel"]').onclick = close;
    // v1.5.101: show the chosen message font and size as it is picked
    const sample = () => {
        const fs = dlg.querySelector('[data-ps="msgFont"]'), sz = dlg.querySelector('[data-p="msgSize"]'), el = dlg.querySelector('#fontSample');
        const f = FONTS.find(x => x[0] === fs.value) || FONTS[0], cs = getComputedStyle(document.documentElement);
        el.style.fontFamily = f[2].replace('var(--tb-mono)', cs.getPropertyValue('--tb-mono')).replace('var(--tb-font)', cs.getPropertyValue('--tb-font'));
        el.style.fontSize = sz.value + 'px';
    };
    dlg.querySelectorAll('[data-ps="msgFont"],[data-p="msgSize"]').forEach(i => i.addEventListener('change', sample));
    sample();
    get({ action: 'msAccountsGet' }).then(d => {
        const i = dlg.querySelector('[data-acc]');
        if (i && d.success) { i.value = (d.accounts || []).map(a => a.call).join(', '); i.dataset.was = i.value; }
    });
    loadRetention().then(pol => {
        const box = dlg.querySelector('#retBox');
        if (!box) return;
        if (!pol) { box.innerHTML = '<span class="k">Not available (run update.sh)</span>'; return; }
        const own = Object.entries(pol.folders || {});
        box.innerHTML = `<label class="ab-chk"><input type="radio" name="retm" data-r="keep" ${pol.default_days ? 'checked' : ''}> Delete bulletins more than
              <input data-r="days" type="number" min="1" max="3650" value="${pol.default_days || 90}" style="width:70px;margin:0 4px"> days old</label>
            <label class="ab-chk"><input type="radio" name="retm" data-r="never" ${pol.default_days ? '' : 'checked'}> Don't delete bulletins</label>
            <label class="ab-chk"><input type="checkbox" data-r="star" ${pol.keep_starred ? 'checked' : ''}> Always keep starred messages</label>
            <div class="k">For every Bulletins ▸ group without its own setting (right-click a folder → Properties… for its own). Runs once a day; it removes the mail-store copies by the message's date, never anything on the BBS.
              ${own.length ? '<br>Own settings: ' + own.map(([k, d]) => esc(prettyFolder(k)) + ' ' + (d ? d + ' days' : 'never')).join(', ') : ''}</div>
            <button class="tbtn" data-r="run" style="margin-top:6px;border-color:var(--border)">🗓 Run retention cleanup now</button>`;
        box.querySelector('[data-r="run"]').onclick = () => runRetention();
    });
    dlg.querySelector('[data-y="save"]').onclick = async () => {
        dlg.querySelectorAll('[data-p]').forEach(i => { S.prefs[i.dataset.p] = i.type === 'checkbox' ? i.checked : +i.value; });
        dlg.querySelectorAll('[data-ps]').forEach(i => { S.prefs[i.dataset.ps] = i.value; });   // v1.5.101: text choices
        if (S.prefs.notify && 'Notification' in window && Notification.permission === 'default') await Notification.requestPermission();
        if (S.prefs.notify && (!('Notification' in window) || Notification.permission !== 'granted')) { S.prefs.notify = false; toast('The browser did not allow notifications (they need https or this computer)', 'err'); }
        lsSet('prefs', S.prefs);
        const ns = { enabled: false, text: '', replies: true, separator: true };
        dlg.querySelectorAll('[data-s]').forEach(i => { ns[i.dataset.s] = i.type === 'checkbox' ? i.checked : i.value; });
        const d = await post({ action: 'saveSignature', signature: ns });
        if (d.success) S.signature = d.signature; else toast('Signature not saved: ' + (d.error || ''), 'err');
        const rd = dlg.querySelector('[data-r="days"]');
        if (rd && S.retention) {
            const pol = Object.assign({}, S.retention, { default_days: dlg.querySelector('[data-r="never"]').checked ? 0 : Math.max(1, +rd.value || 90),
                                                          keep_starred: dlg.querySelector('[data-r="star"]').checked });
            const r = await post({ action: 'msRetentionSave', policy: pol });
            if (r.success) S.retention = r.policy; else toast('Retention not saved: ' + (r.error || ''), 'err');
        }
        const ai = dlg.querySelector('[data-acc]');
        if (ai && ai.value.trim().toUpperCase() !== (ai.dataset.was || '').toUpperCase()) {
            const r = await post({ action: 'msAccountsSave', accounts: ai.value.toUpperCase().split(/[\s,;]+/).filter(Boolean) });
            if (!r.success) return toast('Accounts not saved: ' + (r.error || ''), 'err');
            loadFolders();
            if (r.requeued) toast(`${r.requeued} message${r.requeued === 1 ? '' : 's'} already on the BBS for them will be copied within a few minutes`, 'ok');
        }
        applyPrefs(); renderRows(true); close(); toast('Settings saved', 'ok');
    };
}
// ── Quick Check-In (v1.5.75; moved here from the older Email Client) ──
// Fills in a net check-in and opens it in a Write tab: nothing is sent
// until Send there. The station details are kept on the station
// (bpq_mail_prefs 'checkin'), so every browser and PC has the same ones;
// the first time, they're brought over from the older Email Client's
// copy in this browser if there is one.
const CI_TYPES = ['Real Event', 'Exercise'];
const CI_BANDS = ['VHF', 'UHF', 'HF', 'SHF', 'AXIP'];
const CI_MODES = ['AX.25 Packet', 'VARA HF', 'VARA FM', 'Ardop', 'Pactor', 'Robust Packet', 'Mesh', 'AXIP'];
async function openCheckIn() {
    closeMenus();
    const d = await get({ action: 'msCheckInGet' });
    let p = d.success && d.saved ? d.profile : null, fromOld = false;
    if (!p) {
        try { p = JSON.parse(localStorage.getItem('bbs_v2_checkin_profile') || 'null'); fromOld = !!p; } catch (e) { p = null; }
    }
    p = p || {};
    const opt = (list, v) => list.map(x => `<option ${x === v ? 'selected' : ''}>${esc(x)}</option>`).join('');
    const dlg = document.createElement('div');
    dlg.className = 'tb-modal';
    dlg.innerHTML = `<div class="tb-dlg" style="width:min(620px,96vw)"><div class="tb-dlg-h">✅ Quick Check-In</div>
      <div class="k" style="padding:10px 16px 0">Fills in a net check-in and opens it in a Write tab: nothing is sent until you check it and press Send.
        Your details are remembered on the station, for every browser.${fromOld ? ' (Brought over from the older Email Client.)' : ''}</div>
      <div class="ab-form">
        <label>Send to</label><input data-c="netAddress" value="${esc(p.netAddress || 'PKTNET@USA')}" style="text-transform:uppercase" placeholder="PKTNET@USA or the net manager's call">
        <label>Send as</label><select data-c="msgType"><option value="B" ${p.msgType === 'P' ? '' : 'selected'}>Bulletin (SB), to a net address</option><option value="P" ${p.msgType === 'P' ? 'selected' : ''}>Personal (SP), to one station</option></select>
        <label>Your name</label><input data-c="name" value="${esc(p.name || '')}" placeholder="Jane">
        <label>Your callsign</label><input data-c="callsign" value="${esc(p.callsign || (S.user || '').toUpperCase())}" style="text-transform:uppercase">
        <label>Town</label><input data-c="town" value="${esc(p.town || '')}" placeholder="Anytown">
        <label>State</label><input data-c="state" value="${esc(p.state || '')}" placeholder="GA">
        <label>Grid square</label><input data-c="grid" value="${esc(p.grid || '')}" placeholder="EM73" style="text-transform:uppercase">
        <label>Check-in type</label><select data-c="type">${opt(CI_TYPES, p.type || 'Real Event')}</select>
        <label>Band</label><select data-c="band">${opt(CI_BANDS, p.band || 'VHF')}</select>
        <label>Mode</label><select data-c="session">${opt(CI_MODES, p.session || 'AX.25 Packet')}</select>
        <label>Connected through</label><input data-c="station" value="${esc(p.station || '')}" placeholder="the BBS or hub you checked in via">
        <label style="align-self:start;padding-top:4px">Comments</label><textarea data-c="comments" rows="3" maxlength="500" placeholder="Optional: equipment, propagation, anything worth mentioning"></textarea>
      </div>
      <div class="ab-acts" style="justify-content:flex-end;padding:10px 16px"><button class="tbtn" data-y="cancel">Cancel</button><button class="tbtn primary" data-y="go">Fill in &amp; Write</button></div></div>`;
    document.body.appendChild(dlg);
    const close = () => dlg.remove();
    dlg.addEventListener('mousedown', ev => { if (ev.target === dlg) close(); });
    dlg.addEventListener('keydown', ev => { if (ev.key === 'Escape') { ev.stopPropagation(); close(); } });
    dlg.querySelector('[data-y="cancel"]').onclick = close;
    dlg.querySelector('[data-c="name"]').focus();
    dlg.querySelector('[data-y="go"]').onclick = async () => {
        const v = {};
        dlg.querySelectorAll('[data-c]').forEach(i => { v[i.dataset.c] = i.value.trim(); });
        v.callsign = v.callsign.toUpperCase(); v.netAddress = v.netAddress.toUpperCase(); v.grid = v.grid.toUpperCase();
        if (!v.name || !v.callsign) return toast('Your name and callsign are needed', 'err');
        if (!v.netAddress) return toast('Where should the check-in go?', 'err');
        const keep = Object.assign({}, v); delete keep.comments;
        const r = await post({ action: 'msCheckInSave', profile: keep });
        if (!r.success) toast('Details not remembered: ' + (r.error || 'no answer'), 'err');
        const lines = [
            `Date/Time: ${new Date().toISOString().slice(0, 16).replace('T', ' ')}Z`,
            `Callsign: ${v.callsign}`,
            `Name: ${v.name}`,
            `Location: ${[v.town, v.state].filter(Boolean).join(', ')}`,
            v.grid ? `Grid Square: ${v.grid}` : null,
            `Check-in Type: ${v.type}`,
            `Band: ${v.band}`,
            `Mode: ${v.session}`,
            v.station ? `Via: ${v.station}` : null,
            v.comments ? `Comments: ${v.comments}` : null,
        ].filter(Boolean);
        close();
        compose('fixed', null, null, '', { title: 'Check-In', to: v.netAddress, type: v.msgType,
            subject: [v.name, v.callsign, v.town, v.state].filter(Boolean).join(', '), body: lines.join('\n') });
    };
}
// ── Tools (v1.5.78; moved here from the older Email Client) ──────
// From the server, so every browser and PC shows the same.
function toolDialog(title, width) {
    closeMenus();
    const dlg = document.createElement('div');
    dlg.className = 'tb-modal';
    dlg.innerHTML = `<div class="tb-dlg" style="width:min(${width || 860}px,96vw)"><div class="tb-dlg-h" style="display:flex;align-items:center;gap:10px"><span style="flex:1">${esc(title)}</span><span class="tool-ctl"></span><button class="tbtn" data-y="close">Close</button></div>
      <div class="tool-body" style="padding:12px 16px"><span class="k">Loading…</span></div></div>`;
    document.body.appendChild(dlg);
    const close = () => dlg.remove();
    dlg.addEventListener('mousedown', ev => { if (ev.target === dlg) close(); });
    dlg.addEventListener('keydown', ev => { if (ev.key === 'Escape') { ev.stopPropagation(); close(); } });
    dlg.querySelector('[data-y="close"]').onclick = close;
    dlg.querySelector('[data-y="close"]').focus();
    return { body: dlg.querySelector('.tool-body'), ctl: dlg.querySelector('.tool-ctl') };
}
const tTable = (heads, rows) => `<table class="tool-t"><thead><tr>${heads.map(h => `<th${h.r ? ' class="r"' : ''}>${esc(h.t || h)}</th>`).join('')}</tr></thead><tbody>${rows.join('') || `<tr><td colspan="${heads.length}" class="k">Nothing yet.</td></tr>`}</tbody></table>`;
const tType = t => ({ P: 'Personal', B: 'Bulletin', T: 'NTS' }[t] || t || '');
const tWhen = v => v ? String(v).replace('T', ' ').slice(0, 16) : '';

async function toolForwarding() {
    const d = toolDialog('Forwarding Activity: messages your BBS sent on');
    d.ctl.innerHTML = `<select data-h>${[6, 24, 72, 168].map(h => `<option value="${h}" ${h === 24 ? 'selected' : ''}>last ${h < 48 ? h + ' hours' : h / 24 + ' days'}</option>`).join('')}</select>`;
    const load = async () => {
        d.body.innerHTML = '<span class="k">Loading…</span>';
        const r = await fetch('/bbs-forwards.php?action=list&limit=500&hours=' + d.ctl.querySelector('[data-h]').value, { cache: 'no-store' });
        let j; try { j = await r.json(); } catch (e) { j = { success: false, error: 'HTTP ' + r.status }; }
        if (!j.success) { d.body.innerHTML = `<span class="k">Not available: ${esc(j.error || '')}</span>`; return; }
        const ev = j.events || [];
        d.body.innerHTML = `<div class="k" style="margin-bottom:8px">${ev.length} forward${ev.length === 1 ? '' : 's'} from ${(j.files_read || []).length} BBS log file${(j.files_read || []).length === 1 ? '' : 's'}${(j.files_read || []).length ? '' : ' (LinBPQ writes no BBS log: switch its logging on to see this)'}.</div>`
            + tTable(['Time', '→ BBS', 'Type', 'From', 'To', 'Subject', 'BID', { t: 'Size', r: 1 }], ev.map(e =>
                `<tr><td class="mono">${esc((e.time_iso || '').slice(5, 16).replace('T', ' '))}</td><td><b>${esc(e.dest_bbs || '')}</b></td><td>${esc(tType(e.msg_type))}</td><td>${esc(e.from || '')}</td><td>${esc(e.to_addr || '')}</td><td>${esc(e.subject || '')}</td><td class="mono">${esc(e.bid || '')}</td><td class="r">${fmtSize(e.size || 0)}</td></tr>`));
    };
    d.ctl.querySelector('[data-h]').onchange = load;
    load();
}

async function toolBbsSeen() {
    const d = toolDialog('BBSes Seen: in senders\' addresses, last 180 days');
    const j = await get({ action: 'bbsSeen' });
    if (!j.success) { d.body.innerHTML = `<span class="k">Not available: ${esc(j.error || '')}</span>`; return; }
    const list = j.bbses || [];
    d.body.innerHTML = `<div class="k" style="margin-bottom:8px">${list.length} BBS${list.length === 1 ? '' : 'es'} from ${j.scanned} message${j.scanned === 1 ? '' : 's'}. The route is the longest seen; use it to address mail (CALL@route).</div>`
        + tTable(['BBS', 'Route', { t: 'Personal', r: 1 }, { t: 'Bulletins', r: 1 }, 'Last seen', 'Senders'], list.map(b =>
            `<tr><td><b>${esc(b.bbs)}</b></td><td class="mono">${esc(b.route)}</td><td class="r">${b.personal}</td><td class="r">${b.bulletins}</td><td>${esc(tWhen(b.last))}</td><td>${esc(b.senders.join(', '))}</td></tr>`));
}

async function toolStats() {
    const d = toolDialog('Statistics', 720);
    const j = await get({ action: 'mailStats' });
    if (!j.success) { d.body.innerHTML = `<span class="k">Not available: ${esc(j.error || '')}</span>`; return; }
    const by = j.byType || [];
    const tot = k => by.reduce((a, r) => a + (+r[k] || 0), 0);
    const store = S.folders.filter(f => f.selectable !== false);
    const sum = (pred, k) => store.filter(pred).reduce((a, f) => a + (+f[k] || 0), 0);
    const isB = f => f.name === 'Bulletins' || f.name.startsWith('Bulletins/');
    d.body.innerHTML = `<h4 class="tool-h">Seen by the dashboard (from the BBS, every 5 minutes)</h4>`
        + tTable(['Type', { t: 'Total', r: 1 }, { t: 'Last day', r: 1 }, { t: '7 days', r: 1 }, { t: '30 days', r: 1 }, { t: 'With text', r: 1 }, 'Since'],
            by.map(r => `<tr><td>${esc(tType(r.msg_type))}</td><td class="r">${r.n}</td><td class="r">${r.d1 || 0}</td><td class="r">${r.d7 || 0}</td><td class="r">${r.d30 || 0}</td><td class="r">${r.texts || 0}</td><td>${esc(tWhen(r.since).slice(0, 10))}</td></tr>`)
            .concat([`<tr class="tot"><td>All</td><td class="r">${tot('n')}</td><td class="r">${tot('d1')}</td><td class="r">${tot('d7')}</td><td class="r">${tot('d30')}</td><td class="r">${tot('texts')}</td><td></td></tr>`]))
        + `<h4 class="tool-h">In the mail store now</h4>`
        + tTable(['Folders', { t: 'Messages', r: 1 }, { t: 'Unread', r: 1 }], [
            ['Inbox and its folders', f => f.name === 'INBOX' || f.name.startsWith('INBOX/')], ['Sent', f => f.special === 'sent'],
            ['Bulletins', isB], ['Trash', f => f.special === 'trash']].map(([n, p]) =>
            `<tr><td>${esc(n)}</td><td class="r">${sum(p, 'messages')}</td><td class="r">${sum(p, 'unseen')}</td></tr>`))
        + `<div style="display:flex;gap:16px;flex-wrap:wrap"><div style="flex:1;min-width:240px"><h4 class="tool-h">Busiest senders, 30 days</h4>`
        + tTable(['Call', { t: 'Messages', r: 1 }], (j.senders || []).map(r => `<tr><td>${esc(r.who)}</td><td class="r">${r.n}</td></tr>`))
        + `</div><div style="flex:1;min-width:240px"><h4 class="tool-h">Busiest bulletin groups, 30 days</h4>`
        + tTable(['Group', { t: 'Bulletins', r: 1 }], (j.groups || []).map(r => `<tr><td>${esc(r.grp)}</td><td class="r">${r.n}</td></tr>`))
        + `</div></div>`
        + (j.kills ? `<div class="k" style="margin-top:8px">Killed on the BBS from the mail store: ${j.kills.killed || 0}; waiting: ${j.kills.waiting || 0} (≡ → Tools → Killed on the BBS).</div>` : '');
}

async function toolKills() {
    const d = toolDialog('Killed on the BBS');
    const j = await get({ action: 'killList' });
    if (!j.success) { d.body.innerHTML = `<span class="k">Not available: ${esc(j.error || '')}</span>`; return; }
    const src = { 'mail-app': 'Emptied Trash', trash: 'Trash', 'email-client': 'Email Client', expired: 'Retention' };
    const res = r => !r.done_at ? `<span class="pill wait">waiting${r.tries ? ' (' + r.tries + ' tries)' : ''}</span>`
        : `<span class="pill ${r.result === 'killed' ? 'ok' : 'bad'}">${esc(r.result || 'done')}</span>`;
    d.body.innerHTML = `<div class="k" style="margin-bottom:8px">Messages that left the mail store's Trash are killed on the BBS by the next message-text run (every 5 minutes), after a check that the number still holds the same message.</div>`
        + tTable(['Queued', 'BBS #', 'Type', 'From', 'Subject', 'How', 'Result', 'Done'], (j.queue || []).map(r =>
            `<tr><td>${esc(tWhen(r.queued_at))}</td><td class="mono">${esc(r.bbs_number)}</td><td>${esc(tType(r.msg_type))}</td><td>${esc(r.from_call)}</td><td>${esc(r.subject)}</td><td>${esc(src[r.source] || r.source)}</td><td>${res(r)}</td><td>${esc(tWhen(r.done_at))}</td></tr>`))
        + ((j.kept || []).length ? `<h4 class="tool-h">Kept in the dashboard's archive after a kill</h4>` + tTable(['Killed', 'BBS #', 'Type', 'From', 'Subject'], j.kept.map(r =>
            `<tr><td>${esc(tWhen(r.killed_on_bbs_at))}</td><td class="mono">${esc(r.bbs_number)}</td><td>${esc(tType(r.msg_type))}</td><td>${esc(r.from_call)}</td><td>${esc(r.subject)}</td></tr>`)) : '');
}

// v1.5.101: font choices for message text (and Write), and the page's text size
const FONTS = [
    ['mono',    'Fixed-width (keeps BBS tables lined up)', 'var(--tb-mono)'],
    ['courier', 'Courier New (fixed-width)',               '"Courier New", Courier, "Liberation Mono", monospace'],
    ['dejavu',  'DejaVu Sans Mono (fixed-width)',          '"DejaVu Sans Mono", "Liberation Mono", monospace'],
    ['sans',    'Sans-serif (as the rest of the page)',    'var(--tb-font)'],
    ['arial',   'Arial',                                   'Arial, Helvetica, "Liberation Sans", sans-serif'],
    ['verdana', 'Verdana (wide, easy to read)',            'Verdana, Geneva, "DejaVu Sans", sans-serif'],
    ['serif',   'Serif (Georgia)',                         'Georgia, "Times New Roman", "DejaVu Serif", serif'],
];
function msgFontId() { return FONTS.some(f => f[0] === S.prefs.msgFont) ? S.prefs.msgFont : (S.prefs.mono === false ? 'sans' : 'mono'); }
function applyPrefs() {
    const f = FONTS.find(x => x[0] === msgFontId());
    const root = document.documentElement.style;
    root.setProperty('--msg-font', f[2]);
    root.setProperty('--msg-size', Math.max(10, Math.min(24, +S.prefs.msgSize || 13)) + 'px');
    root.setProperty('--ui-size', Math.max(11, Math.min(20, +S.prefs.uiSize || 13)) + 'px');
    S.prefs.mono = ['mono', 'courier', 'dejavu'].includes(msgFontId());
    document.body.classList.toggle('prop-font', false);
    clearInterval(S.refreshTimer);
    if (S.prefs.refreshMin > 0) S.refreshTimer = setInterval(() => { if (!document.hidden && S.tab === 'mail' && !S.loading) getMessages(true); }, S.prefs.refreshMin * 60000);
}
function updateTitle() {
    const inbox = S.folders.find(f => f.name === 'INBOX');
    const n = inbox ? inbox.unseen : 0;
    document.title = (n ? `(${n}) ` : '') + 'BBS Mail - BPQ Dashboard';
    if (S.lastInboxUnseen != null && n > S.lastInboxUnseen && S.prefs.notify && 'Notification' in window && Notification.permission === 'granted' && document.hidden) {
        try {
            const nn = new Notification('BBS Mail', { body: `${n - S.lastInboxUnseen} new message${n - S.lastInboxUnseen === 1 ? '' : 's'} in Inbox`, icon: '/favicon.svg', tag: 'bbs-mail' });
            nn.onclick = () => { window.focus(); selectFolder('INBOX'); nn.close(); };
        } catch (e) { /* not allowed */ }
    }
    S.lastInboxUnseen = n;
}

// ── Message Filters (v1.5.73), like Thunderbird's ──────────────
const MF_FIELDS = [['from', 'From (callsign)'], ['to', 'To'], ['subject', 'Subject'], ['body', 'Body'], ['type', 'Type'], ['group', 'Bulletin group']];
const MF_OPS = [['contains', 'contains'], ['not_contains', "doesn't contain"], ['is', 'is'], ['is_not', "isn't"], ['begins', 'begins with'], ['ends', 'ends with']];
let MF = { list: [], sel: null };
async function loadFilters() {
    const d = await get({ action: 'mfList' });
    if (!d.success) { toast(d.error || 'Filters not available', 'err'); return false; }
    MF.list = d.filters || [];
    return true;
}
function mfSummary(f) {
    const c = f.conditions.map(c => `${(MF_FIELDS.find(x => x[0] === c.field) || [, c.field])[1]} ${(MF_OPS.find(x => x[0] === c.op) || [, c.op])[1]} “${c.value}”`)
        .join(f.match_all ? ' and ' : ' or ');
    const a = [f.actions.move ? 'move to ' + prettyFolder(f.actions.move) : '', f.actions.read ? 'mark read' : '', f.actions.star ? 'star' : '', f.actions.stop ? 'stop' : ''].filter(Boolean).join(', ');
    return `If ${c} → ${a}`;
}
async function openFilters() {
    closeMenus();
    if (!(await loadFilters())) return;
    let dlg = document.getElementById('mfDlg');
    if (dlg) dlg.remove();
    dlg = document.createElement('div');
    dlg.className = 'tb-modal'; dlg.id = 'mfDlg';
    dlg.innerHTML = `<div class="tb-dlg" style="width:min(820px,96vw)"><div class="tb-dlg-h">Message Filters</div>
      <div class="mf-wrap">
        <div class="mf-list" id="mfList" tabindex="0"></div>
        <div class="mf-side">
          <button class="tbtn primary" data-m="new">＋ New…</button>
          <button class="tbtn" data-m="edit">Edit…</button>
          <button class="tbtn" data-m="del">Delete</button>
          <div class="tb-sep" style="height:1px;width:auto;margin:6px 0"></div>
          <button class="tbtn" data-m="up">▲ Move Up</button>
          <button class="tbtn" data-m="down">▼ Move Down</button>
        </div>
      </div>
      <div class="mf-run"><span>Run selected filter on</span> <select id="mfRunFolder">${S.folders.filter(f => f.selectable && f.special !== 'trash').map(f => `<option value="${esc(f.name)}" ${f.name === S.folder ? 'selected' : ''}>${esc(prettyFolder(f.name))}</option>`).join('')}</select>
        <button class="tbtn" data-m="run">▶ Run Now</button><button class="tbtn" data-m="runall" title="Every filter ticked for manual runs, in order">Run All</button></div>
      <div class="k" style="padding:0 16px 8px">Filters run in this order on new BBS mail as it is copied into the mail store (within about 2 minutes), so it lands straight in the folder you chose. Filters also work for Thunderbird, because they act on the mail store itself.</div>
      <div class="ab-acts" style="justify-content:flex-end;padding:8px 16px 12px"><button class="tbtn primary" data-m="close">Close</button></div></div>`;
    document.body.appendChild(dlg);
    const render = () => {
        $('mfList').innerHTML = MF.list.length ? MF.list.map(f => `<div class="mf-row ${MF.sel === f.id ? 'sel' : ''}" data-id="${f.id}">
            <input type="checkbox" data-en="${f.id}" ${f.enabled ? 'checked' : ''} title="Enabled">
            <div class="mf-txt"><div class="n">${esc(f.name)}</div><div class="k">${esc(mfSummary(f))}</div></div>
            <div class="k" title="Messages it has acted on">${f.hits ? f.hits + '×' : ''}</div></div>`).join('')
            : '<div class="mv-empty" style="padding:30px">No filters yet. ＋ New…, or right-click a message → Create Filter From Message.</div>';
        $('mfList').querySelectorAll('.mf-row').forEach(el => {
            const id = +el.dataset.id;
            el.addEventListener('click', ev => { if (ev.target.matches('input')) return; MF.sel = id; render(); });
            el.addEventListener('dblclick', () => editFilter(MF.list.find(f => f.id === id)));
        });
        $('mfList').querySelectorAll('[data-en]').forEach(cb => cb.addEventListener('change', async () => {
            const f = MF.list.find(x => x.id === +cb.dataset.en);
            const d = await post({ action: 'mfSave', filter: Object.assign({}, f, { enabled: cb.checked }) });
            if (!d.success) { cb.checked = !cb.checked; return toast(d.error, 'err'); }
            MF.list = d.filters;
        }));
    };
    MF.render = render;
    render();
    const reorder = async delta => {
        const i = MF.list.findIndex(f => f.id === MF.sel);
        const j = i + delta;
        if (i < 0 || j < 0 || j >= MF.list.length) return;
        const ids = MF.list.map(f => f.id);
        [ids[i], ids[j]] = [ids[j], ids[i]];
        const d = await post({ action: 'mfOrder', ids });
        if (d.success) { MF.list = d.filters; render(); }
    };
    dlg.addEventListener('mousedown', ev => { if (ev.target === dlg) dlg.remove(); });
    dlg.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', async () => {
        const m = b.dataset.m, cur = MF.list.find(f => f.id === MF.sel);
        if (m === 'close') dlg.remove();
        else if (m === 'new') editFilter(null);
        else if (m === 'edit') { if (cur) editFilter(cur); }
        else if (m === 'del') {
            if (!cur || !confirm(`Delete the filter “${cur.name}”?`)) return;
            const d = await post({ action: 'mfDelete', ids: [cur.id] });
            if (d.success) { MF.list = d.filters; MF.sel = null; render(); }
        }
        else if (m === 'up') reorder(-1);
        else if (m === 'down') reorder(1);
        else if (m === 'run' || m === 'runall') {
            if (m === 'run' && !cur) return toast('Select a filter first', 'err');
            runFilters($('mfRunFolder').value, m === 'run' ? [cur.id] : null);
        }
    }));
}
async function runFilters(folder, ids) {
    toast(`Running filters on ${prettyFolder(folder)}…`);
    const d = await post({ action: 'mfRun', folder, ids: ids || [] });
    if (!d.success) return toast(d.error || 'Failed', 'err');
    toast(`${prettyFolder(folder)}: ${d.matched} of ${d.checked} matched — ${d.moved} moved, ${d.read} marked read, ${d.starred} starred`, 'ok');
    await loadFolders();
    if (!S.search) loadList(false);
    if (MF.render && document.getElementById('mfDlg')) { await loadFilters(); MF.render(); }
}
function filterFromMessage(m) {
    if (!m) return;
    const bull = m.bbsType === 'Bulletin';
    editFilter(null, {
        name: bull ? `Bulletins ${String(m.to || '').split('@')[0]}` : `From ${m.from}`,
        conditions: [bull ? { field: 'group', op: 'is', value: String(m.to || '').split('@')[0] } : { field: 'from', op: 'is', value: String(m.from || '').replace(/-\d+$/, '') }],
    });
}
function editFilter(f, preset) {
    const c = Object.assign({ name: '', enabled: true, match_all: true, on_new: true, on_manual: true,
        conditions: [{ field: 'from', op: 'is', value: '' }], actions: { move: '', read: false, star: false, stop: false } }, f || {}, preset || {});
    c.conditions = JSON.parse(JSON.stringify(c.conditions));
    c.actions = Object.assign({ move: '', read: false, star: false, stop: false }, c.actions);
    const dlg = document.createElement('div');
    dlg.className = 'tb-modal';
    const folders = S.folders.filter(x => x.selectable && x.special !== 'trash');
    const folderOpts = sel => `<option value="">(don't move)</option>` + folders.map(x => `<option value="${esc(x.name)}" ${x.name === sel ? 'selected' : ''}>${esc(prettyFolder(x.name))}</option>`).join('')
        + (sel && !folders.some(x => x.name === sel) ? `<option value="${esc(sel)}" selected>${esc(prettyFolder(sel))} (new)</option>` : '') + `<option value="__new">New folder in Inbox…</option>`;
    const condRow = (k, i) => `<div class="mf-cond" data-i="${i}">
        <select data-c="field">${MF_FIELDS.map(([v, l]) => `<option value="${v}" ${k.field === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <select data-c="op">${MF_OPS.map(([v, l]) => `<option value="${v}" ${k.op === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        ${k.field === 'type' ? `<select data-c="value">${['Personal', 'NTS', 'Bulletin'].map(v => `<option ${k.value === v ? 'selected' : ''}>${v}</option>`).join('')}</select>`
                             : `<input data-c="value" value="${esc(k.value)}" placeholder="${k.field === 'from' ? 'N3MEL' : k.field === 'group' ? 'WX' : k.field === 'to' ? 'SYSOP or WX@ALLUS' : 'text'}">`}
        <button class="tbtn" data-c="add" title="Add a condition">＋</button><button class="tbtn" data-c="rm" title="Remove" ${c.conditions.length < 2 ? 'disabled' : ''}>－</button></div>`;
    const draw = () => {
        dlg.innerHTML = `<div class="tb-dlg" style="width:min(760px,96vw)"><div class="tb-dlg-h">${f ? 'Edit Filter' : 'New Filter'}</div>
          <div class="ab-form">
            <label>Filter name</label><input data-f="name" value="${esc(c.name)}" maxlength="100" placeholder="e.g. Net traffic to Club folder">
            <label>Apply</label><div><label class="ab-chk"><input type="checkbox" data-f="on_new" ${c.on_new ? 'checked' : ''}> to new mail as it arrives</label>
              <label class="ab-chk"><input type="checkbox" data-f="on_manual" ${c.on_manual ? 'checked' : ''}> when run by hand (Run All)</label></div>
            <label></label><div><label class="ab-chk" style="display:inline-flex!important"><input type="radio" name="mfm" data-f="all" ${c.match_all ? 'checked' : ''}> Match all of the following</label>
              &nbsp; <label class="ab-chk" style="display:inline-flex!important"><input type="radio" name="mfm" data-f="any" ${c.match_all ? '' : 'checked'}> Match any of the following</label></div>
            <label style="align-self:start;padding-top:6px">If</label><div id="mfConds">${c.conditions.map(condRow).join('')}</div>
            <label style="align-self:start;padding-top:6px">Then</label><div>
              <div class="mf-cond"><span style="min-width:120px">Move to folder</span><select data-a="move" style="flex:1">${folderOpts(c.actions.move)}</select></div>
              <label class="ab-chk"><input type="checkbox" data-a="read" ${c.actions.read ? 'checked' : ''}> Mark as read</label>
              <label class="ab-chk"><input type="checkbox" data-a="star" ${c.actions.star ? 'checked' : ''}> Star it</label>
              <label class="ab-chk"><input type="checkbox" data-a="stop" ${c.actions.stop ? 'checked' : ''}> Stop filter processing (later filters skip it)</label>
              <div class="k">A move ends filtering for that message. Nothing here changes the BBS.</div></div>
          </div>
          <div class="ab-acts" style="justify-content:flex-end;padding:8px 16px 12px"><button class="tbtn" data-y="cancel">Cancel</button><button class="tbtn primary" data-y="save">Save</button></div></div>`;
        const read = () => {
            c.name = dlg.querySelector('[data-f="name"]').value;
            c.on_new = dlg.querySelector('[data-f="on_new"]').checked;
            c.on_manual = dlg.querySelector('[data-f="on_manual"]').checked;
            c.match_all = dlg.querySelector('[data-f="all"]').checked;
            dlg.querySelectorAll('.mf-cond[data-i]').forEach(r => {
                const k = c.conditions[+r.dataset.i];
                k.field = r.querySelector('[data-c="field"]').value; k.op = r.querySelector('[data-c="op"]').value; k.value = r.querySelector('[data-c="value"]').value;
            });
            const mv = dlg.querySelector('[data-a="move"]').value;
            if (mv !== '__new') c.actions.move = mv;
            ['read', 'star', 'stop'].forEach(k => { c.actions[k] = dlg.querySelector(`[data-a="${k}"]`).checked; });
        };
        dlg.querySelectorAll('.mf-cond[data-i]').forEach(r => {
            const i = +r.dataset.i;
            r.querySelector('[data-c="field"]').addEventListener('change', () => { read(); if (c.conditions[i].field === 'type') c.conditions[i].value = 'Bulletin'; draw(); });
            r.querySelector('[data-c="add"]').addEventListener('click', () => { read(); c.conditions.splice(i + 1, 0, { field: 'subject', op: 'contains', value: '' }); draw(); });
            r.querySelector('[data-c="rm"]').addEventListener('click', () => { read(); c.conditions.splice(i, 1); draw(); });
        });
        dlg.querySelector('[data-a="move"]').addEventListener('change', ev => {
            if (ev.target.value !== '__new') return;
            const n = prompt('New folder in Inbox:');
            read();
            if (n && n.trim() && !/[\/\\*%"]/.test(n)) c.actions.move = 'INBOX/' + n.trim();
            draw();
        });
        dlg.querySelector('[data-y="cancel"]').onclick = () => dlg.remove();
        dlg.querySelector('[data-y="save"]').onclick = async () => {
            read();
            const d = await post({ action: 'mfSave', filter: Object.assign({}, c, { id: f ? f.id : 0 }) });
            if (!d.success) return toast(d.error || 'Not saved', 'err');
            MF.list = d.filters; MF.sel = d.id;
            dlg.remove();
            toast('Filter saved', 'ok');
            if (MF.render && document.getElementById('mfDlg')) MF.render();
            else if (confirm('Filter saved. Run it now on ' + prettyFolder(S.folder) + ' too?')) runFilters(S.folder, [d.id]);
        };
        const first = dlg.querySelector('[data-f="name"]'); if (first && !c.name) first.focus();
    };
    document.body.appendChild(dlg);
    dlg.addEventListener('mousedown', ev => { if (ev.target === dlg) dlg.remove(); });
    draw();
}

// ── start ──────────────────────────────────────────────────────
async function start() {
    placeUnderNav();
    new MutationObserver(placeUnderNav).observe(document.body, { childList: true });
    addEventListener('resize', placeUnderNav);
    setTimeout(placeUnderNav, 400); setTimeout(placeUnderNav, 1500);
    if (innerWidth <= 760) $('btnFolders').style.display = '';
    initSplitters(); wireList(); wireFilters(); wireKeys(); wireToolbar(); applyFocus(); renderTabs();
    const st = await get({ action: 'msStatus' });
    if (!st.success || !st.enabled) {
        $('paneMail').innerHTML = `<div class="notice"><b>The BBS mail store isn't set up on this station${st.success ? '' : ' (or isn\'t answering)'}.</b><br>
            ${esc(st.reason || st.error || '')}<br><br>BBS Mail shows the station's mail store, the same folders Thunderbird reads.
            The sysop sets it up once with <code>sudo bash &lt;web root&gt;/tools/bbs-mail-setup.sh</code> (Installation Manual §10.19).<br><br>
            Until then the older <a href="/bbs-messages.html">Email Client</a> is used.</div>`;
        return;
    }
    S.user = st.user || '';
    status();
    get({ action: 'addresses' }).then(d => { if (d.success) S.addresses = d.addresses || []; });
    get({ action: 'signature' }).then(d => { if (d.success) S.signature = d.signature; });
    loadContacts().then(() => renderRows(true));
    applyPrefs();
    await loadFolders();
    setTabTitle('mail', leafName(S.folder));
    S.lastRefresh = new Date();
    await loadList(false);
    $('listWrap').focus();

}
document.addEventListener('DOMContentLoaded', start);
})();
