# BPQ Dashboard - POP3/SMTP Integration for BBS Messages

**Status:** Research / Future Implementation  
**Last Updated:** February 2026

This document explores using POP3 and SMTP protocols as an alternative to telnet for the BBS Messages dashboard.

---

## Current Architecture

```
bbs-messages.html → bbs-messages.php → Telnet (port 8010) → BBS
                                       ↓
                                   Parse BBS prompts
                                   Send BBS commands (L, R, SP, etc.)
                                   Scrape message output
```

**Pros of current approach:**
- Full BBS functionality (bulletins, kill, forwarding status)
- Works with any BPQ32 configuration

**Cons of current approach:**
- Fragile parsing of BBS text prompts
- Must handle various BBS states and prompts
- No standard message format

---

## BPQ32 POP3/SMTP Server Overview

### Enabling POP3/SMTP

In BPQ32 Mail Server Configuration:
- **POP3 Port:** Usually 110 (or custom like 17110)
- **SMTP Port:** Usually 25 (or custom like 17025)
- **Enable Remote Access:** Required if accessing from another machine

### Authentication

Both POP3 and SMTP require authentication:
- **Username:** Callsign (e.g., `K1ABC`)
- **Password:** Set in BPQ32 Users configuration tab

### Email Addressing

Messages use hierarchical addressing:
- `callsign@bbscall` (e.g., `K1ABC@K1AJD`)
- `callsign@bbscall.region.country` (e.g., `K1ABC@K1AJD.#CSRA.GA.USA.NOAM`)

---

## POP3 Protocol Summary

### Connection Flow

```
Client: [connect to port 110]
Server: +OK BPQ Mail Server ready

Client: USER K1ABC
Server: +OK

Client: PASS mypassword
Server: +OK Logged in

Client: STAT
Server: +OK 5 12345    (5 messages, 12345 bytes total)

Client: LIST
Server: +OK
Server: 1 2048
Server: 2 1536
Server: 3 4096
Server: .

Client: RETR 1
Server: +OK 2048 octets
Server: [message headers and body]
Server: .

Client: DELE 1          (mark for deletion)
Server: +OK

Client: QUIT
Server: +OK Bye
```

### Key Commands

| Command | Description |
|---------|-------------|
| `USER <callsign>` | Specify username |
| `PASS <password>` | Authenticate |
| `STAT` | Get message count and total size |
| `LIST` | List all message numbers and sizes |
| `LIST <n>` | Get size of specific message |
| `RETR <n>` | Retrieve full message |
| `TOP <n> <lines>` | Get headers + first N lines of body |
| `DELE <n>` | Mark message for deletion |
| `RSET` | Unmark all deletions |
| `UIDL` | Get unique IDs for messages |
| `QUIT` | End session (deletions committed) |

### Message Format (RFC 822)

Retrieved messages include standard email headers:
```
From: K7EK@K7EK.#WWA.WA.USA.NOAM
To: K1ABC@K1AJD.#CSRA.GA.USA.NOAM
Subject: Test message
Date: Mon, 03 Feb 2026 15:30:00 +0000
Message-ID: <12345@K7EK>

This is the message body.
```

---

## SMTP Protocol Summary

### Connection Flow

```
Client: [connect to port 25]
Server: 220 BPQ Mail Server ready

Client: HELO localhost
Server: 250 OK

Client: AUTH LOGIN
Server: 334 VXNlcm5hbWU6    (base64 "Username:")
Client: SzFBQkM=             (base64 "K1ABC")
Server: 334 UGFzc3dvcmQ6    (base64 "Password:")
Client: bXlwYXNzd29yZA==     (base64 "mypassword")
Server: 235 Authentication successful

Client: MAIL FROM:<K1ABC@K1AJD>
Server: 250 OK

Client: RCPT TO:<K7EK@K7EK.#WWA.WA.USA.NOAM>
Server: 250 OK

Client: DATA
Server: 354 Start mail input

Client: Subject: Test message
Client: 
Client: This is my message body.
Client: .
Server: 250 OK Message accepted

Client: QUIT
Server: 221 Bye
```

