<?php
/**
 * includes/imap-client.php — a small IMAP client for the BBS mail store
 * (BPQ Dashboard v1.5.68).
 *
 * Talks to the station's own Dovecot (tools/bbs-mail-setup.sh) on
 * 127.0.0.1:143 with the dashboard's login from /etc/bpq-dashboard/bbs-mail.php
 * ("<user>*bpqdash", a Dovecot master login that works from this computer
 * only). No PHP imap extension needed (it's gone from PHP 8.4).
 *
 * Only what the Email Client's "Mail store" view needs: LIST with STATUS,
 * EXAMINE/SELECT, UID SORT/SEARCH, UID FETCH (headers, whole message, flags),
 * UID STORE, UID MOVE. Mailbox names are modified UTF-7 on the wire and
 * UTF-8 everywhere else.
 */

const BPQ_MAILSTORE_SECRET = '/etc/bpq-dashboard/bbs-mail.php';

class BpqImapError extends RuntimeException {}

class BpqImap
{
    private $s = null;
    private int $tag = 0;
    public array $caps = [];

    public function __construct(string $host = '127.0.0.1', int $port = 143, int $timeout = 8)
    {
        $errno = 0; $errstr = '';
        $this->s = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout);
        if (!$this->s) throw new BpqImapError("The mail store isn't answering on $host:$port ($errstr)");
        stream_set_timeout($this->s, $timeout);
        $greet = $this->line();
        if (strpos($greet, '* OK') !== 0) throw new BpqImapError('Unexpected greeting from the mail store');
    }

    public function __destruct()
    {
        if ($this->s) { @fwrite($this->s, "Z LOGOUT\r\n"); @fclose($this->s); }
    }

    /** Settings from /etc/bpq-dashboard/bbs-mail.php, or null when the mail store isn't set up. */
    public static function settings(): ?array
    {
        if (!@is_readable(BPQ_MAILSTORE_SECRET)) return null;
        $c = @include BPQ_MAILSTORE_SECRET;
        return (is_array($c) && !empty($c['user']) && !empty($c['pass'])) ? $c : null;
    }

    public static function open(): self
    {
        $c = self::settings();
        if (!$c) throw new BpqImapError('The mail store is not set up for the dashboard (run tools/bbs-mail-setup.sh)');
        $im = new self($c['host'] ?? '127.0.0.1', (int)($c['port'] ?? 143));
        $login = $c['user'] . (!empty($c['master']) ? '*' . $c['master'] : '');
        $im->cmd('LOGIN ' . self::q($login) . ' ' . self::q($c['pass']));
        return $im;
    }

    // ── wire ──────────────────────────────────────────────────────────────
    private function line(): string
    {
        $l = fgets($this->s);
        if ($l === false) {
            $m = stream_get_meta_data($this->s);
            throw new BpqImapError(!empty($m['timed_out']) ? 'The mail store took too long to answer' : 'The mail store closed the connection');
        }
        return $l;
    }

    private function bytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->s, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $m = stream_get_meta_data($this->s);
                if (!empty($m['timed_out']) || feof($this->s)) throw new BpqImapError('The mail store connection broke');
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** One response, literals folded in: returns the raw text with each literal's bytes inline as {n}\r\n<bytes>. */
    private function response(): string
    {
        $out = '';
        while (true) {
            $l = $this->line();
            $out .= $l;
            if (preg_match('/\{(\d+)\+?\}\r\n$/', $l, $m)) { $out .= $this->bytes((int)$m[1]); continue; }
            return $out;
        }
    }

    /** Runs a command; returns the untagged responses (raw). Throws on NO/BAD. */
    /** The last tagged OK line (APPENDUID and the like). */
    public string $lastOk = '';

    /**
     * v1.5.78: store a message in $box (Drafts), returns its new UID (0 if
     * the server doesn't say). $raw is a whole RFC 822 message.
     */
    public function append(string $box, string $raw, array $flags = []): int
    {
        $raw = preg_replace("/\r?\n/", "\r\n", $raw);
        $f = $flags ? ' (' . implode(' ', array_map(fn($x) => preg_replace('/[^A-Za-z\\\\]/', '', $x), $flags)) . ')' : '';
        $this->cmd('APPEND ' . self::boxOut($box) . $f . ' {' . strlen($raw) . "+}\r\n" . $raw);
        return preg_match('/\[APPENDUID \d+ (\d+)\]/i', $this->lastOk, $m) ? (int)$m[1] : 0;
    }

    public function cmd(string $c): array
    {
        $t = 'A' . (++$this->tag);
        if (fwrite($this->s, "$t $c\r\n") === false) throw new BpqImapError('Could not write to the mail store');
        $untagged = [];
        while (true) {
            $r = $this->response();
            if (strpos($r, "$t ") === 0) {
                if (preg_match('/^' . $t . ' OK/i', $r)) { $this->lastOk = $r; return $untagged; }
                $msg = trim(preg_replace('/^' . $t . ' (NO|BAD)\s*(\[[^\]]*\]\s*)?/i', '', $r));
                $msg = trim(preg_replace('/\s*\(\d+\.\d+ \+ [^)]*secs\)\.?$/', '', $msg));
                if (stripos($c, 'LOGIN ') === 0) throw new BpqImapError('The mail store refused the dashboard\'s login (' . $msg . '): run tools/bbs-mail-setup.sh again');
                throw new BpqImapError('Mail store: ' . $msg);
            }
            $untagged[] = $r;
        }
    }

    public static function q(string $s): string
    {
        if (preg_match('/[\r\n\x00]/', $s)) throw new BpqImapError('Invalid characters');
        if (preg_match('/[\x80-\xff]/', $s)) return '{' . strlen($s) . "+}\r\n" . $s;   // LITERAL+
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    public static function boxOut(string $utf8): string
    {
        return self::q(function_exists('mb_convert_encoding') ? mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8') : $utf8);
    }

    public static function boxIn(string $wire): string
    {
        return function_exists('mb_convert_encoding') ? mb_convert_encoding($wire, 'UTF-8', 'UTF7-IMAP') : $wire;
    }

    // ── response parsing ─────────────────────────────────────────────────
    /** Parses an IMAP response text (after "* ") into nested arrays of strings; NIL becomes null. */
    public static function parse(string $s, int &$i = 0, bool $inList = false): array
    {
        $out = []; $n = strlen($s);
        while ($i < $n) {
            $ch = $s[$i];
            if ($ch === ' ' || $ch === "\r" || $ch === "\n") { $i++; continue; }
            if ($ch === '(') { $i++; $out[] = self::parse($s, $i, true); continue; }
            if ($ch === ')') { $i++; if ($inList) return $out; continue; }
            if ($ch === '"') {
                $i++; $v = '';
                while ($i < $n && $s[$i] !== '"') { if ($s[$i] === '\\' && $i + 1 < $n) $i++; $v .= $s[$i++]; }
                $i++; $out[] = $v; continue;
            }
            if ($ch === '{' && preg_match('/\{(\d+)\+?\}\r\n/A', $s, $m, 0, $i)) {
                $i += strlen($m[0]); $out[] = substr($s, $i, (int)$m[1]); $i += (int)$m[1]; continue;
            }
            // atom, possibly with [section (list)] inside, like BODY[HEADER.FIELDS (FROM)]
            $v = ''; $depth = 0;
            while ($i < $n) {
                $c = $s[$i];
                if ($c === '[') $depth++;
                if ($c === ']') $depth--;
                if ($depth <= 0 && ($c === ' ' || $c === '(' || $c === ')' || $c === "\r" || $c === "\n")) break;
                $v .= $c; $i++;
            }
            $out[] = (strtoupper($v) === 'NIL') ? null : $v;
        }
        return $out;
    }

    /** FETCH responses -> [uid => [ITEM => value]] */
    private static function fetchMap(array $untagged): array
    {
        $res = [];
        foreach ($untagged as $r) {
            if (!preg_match('/^\* \d+ FETCH /i', $r)) continue;
            $i = 0;
            $p = self::parse(substr($r, 2), $i);
            $items = $p[2] ?? [];
            $map = [];
            for ($k = 0; $k + 1 < count($items); $k += 2) $map[strtoupper((string)$items[$k])] = $items[$k + 1];
            if (isset($map['UID'])) $res[(int)$map['UID']] = $map;
        }
        return $res;
    }

    // ── commands ─────────────────────────────────────────────────────────
    /** All folders with message and unread counts. */
    public function folders(): array
    {
        $un = $this->cmd('LIST "" "*" RETURN (SPECIAL-USE STATUS (MESSAGES UNSEEN))');
        $boxes = []; $status = [];
        foreach ($un as $r) {
            $i = 0;
            if (preg_match('/^\* LIST /i', $r)) {
                $p = self::parse(substr($r, 7), $i);      // (flags) "sep" name
                $flags = array_map('strtolower', (array)($p[0] ?? []));
                $wire = (string)($p[2] ?? '');
                $boxes[$wire] = ['flags' => $flags, 'sep' => (string)($p[1] ?? '/')];
            } elseif (preg_match('/^\* STATUS /i', $r)) {
                $p = self::parse(substr($r, 9), $i);      // name (MESSAGES n UNSEEN n)
                $wire = (string)($p[0] ?? ''); $kv = (array)($p[1] ?? []);
                $st = [];
                for ($k = 0; $k + 1 < count($kv); $k += 2) $st[strtoupper($kv[$k])] = (int)$kv[$k + 1];
                $status[$wire] = $st;
            }
        }
        $out = [];
        foreach ($boxes as $wire => $b) {
            $name = self::boxIn($wire);
            $special = '';
            foreach (['\\sent' => 'sent', '\\trash' => 'trash', '\\drafts' => 'drafts', '\\junk' => 'junk'] as $f => $sp) {
                if (in_array($f, $b['flags'], true)) $special = $sp;
            }
            if (strtoupper($name) === 'INBOX') { $name = 'INBOX'; $special = 'inbox'; }
            $out[] = [
                'name'       => $name,
                'selectable' => !in_array('\\noselect', $b['flags'], true),
                'special'    => $special,
                'messages'   => $status[$wire]['MESSAGES'] ?? 0,
                'unseen'     => $status[$wire]['UNSEEN'] ?? 0,
            ];
        }
        return $out;
    }

    public function examine(string $box, bool $write = false): int
    {
        $un = $this->cmd(($write ? 'SELECT ' : 'EXAMINE ') . self::boxOut($box));
        foreach ($un as $r) if (preg_match('/^\* (\d+) EXISTS/i', $r, $m)) return (int)$m[1];
        return 0;
    }

    /** UIDs newest first (by the message's Date), optionally only those matching $text. */
    public function sortedUids(string $text = '', bool $unseenOnly = false): array
    {
        return $this->query(['text' => $text, 'unseen' => $unseenOnly]);
    }

    /**
     * v1.5.71: UIDs matching $f, sorted. $f: text, fields (from,to,subject,body;
     * empty = whole message), unseen, flagged, sort (date|arrival|from|to|subject|size),
     * desc (bool, default true).
     */
    public function query(array $f): array
    {
        $crit = [];
        if (!empty($f['unseen'])) $crit[] = 'UNSEEN';
        if (!empty($f['flagged'])) $crit[] = 'FLAGGED';
        $text = (string)($f['text'] ?? '');
        if ($text !== '') {
            $map = ['from' => 'FROM', 'to' => 'TO', 'subject' => 'SUBJECT', 'body' => 'BODY'];
            $keys = array_values(array_intersect(array_keys($map), (array)($f['fields'] ?? [])));
            if (!$keys) {
                $crit[] = 'TEXT ' . self::q($text);
            } else {
                $parts = array_map(fn($k) => $map[$k] . ' ' . self::q($text), $keys);
                $expr = array_pop($parts);
                while ($parts) $expr = 'OR ' . array_pop($parts) . ' ' . $expr;
                $crit[] = count($keys) > 1 ? "($expr)" : $expr;
            }
        }
        $c = $crit ? implode(' ', $crit) : 'ALL';
        $by = ['date' => 'DATE', 'arrival' => 'ARRIVAL', 'from' => 'FROM', 'to' => 'TO', 'subject' => 'SUBJECT', 'size' => 'SIZE'][$f['sort'] ?? 'date'] ?? 'DATE';
        $desc = !isset($f['desc']) || $f['desc'];
        $un = in_array('SORT', $this->capabilities(), true)
            ? $this->cmd('UID SORT (' . ($desc ? 'REVERSE ' : '') . "$by) UTF-8 $c")
            : $this->cmd("UID SEARCH CHARSET UTF-8 $c");
        $uids = [];
        foreach ($un as $r) {
            if (preg_match('/^\* (SORT|SEARCH)\s*(.*)$/is', trim($r), $m)) {
                foreach (preg_split('/\s+/', trim($m[2])) as $u) if (ctype_digit($u)) $uids[] = (int)$u;
                if (strtoupper($m[1]) === 'SEARCH') rsort($uids);
            }
        }
        return $uids;
    }

    public function capabilities(): array
    {
        if (!$this->caps) {
            foreach ($this->cmd('CAPABILITY') as $r) {
                if (preg_match('/^\* CAPABILITY (.*)$/i', trim($r), $m)) $this->caps = preg_split('/\s+/', strtoupper($m[1]));
            }
        }
        return $this->caps;
    }

    /** Summary rows for the list. */
    public function summaries(array $uids): array
    {
        if (!$uids) return [];
        $set = implode(',', array_map('intval', $uids));
        $un = $this->cmd("UID FETCH $set (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE MESSAGE-ID X-BBS-NUMBER X-BBS-TYPE X-BBS-BID X-BBS-FROM X-BBS-TO X-BBS-VIA)])");
        $map = self::fetchMap($un);
        $out = [];
        foreach ($uids as $u) {
            if (!isset($map[$u])) continue;
            $m = $map[$u];
            $hdrText = '';
            foreach ($m as $k => $v) if (strpos($k, 'BODY[HEADER') === 0) $hdrText = (string)$v;
            $h = self::headers($hdrText);
            $flags = array_map('strtolower', (array)($m['FLAGS'] ?? []));
            $out[] = self::row($u, $h, $flags, (int)($m['RFC822.SIZE'] ?? 0), (string)($m['INTERNALDATE'] ?? ''));
        }
        return $out;
    }

    /** One whole message, decoded to UTF-8 text. */
    public function message(int $uid): ?array
    {
        $un = $this->cmd("UID FETCH $uid (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[])");
        $map = self::fetchMap($un);
        if (!isset($map[$uid])) return null;
        $m = $map[$uid];
        $raw = '';
        foreach ($m as $k => $v) if (strpos($k, 'BODY[') === 0) $raw = (string)$v;
        [$hdrText, $body] = self::split($raw);
        $h = self::headers($hdrText);
        $row = self::row($uid, $h, array_map('strtolower', (array)($m['FLAGS'] ?? [])), (int)($m['RFC822.SIZE'] ?? 0), (string)($m['INTERNALDATE'] ?? ''));
        $row['body'] = self::textOf($h, $body);
        return $row;
    }

    public function setFlagged(array $uids, bool $on): void
    {
        if (!$uids) return;
        $this->cmd('UID STORE ' . implode(',', array_map('intval', $uids)) . ($on ? ' +' : ' -') . 'FLAGS.SILENT (\\Flagged)');
    }

    public function copy(array $uids, string $to): void
    {
        if (!$uids) return;
        $this->cmd('UID COPY ' . implode(',', array_map('intval', $uids)) . ' ' . self::boxOut($to));
    }

    /** Every message in the open folder read (or unread). */
    public function setSeenAll(bool $seen): void
    {
        $this->cmd('UID STORE 1:* ' . ($seen ? '+' : '-') . 'FLAGS.SILENT (\\Seen)');
    }

    public function setSeen(array $uids, bool $seen): void
    {
        if (!$uids) return;
        $this->cmd('UID STORE ' . implode(',', array_map('intval', $uids)) . ($seen ? ' +' : ' -') . 'FLAGS.SILENT (\\Seen)');
    }

    public function move(array $uids, string $to): void
    {
        if (!$uids) return;
        $set = implode(',', array_map('intval', $uids));
        if (in_array('MOVE', $this->capabilities(), true)) { $this->cmd("UID MOVE $set " . self::boxOut($to)); return; }
        $this->cmd("UID COPY $set " . self::boxOut($to));
        $this->cmd("UID STORE $set +FLAGS.SILENT (\\Deleted)");
        $this->cmd("UID EXPUNGE $set");
    }

    public function createFolder(string $name): void
    {
        $this->cmd('CREATE ' . self::boxOut($name));
        try { $this->cmd('SUBSCRIBE ' . self::boxOut($name)); } catch (BpqImapError $e) { /* listed anyway */ }
    }

    public function renameFolder(string $from, string $to): void
    {
        $this->cmd('RENAME ' . self::boxOut($from) . ' ' . self::boxOut($to));
        try { $this->cmd('UNSUBSCRIBE ' . self::boxOut($from)); } catch (BpqImapError $e) { /* not subscribed */ }
        try { $this->cmd('SUBSCRIBE ' . self::boxOut($to)); } catch (BpqImapError $e) { /* listed anyway */ }
    }

    public function deleteFolder(string $name): void
    {
        $this->cmd('DELETE ' . self::boxOut($name));
        try { $this->cmd('UNSUBSCRIBE ' . self::boxOut($name)); } catch (BpqImapError $e) { /* not subscribed */ }
    }

    public function unselect(): void
    {
        try { $this->cmd(in_array('UNSELECT', $this->capabilities(), true) ? 'UNSELECT' : 'CLOSE'); } catch (BpqImapError $e) { /* none open */ }
    }

    /** Deletes for good (used in Trash only). */
    public function expunge(array $uids): void
    {
        if (!$uids) return;
        $set = implode(',', array_map('intval', $uids));
        $this->cmd("UID STORE $set +FLAGS.SILENT (\\Deleted)");
        $this->cmd(in_array('UIDPLUS', $this->capabilities(), true) ? "UID EXPUNGE $set" : 'EXPUNGE');
    }

    // ── message text helpers ─────────────────────────────────────────────
    public static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $p = strpos($raw, "\n\n");
        return $p === false ? [$raw, ''] : [substr($raw, 0, $p), substr($raw, $p + 2)];
    }

    /** Header block -> [lowercase name => decoded value] (first occurrence). */
    public static function headers(string $text): array
    {
        $text = preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $text));
        $h = [];
        foreach (explode("\n", $text) as $l) {
            if (!preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $l, $m)) continue;
            $k = strtolower($m[1]);
            if (!isset($h[$k])) $h[$k] = self::decodeHeader(trim($m[2]));
        }
        return $h;
    }

    public static function decodeHeader(string $v): string
    {
        if (strpos($v, '=?') === false) return $v;
        if (function_exists('iconv_mime_decode')) {
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($d !== false) return $d;
        }
        return function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($v) : $v;
    }

    /** "N3MEL <N3MEL@bbs.invalid>" -> N3MEL; any other address kept as written. */
    public static function call(string $addr): string
    {
        if (preg_match('/<([^>@]+)@bbs\.invalid>/i', $addr, $m)) return strtoupper($m[1]);
        if (preg_match('/^"?([^"<]+?)"?\s*<([^>]+)>/', $addr, $m)) return trim($m[1]) ?: $m[2];
        return $addr;
    }

    private static function row(int $uid, array $h, array $flags, int $size, string $idate): array
    {
        // a weekday name makes strtotime() move to the NEXT such day when it
        // doesn't match the date: leave it out
        $ts = strtotime(preg_replace('/^\s*[A-Za-z]{3},\s*/', '', $h['date'] ?? '')) ?: (strtotime($idate) ?: 0);
        $to = $h['to'] ?? '';
        // a bulletin's To is "WX@ALLUS (bulletin)" <WX@bbs.invalid>: show the group@area
        if (preg_match('/^"?([^"<]+?) \(bulletin\)"?\s*</', $to, $m)) $toShow = $m[1]; else $toShow = self::call($to);
        // v1.5.77: Winlink / internet mail keeps its real address in
        // X-BBS-From / X-BBS-To (RMS:user@domain, CALL@winlink.org)
        $from = self::call($h['from'] ?? '');
        $bbsFrom = trim($h['x-bbs-from'] ?? ''); $bbsTo = trim($h['x-bbs-to'] ?? '');
        if (preg_match('/^(RMS|SMTP):\s*(\S+@\S+)$/i', $bbsFrom, $mm)) $from = $mm[2];
        elseif (preg_match('/@winlink\.org$/i', $bbsFrom)) $from = strtoupper(strtok($bbsFrom, '@')) . '@winlink.org';
        if (preg_match('/^(RMS|SMTP):\s*(\S+@\S+)$/i', $bbsTo, $mm)) $toShow = $mm[2];
        elseif (preg_match('/@winlink\.org$/i', $bbsTo)) $toShow = strtoupper(strtok($bbsTo, '@')) . '@winlink.org';
        return [
            'uid'       => $uid,
            'from'      => $from,
            'fromAddr'  => $bbsFrom,
            'toAddr'    => $bbsTo,
            'winlink'   => strcasecmp(trim($h['x-bbs-via'] ?? ''), 'Winlink') === 0,
            // v1.5.78: a draft written in BBS Mail keeps what Write needs
            'draft'     => isset($h['x-bbs-draft-type']) ? ['to' => (string)($h['x-bbs-draft-to'] ?? ''), 'type' => (string)$h['x-bbs-draft-type']] : null,
            'to'        => $toShow,
            'subject'   => $h['subject'] ?? '',
            'date'      => $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '',
            'seen'      => in_array('\\seen', $flags, true),
            'flagged'   => in_array('\\flagged', $flags, true),
            'size'      => $size,
            'bbsNumber' => isset($h['x-bbs-number']) && ctype_digit($h['x-bbs-number']) ? (int)$h['x-bbs-number'] : null,
            'bbsType'   => $h['x-bbs-type'] ?? '',
            'bid'       => $h['x-bbs-bid'] ?? '',
            'messageId' => $h['message-id'] ?? '',
        ];
    }

    /** The readable text of a message: text/plain (first one in a multipart), transfer-decoded, as UTF-8. */
    public static function textOf(array $h, string $body, int $depth = 0): string
    {
        $ct = $h['content-type'] ?? 'text/plain';
        $cte = strtolower(trim($h['content-transfer-encoding'] ?? '7bit'));
        if ($depth < 5 && preg_match('#^multipart/#i', $ct) && preg_match('/boundary="?([^";]+)"?/i', $ct, $m)) {
            $parts = explode('--' . $m[1], $body);
            $html = null;
            foreach (array_slice($parts, 1) as $part) {
                if (strpos($part, '--') === 0) break;
                [$ph, $pb] = self::split(ltrim($part, "\r\n"));
                $hh = self::headers($ph);
                $pct = $hh['content-type'] ?? 'text/plain';
                if (preg_match('#^(text/plain|multipart/)#i', $pct)) {
                    $t = self::textOf($hh, $pb, $depth + 1);
                    if ($t !== '') return $t;
                } elseif ($html === null && preg_match('#^text/html#i', $pct)) {
                    $html = self::textOf($hh, $pb, $depth + 1);
                }
            }
            return $html !== null ? trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) : '';
        }
        if ($cte === 'base64') $body = (string)base64_decode(preg_replace('/\s+/', '', $body));
        elseif ($cte === 'quoted-printable') $body = quoted_printable_decode($body);
        $cs = preg_match('/charset="?([A-Za-z0-9._-]+)/i', $ct, $m) ? strtoupper($m[1]) : 'UTF-8';
        if ($cs !== 'UTF-8' && $cs !== 'US-ASCII' && function_exists('mb_convert_encoding')) {
            $conv = @mb_convert_encoding($body, 'UTF-8', $cs);
            if ($conv !== false) $body = $conv;
        }
        if (!preg_match('//u', $body)) $body = mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
        if (preg_match('#^text/html#i', $ct)) $body = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
        return rtrim(str_replace("\r\n", "\n", $body));
    }
}