### Key Points

- BPQ SMTP **requires authentication** (unlike many SMTP servers)
- Use `AUTH LOGIN` with base64-encoded credentials
- Full hierarchical addressing may be required for recipients
- Messages become personal mail or bulletins based on addressing

---

## Implementation Analysis

### What POP3/SMTP Can Do

| Feature | Telnet | POP3 | SMTP |
|---------|--------|------|------|
| Read personal messages | ✅ | ✅ | ❌ |
| Send personal messages | ✅ | ❌ | ✅ |
| Read bulletins | ✅ | ❌ | ❌ |
| Send bulletins | ✅ | ❌ | ✅* |
| Kill messages | ✅ | ✅ (DELE) | ❌ |
| List all messages | ✅ | ✅ | ❌ |
| Forwarding status | ✅ | ❌ | ❌ |
| Message routing info | ✅ | ❌ | ❌ |

*Bulletins via SMTP require proper @ addressing (e.g., `ALL@WW`)

### Limitations of POP3/SMTP

1. **POP3 only shows personal messages** - Bulletins are not accessible via POP3
2. **No forwarding status** - Can't see if message was forwarded
3. **No message routing** - Can't see BBS routing information
4. **Delete behavior** - Standard POP3 deletes after QUIT (configurable in some clients)
5. **No BBS-specific commands** - Can't access `I` (info), `H` (help), `K` (kill), etc.

### What This Means

POP3/SMTP would be useful for:
- Cleaner personal message reading (standard RFC 822 format)
- More reliable message sending (standard SMTP)
- Integration with other email tools

But **cannot replace telnet** for:
- Bulletin board access
- Full BBS functionality
- Administrative commands

---

## Proposed Hybrid Architecture

```
bbs-messages.html
    │
    ├─→ bbs-messages.php (existing telnet)
    │       └─→ Full BBS access (bulletins, admin, status)
    │
    └─→ bbs-pop3.php (new)
            ├─→ POP3: Read personal messages (cleaner parsing)
            └─→ SMTP: Send messages (more reliable)
```

### Configuration Addition

```php
// config.php
'bbs' => [
    'host'      => 'localhost',
    'port'      => 8010,          // Telnet (existing)
    'pop3_port' => 110,           // POP3 (new)
    'smtp_port' => 25,            // SMTP (new)
    'user'      => 'YOURCALL',
    'pass'      => 'CHANGEME',
    'use_pop3'  => false,         // Enable POP3/SMTP mode
],
```

### UI Changes

Add toggle in BBS Messages dashboard:
```
[📧 Standard Mode] [📬 Email Mode]
```

- **Standard Mode:** Current telnet-based (full features)
- **Email Mode:** POP3/SMTP (personal messages only, cleaner)

---

## Implementation Steps

### Phase 1: POP3 Client (Read Messages)

1. Create `bbs-pop3.php` with POP3 client class
2. Implement: connect, auth, list, retrieve, delete
3. Parse RFC 822 message format
4. Add "Email Mode" toggle to UI
5. Test with BPQ32

### Phase 2: SMTP Client (Send Messages)

1. Add SMTP client to `bbs-pop3.php`
2. Implement: connect, auth, send
3. Handle base64 encoding for AUTH LOGIN
4. Build proper message headers
5. Test sending to local and remote stations

### Phase 3: Integration

1. Add configuration options
2. Graceful fallback if POP3/SMTP unavailable
3. Clear UI indication of which mode is active
4. Documentation updates

---

## PHP POP3 Client Example

```php
class BPQPop3Client {
    private $socket;
    private $host;
    private $port;
    
    public function connect($host, $port = 110) {
        $this->socket = fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            throw new Exception("Connection failed: $errstr");
        }
        $response = $this->readLine();
        if (strpos($response, '+OK') !== 0) {
            throw new Exception("Server not ready: $response");
        }
        return true;
    }
    
    public function login($user, $pass) {
        $this->sendCommand("USER $user");
        $response = $this->readLine();
        if (strpos($response, '+OK') !== 0) {
            throw new Exception("USER failed: $response");
        }
        
        $this->sendCommand("PASS $pass");
        $response = $this->readLine();
        if (strpos($response, '+OK') !== 0) {
            throw new Exception("PASS failed: $response");
        }
        return true;
    }
    
    public function stat() {
        $this->sendCommand("STAT");
        $response = $this->readLine();
        if (preg_match('/^\+OK (\d+) (\d+)/', $response, $m)) {
            return ['count' => (int)$m[1], 'size' => (int)$m[2]];
        }
        throw new Exception("STAT failed: $response");
    }
    
    public function listMessages() {
        $this->sendCommand("LIST");
        $response = $this->readLine();
        if (strpos($response, '+OK') !== 0) {
            throw new Exception("LIST failed: $response");
        }
        
        $messages = [];
        while (($line = $this->readLine()) !== '.') {
            if (preg_match('/^(\d+) (\d+)/', $line, $m)) {
                $messages[$m[1]] = (int)$m[2];
            }
        }
        return $messages;
    }
    
    public function retrieve($msgNum) {
        $this->sendCommand("RETR $msgNum");
        $response = $this->readLine();
        if (strpos($response, '+OK') !== 0) {
            throw new Exception("RETR failed: $response");
        }
        
        $message = '';
        while (($line = $this->readLine()) !== '.') {
            // Handle byte-stuffing (lines starting with . have extra . added)
            if (strpos($line, '..') === 0) {
                $line = substr($line, 1);
            }
            $message .= $line . "\r\n";
        }
        return $this->parseMessage($message);
    }
    
    public function delete($msgNum) {
        $this->sendCommand("DELE $msgNum");
        $response = $this->readLine();
        return strpos($response, '+OK') === 0;
    }
    
    public function quit() {
        $this->sendCommand("QUIT");
        fclose($this->socket);
    }
    
    private function parseMessage($raw) {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerBlock = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        
        $headers = [];
        foreach (explode("\n", $headerBlock) as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', trim($line), $m)) {
                $headers[strtolower($m[1])] = $m[2];
            }
        }
        
        return [
            'from'    => $headers['from'] ?? '',
            'to'      => $headers['to'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'date'    => $headers['date'] ?? '',
            'body'    => trim($body),
            'raw'     => $raw
        ];
    }
    
    private function sendCommand($cmd) {
        fwrite($this->socket, "$cmd\r\n");
    }
    
    private function readLine() {
        return trim(fgets($this->socket, 1024));
    }
}
```

---

## Estimated Development Time

| Phase | Description | Sessions |
|-------|-------------|----------|
| Phase 1 | POP3 client + UI toggle | 1-2 |
| Phase 2 | SMTP client | 1 |
| Phase 3 | Integration + testing | 1 |
| **Total** | | **3-4 sessions** |

---

## Recommendation

**Implement as optional feature** alongside existing telnet:

1. Keep telnet as default (full BBS features)
2. Add POP3/SMTP as "Email Mode" for users who prefer it
3. POP3 provides cleaner message parsing for personal mail
4. SMTP provides more reliable message sending
5. Clearly document that Email Mode only works for personal messages

This gives users choice while maintaining full BBS functionality.

---

## References

- [BPQ32 Mail Server Documentation](https://www.cantab.net/users/john.wiseman/Documents/MailServer.html)
- [BPQ32 Email Client Configuration](https://www.cantab.net/users/john.wiseman/Documents/eMailClientConfiguration.html)
- [RFC 1939 - POP3 Protocol](https://datatracker.ietf.org/doc/html/rfc1939)
- [RFC 821 - SMTP Protocol](https://datatracker.ietf.org/doc/html/rfc821)
