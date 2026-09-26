#!/usr/bin/env python3
"""
yapp_protocol.py — YAPP (Yet Another Packet Protocol) state machine
for binary file transfer over packet radio terminal sessions.

Based on:
  - YAPP 1.1 spec by Jeff Jacobsen WA7MBL (1986)
  - YAPP-C (YAPP with Checksum) extension by FBB / TPK 1.65a+

This module is transport-agnostic — it processes raw bytes via
feed() and produces bytes to send via take_out(). Integration with
the WebSocket-to-telnet daemon is done in bpq-telnet-daemon.py.

═══════════════════════════════════════════════════════════════════
PROTOCOL OVERVIEW
═══════════════════════════════════════════════════════════════════

Each YAPP packet is: <control_byte> <length_byte> <data...>
where length is the count of bytes that follow (not including
control or length itself).

Control bytes:
  ENQ (0x05) — Send_Init / Rcv_Init
  ACK (0x06) — RR (Rcv_Rdy), RF (Rcv_File), AF (Ack_EOF),
                AT (Ack_EOT), CA (Can_Ack)
  SOH (0x01) — HD (Send_Hdr) — header with filename + size
  STX (0x02) — DT (Send_Data) — data block
  ETX (0x03) — EF (Send_EOF) — end of file marker
  EOT (0x04) — ET (Send_EOT) — end of transmission
  NAK (0x15) — NR (Not_Rdy), RE (Resume)
  CAN (0x18) — CN (Cancel)
  DLE (0x10) — TX (Text)

Send subtype is identified by ACK length:
  ACK 01 = RR (Rcv_Rdy)
  ACK 02 = RF (Rcv_File) — classic YAPP
  ACK 02 ACK = RT (Rcv_TPK) — YAPP-C extension
  ACK 03 = AF (Ack_EOF)
  ACK 04 = AT (Ack_EOT)
  ACK 05 = CA (Can_Ack)
  ENQ 01 = SI (Send_Init)
  ENQ 02 + filespec = RI (Rcv_Init)
  ETX 01 = EF (Send_EOF) — classic
  EOT 01 = ET (Send_EOT) — classic
  EOT + checksum byte = ET (Send_EOT) — YAPP-C

═══════════════════════════════════════════════════════════════════
STATE MACHINE — SENDING A FILE
═══════════════════════════════════════════════════════════════════

  IDLE  →  send SI (ENQ 01)                                → WAIT_RR
  WAIT_RR  →  recv RR (ACK 01)                             → send HD
  WAIT_RR  →  recv NR (NAK …)                              → ABORT
  WAIT_RF  →  recv RF (ACK 02)                             → SEND_DATA (classic)
  WAIT_RF  →  recv RT (ACK 02 ACK) — YappC                 → SEND_DATA (compute checksum)
  SEND_DATA →  send DT blocks until EOF                    → send EF (ETX 01)
  WAIT_AF  →  recv AF (ACK 03)                             → send ET
                (in YappC, ET = EOT + checksum)
  WAIT_AT  →  recv AT (ACK 04)                             → DONE
  any state →  recv CN (CAN …)                             → ABORT
  any state →  timeout                                     → ABORT

═══════════════════════════════════════════════════════════════════
STATE MACHINE — RECEIVING A FILE
═══════════════════════════════════════════════════════════════════

  IDLE  →  recv SI (ENQ 01)  →  send RR (ACK 01)  → WAIT_HD
       OR  recv SI (ENQ 01)  →  send RT (ACK 02 ACK) if we want YappC
  WAIT_HD  →  recv HD (SOH …)                              → parse filename/size,
                                                              send RF (ACK 02)  → RECV_DATA
       OR  if YappC                                        →  RECV_DATA (no extra)
  RECV_DATA →  recv DT (STX …) blocks                      → append to file
  RECV_DATA →  recv EF (ETX 01) — classic                  → send AF (ACK 03) → WAIT_ET
  RECV_DATA →  recv EF (ETX 01) — YappC                    → send AF (ACK 03)
  WAIT_ET  →  recv ET (EOT 01) — classic                   → send AT (ACK 04) → DONE
  WAIT_ET  →  recv ET (EOT + checksum) — YappC             → verify checksum,
                                                              send AT (ACK 04) → DONE

YAPP-C CHECKSUM
═══════════════════════════════════════════════════════════════════

YAPP-C extension: the sender computes a checksum across all file
data bytes as the sum modulo 256, then takes the two's complement
(i.e. checksum = -sum_of_bytes & 0xFF). Receiver computes the same
sum-of-bytes and verifies (sum + sent_checksum) & 0xFF == 0.

The ET packet in YappC is: EOT <checksum_byte> (no length byte —
the checksum byte IS the position where length would be in classic).
We use the presence of YAPP-C mode (negotiated up front) to decide
how to parse it.
"""

import logging
import os
import struct
from enum import Enum

log = logging.getLogger(__name__)

# Control bytes
SOH = 0x01
STX = 0x02
ETX = 0x03
EOT = 0x04
ENQ = 0x05
ACK = 0x06
DLE = 0x10
NAK = 0x15
CAN = 0x18
NUL = 0x00


class YappState(Enum):
    IDLE       = 'idle'
    # Send states
    WAIT_RR    = 'wait_rr'        # sent SI, waiting for RR
    WAIT_RF    = 'wait_rf'        # sent HD, waiting for RF or RT
    SEND_DATA  = 'send_data'      # sending DT blocks
    WAIT_AF    = 'wait_af'        # sent EF, waiting for AF
    WAIT_AT    = 'wait_at'        # sent ET, waiting for AT
    # Receive states
    WAIT_SI    = 'wait_si'        # waiting for sender's SI
    WAIT_HD    = 'wait_hd'        # sent RR, waiting for HD
    RECV_DATA  = 'recv_data'      # receiving DT blocks
    WAIT_ET    = 'wait_et'        # sent AF, waiting for ET
    # Terminal
    DONE       = 'done'
    ABORT      = 'abort'


class YappTransfer:
    """A single YAPP file transfer in either direction.

    Usage (receive):
        t = YappTransfer(direction='recv', out_path='/path/to/save')
        t.start()  # transition to WAIT_SI
        # feed bytes from the connection peer:
        out_bytes = t.feed(incoming_bytes)
        # send out_bytes back to the peer
        # repeat until t.state is DONE or ABORT
        # t.bytes_received holds the file contents (also written to out_path)

    Usage (send):
        t = YappTransfer(direction='send', filename='report.txt',
                          payload=open('report.txt','rb').read(),
                          allow_yappc=True)
        t.start()  # generates initial SI packet
        out_bytes = t.take_out()
        # send out_bytes to peer
        # feed peer replies via t.feed(...)
        # repeat
    """

    def __init__(self, direction, filename=None, payload=None,
                 out_path=None, allow_yappc=True, max_size=10*1024*1024):
        """
        direction: 'send' or 'recv'
        filename: required for 'send'; for 'recv' it's discovered from HD
        payload: bytes to send (required for 'send')
        out_path: file to write received data into (required for 'recv')
        allow_yappc: if True, negotiate YAPP-C compression-style checksum
        max_size: reject transfers larger than this (default 10MB)
        """
        if direction not in ('send', 'recv'):
            raise ValueError("direction must be 'send' or 'recv'")
        self.direction = direction
        self.filename = filename
        self.payload = payload or b''
        self.out_path = out_path
        self.allow_yappc = allow_yappc
        self.max_size = max_size

        self.state = YappState.IDLE
        self.yappc = False  # negotiated: are we using YAPP-C with checksum?
        self.checksum = 0   # rolling sum of file data bytes
        self.expected_size = 0
        self.bytes_done = 0
        self.bytes_received = bytearray()
        self.error_message = None

        # Output buffer — bytes ready to send to peer
        self._out_buf = bytearray()
        # Input buffer — partial packet being assembled from feed() calls
        self._in_buf = bytearray()

        # For 'send' direction: where we are in the payload
        self._send_pos = 0

    # ─── Public interface ─────────────────────────────────────────

    def start(self):
        """Begin the transfer. Side effects depend on direction."""
        if self.direction == 'send':
            self._send_si()
            self.state = YappState.WAIT_RR
        else:
            self.state = YappState.WAIT_SI

    def feed(self, data):
        """Process incoming bytes from peer. Returns bytes to send back.

        Internally appends to input buffer, parses complete packets,
        and runs the state machine. Any output is queued and returned.
        """
        if self.state in (YappState.DONE, YappState.ABORT):
            return b''
        self._in_buf.extend(data)
        # Try to parse as many complete packets as we have
        while self._parse_one_packet():
            if self.state in (YappState.DONE, YappState.ABORT):
                break
        return self.take_out()

    def take_out(self):
        """Drain pending output bytes."""
        out = bytes(self._out_buf)
        self._out_buf.clear()
        return out

    def abort(self, reason='User cancelled'):
        """Send a CN (Cancel) packet and transition to ABORT."""
        reason_b = reason.encode('ascii', errors='replace')[:200]
        self._send_packet(CAN, reason_b)
        self.state = YappState.ABORT
        self.error_message = reason

    def progress(self):
        """Return (bytes_done, total_bytes, percent)."""
        total = self.expected_size if self.direction == 'recv' else len(self.payload)
        done = self.bytes_done
        pct = (done * 100 // total) if total > 0 else 0
        return done, total, pct

    # ─── Packet construction & sending ────────────────────────────

    def _send_packet(self, ctrl, data=b''):
        """Construct and queue a packet: <ctrl> <len> <data>.

        For data lengths up to 255, len = len(data). For exactly 256
        bytes (used in DT blocks), len = 0 (per spec).
        """
        if len(data) == 256:
            length_byte = 0
        else:
            length_byte = len(data)
        self._out_buf.append(ctrl)
        self._out_buf.append(length_byte)
        self._out_buf.extend(data)

    def _send_si(self):
        """SI (Send_Init): ENQ 01
        Per spec: ENQ followed by subtype byte 0x01 (not a length).
        """
        self._out_buf.extend([ENQ, 0x01])

    def _send_hd(self):
        """HD (Send_Hdr): SOH len filename NUL size NUL [extension NUL]"""
        # filename + NUL + size_string + NUL
        size_str = str(len(self.payload)).encode('ascii')
        name_b = self.filename.encode('ascii', errors='replace')
        # Strip any path components — defense against malicious filenames
        name_b = name_b.replace(b'/', b'_').replace(b'\\', b'_')
        body = name_b + b'\x00' + size_str + b'\x00'
        if len(body) > 255:
            raise ValueError("Filename + size exceeds 255 bytes")
        self._out_buf.append(SOH)
        self._out_buf.append(len(body))
        self._out_buf.extend(body)

    def _send_dt_block(self):
        """DT (Send_Data): STX len data ... up to 256 bytes per block.
        Updates checksum if YAPP-C is active. Advances _send_pos.
        """
        if self._send_pos >= len(self.payload):
            return False  # nothing left
        block = self.payload[self._send_pos:self._send_pos + 256]
        self._send_pos += len(block)
        self.bytes_done = self._send_pos
        # Update checksum
        if self.yappc:
            for b in block:
                self.checksum = (self.checksum + b) & 0xFF
        self._out_buf.append(STX)
        # Length: 0 means 256 bytes
        self._out_buf.append(0 if len(block) == 256 else len(block))
        self._out_buf.extend(block)
        return True

    def _send_ef(self):
        """EF (Send_EOF): ETX 01"""
        self._out_buf.extend([ETX, 0x01])

    def _send_et(self):
        """ET (Send_EOT): EOT 01 (classic) or EOT <checksum> (YAPP-C).

        In YAPP-C the checksum is the two's complement of the sum of
        all data bytes (sum modulo 256, negated and masked).
        """
        if self.yappc:
            cksum = (-self.checksum) & 0xFF
            self._out_buf.extend([EOT, cksum])
        else:
            self._out_buf.extend([EOT, 0x01])

    def _send_rr(self):
        """RR (Rcv_Rdy): ACK 01"""
        self._out_buf.extend([ACK, 0x01])

    def _send_rf(self):
        """RF (Rcv_File): ACK 02 — accept file, classic mode"""
        self._out_buf.extend([ACK, 0x02])

    def _send_rt(self):
        """RT (Rcv_TPK / Rcv_File with YappC): ACK 02 ACK — accept YappC"""
        self._out_buf.extend([ACK, 0x02, ACK])

    def _send_af(self):
        """AF (Ack_EOF): ACK 03"""
        self._out_buf.extend([ACK, 0x03])

    def _send_at(self):
        """AT (Ack_EOT): ACK 04"""
        self._out_buf.extend([ACK, 0x04])

    # ─── Packet parsing & state machine ───────────────────────────

    def _parse_one_packet(self):
        """Try to parse one complete packet from _in_buf and dispatch.

        Returns True if a packet was consumed (caller should loop),
        False if more bytes are needed.
        """
        if len(self._in_buf) < 2:
            return False

        ctrl = self._in_buf[0]
        length = self._in_buf[1]

        # Most packets are <ctrl><len><data> where data is `length` bytes.
        # Exceptions:
        #   ACK 01/02/03/04/05 — `length` IS the subtype, no data follows
        #   ACK 02 ACK         — YAPP-C variant of RF, needs 1 extra byte
        #   ENQ 01             — SI, no data
        #   ENQ 02 …           — RI, length bytes of filespec follow
        #   ETX 01             — EF, no data
        #   EOT <byte>         — ET, the byte is checksum (YappC) or 01 (classic)
        #   STX <len> <data>   — DT, len=0 means 256 bytes
        #   SOH <len> <data>   — HD, len bytes of filename+size+opt

        # The ACK family — subtype packets, just 2 bytes
        if ctrl == ACK:
            subtype = length
            # Check for ACK 02 ACK (RT — YappC negotiation by receiver)
            if subtype == 0x02 and len(self._in_buf) >= 3 and self._in_buf[2] == ACK:
                self._consume(3)
                self._handle_rt()
                return True
            # Plain ACK with subtype
            self._consume(2)
            self._handle_ack(subtype)
            return True

        if ctrl == ENQ:
            subtype = length
            if subtype == 0x01:
                self._consume(2)
                self._handle_si()
                return True
            elif subtype == 0x02:
                # RI: ENQ 02 <len_filespec> <filespec>
                if len(self._in_buf) < 3: return False
                fs_len = self._in_buf[2]
                if len(self._in_buf) < 3 + fs_len: return False
                filespec = bytes(self._in_buf[3:3+fs_len])
                self._consume(3 + fs_len)
                self._handle_ri(filespec)
                return True

        if ctrl == ETX:
            # EF — classic EOF marker: ETX 01
            if length == 0x01:
                self._consume(2)
                self._handle_ef()
                return True

        if ctrl == EOT:
            # ET — end of transmission. The next byte is either 01
            # (classic) or a checksum byte (YappC). Either way we just
            # need to consume 2 bytes total and pass the byte to handler.
            self._consume(2)
            self._handle_et(length)
            return True

        if ctrl == SOH:
            # HD: SOH len data...
            if len(self._in_buf) < 2 + length: return False
            data = bytes(self._in_buf[2:2+length])
            self._consume(2 + length)
            self._handle_hd(data)
            return True

        if ctrl == STX:
            # DT: STX len data... (len=0 means 256)
            data_len = 256 if length == 0 else length
            if len(self._in_buf) < 2 + data_len: return False
            data = bytes(self._in_buf[2:2+data_len])
            self._consume(2 + data_len)
            self._handle_dt(data)
            return True

        if ctrl == NAK:
            # NR: NAK len reason / RE: NAK len R NUL size NUL
            if len(self._in_buf) < 2 + length: return False
            data = bytes(self._in_buf[2:2+length])
            self._consume(2 + length)
            self._handle_nak(data)
            return True

        if ctrl == CAN:
            # CN: CAN len reason
            if len(self._in_buf) < 2 + length: return False
            reason = bytes(self._in_buf[2:2+length]).decode('ascii', errors='replace')
            self._consume(2 + length)
            self._handle_cn(reason)
            return True

        if ctrl == DLE:
            # TX: text from server — display to user
            if len(self._in_buf) < 2 + length: return False
            text = bytes(self._in_buf[2:2+length]).decode('ascii', errors='replace')
            self._consume(2 + length)
            log.info(f"YAPP TX (server text): {text}")
            return True

        # Unknown byte at the start of buffer — drop it and keep parsing.
        # This recovers from stray bytes (e.g. user pressed Enter before
        # YAPP started, or a partial packet got out of sync).
        log.warning(f"YAPP: unknown control byte 0x{ctrl:02x}, dropping")
        self._consume(1)
        return True

    def _consume(self, n):
        del self._in_buf[:n]

    # ─── Packet handlers ──────────────────────────────────────────

    def _handle_ack(self, subtype):
        log.debug(f"YAPP recv ACK subtype={subtype} in state {self.state.value}")
        if subtype == 0x01:    # RR
            self._on_rr()
        elif subtype == 0x02:  # RF (classic, no YappC)
            self._on_rf(yappc=False)
        elif subtype == 0x03:  # AF
            self._on_af()
        elif subtype == 0x04:  # AT
            self._on_at()
        elif subtype == 0x05:  # CA
            self._on_ca()

    def _handle_rt(self):
        """RT — YAPP-C variant of RF"""
        log.debug("YAPP recv RT (YappC)")
        self._on_rf(yappc=True)

    def _handle_si(self):
        if self.state != YappState.WAIT_SI:
            log.warning(f"YAPP: SI received in state {self.state.value}")
            return
        # Receiver: ACK with RR (Rcv_Rdy). YappC vs classic negotiation
        # happens at HD time, where we send RF (classic) or RT (YappC).
        self._send_rr()
        self.state = YappState.WAIT_HD

    def _handle_ri(self, filespec):
        log.info(f"YAPP RI received with filespec={filespec!r}")
        # Server mode — we'd respond with the file. For now, refuse.
        self.abort("Server mode (RI) not implemented")

    def _handle_hd(self, data):
        """Parse: filename NUL size NUL [optional extensions NUL]"""
        if self.state != YappState.WAIT_HD:
            log.warning(f"YAPP: HD in state {self.state.value}")
            return
        parts = data.split(b'\x00')
        if len(parts) < 2:
            self.abort("Malformed HD packet")
            return
        try:
            filename = parts[0].decode('ascii', errors='replace')
            size = int(parts[1].decode('ascii').strip() or '0')
        except (ValueError, UnicodeDecodeError) as e:
            self.abort(f"Invalid HD: {e}")
            return
        # Sanitize filename — strip path, limit length
        filename = filename.replace('/', '_').replace('\\', '_').strip()
        if len(filename) > 64:
            filename = filename[-64:]
        if not filename:
            filename = 'received.bin'
        if size > self.max_size:
            self.abort(f"File too large: {size} > {self.max_size}")
            return
        self.filename = filename
        self.expected_size = size
        log.info(f"YAPP: receiving '{filename}' ({size} bytes, allow_yappc={self.allow_yappc})")
        # Choose mode based on receiver's allow_yappc setting:
        #   RT (ACK 02 ACK) — YappC with checksum
        #   RF (ACK 02)     — classic YAPP
        if self.allow_yappc:
            self.yappc = True
            self._send_rt()
        else:
            self._send_rf()
        self.state = YappState.RECV_DATA

    def _handle_dt(self, data):
        if self.state != YappState.RECV_DATA:
            log.warning(f"YAPP: DT in state {self.state.value}")
            return
        self.bytes_received.extend(data)
        self.bytes_done = len(self.bytes_received)
        if self.yappc:
            for b in data:
                self.checksum = (self.checksum + b) & 0xFF
        if self.bytes_done > self.max_size:
            self.abort(f"Exceeded max_size during transfer")

    def _handle_ef(self):
        if self.state != YappState.RECV_DATA:
            log.warning(f"YAPP: EF in state {self.state.value}")
            return
        log.info(f"YAPP: received EOF, {self.bytes_done} bytes")
        self._send_af()
        self.state = YappState.WAIT_ET

    def _handle_et(self, second_byte):
        """second_byte: 0x01 (classic) or checksum value (YappC).

        Note: even when YAPP-C is negotiated via RT, some implementations
        (including BPQ) send the classic-style ET (EOT 01) instead of the
        checksum-style ET. If second_byte is exactly 0x01 and YappC was
        negotiated, treat it as classic and skip checksum verification.
        """
        if self.state != YappState.WAIT_ET:
            # Some senders skip EF and go straight to ET. Handle that.
            if self.state == YappState.RECV_DATA:
                self._send_af()
            else:
                log.warning(f"YAPP: ET in state {self.state.value}")
                return
        if self.yappc and second_byte != 0x01:
            # YAPP-C with real checksum byte
            total = (self.checksum + second_byte) & 0xFF
            if total != 0:
                log.warning(f"YAPP-C checksum mismatch: sum={self.checksum} "
                            f"got_ck={second_byte} total={total}")
                self.error_message = "Checksum mismatch"
                # Some impls still ACK and complain after. We'll ACK and
                # note the error — caller can decide to keep the file.
            else:
                log.info(f"YAPP-C checksum OK: sum={self.checksum} "
                         f"ck={second_byte}")
        elif self.yappc and second_byte == 0x01:
            log.info(f"YAPP-C: peer sent classic-style ET (no checksum), "
                     f"accepting as final")
        log.info(f"YAPP: received EOT, transfer complete")
        self._send_at()
        # Persist to disk if requested
        self._finalize_receive()
        self.state = YappState.DONE

    def _handle_nak(self, data):
        log.warning(f"YAPP: NAK received with data {data!r}")
        # NR (Not_Rdy) or RE (Resume) — for simplicity we just abort
        self.abort("Peer not ready")

    def _handle_cn(self, reason):
        log.info(f"YAPP: peer cancelled: {reason}")
        self.error_message = f"Peer cancelled: {reason}"
        # Reply with CA
        self._out_buf.extend([ACK, 0x05])
        self.state = YappState.ABORT

    # ─── State transition helpers for SEND ────────────────────────

    def _on_rr(self):
        """Received RR — peer is ready, send HD."""
        if self.state != YappState.WAIT_RR:
            return
        self._send_hd()
        self.state = YappState.WAIT_RF

    def _on_rf(self, yappc):
        """Received RF (or RT). Begin sending DT blocks."""
        if self.state != YappState.WAIT_RF:
            return
        self.yappc = yappc
        log.info(f"YAPP send: starting transfer (yappc={yappc})")
        # Send all DT blocks. Real implementations would pace these,
        # but since BPQ telnet handles flow control over TCP we can
        # send all at once.
        self.state = YappState.SEND_DATA
        while self._send_dt_block():
            pass
        # Now EF
        self._send_ef()
        self.state = YappState.WAIT_AF

    def _on_af(self):
        """Received AF — peer accepted EOF, send ET."""
        if self.state != YappState.WAIT_AF:
            return
        self._send_et()
        self.state = YappState.WAIT_AT

    def _on_at(self):
        """Received AT — transfer complete."""
        if self.state != YappState.WAIT_AT:
            return
        log.info(f"YAPP send: transfer complete, {self.bytes_done} bytes")
        self.state = YappState.DONE

    def _on_ca(self):
        """Received CA — peer acknowledged our cancellation."""
        self.state = YappState.ABORT

    # ─── Finalization ─────────────────────────────────────────────

    def _finalize_receive(self):
        """Write received bytes to out_path if specified.

        If out_path's basename contains 'pending' (placeholder), replace
        the 'pending' part with the actual filename from HD. So
        '20260521-000614-pending.bin' becomes
        '20260521-000614-bpqtest.pdf' when HD said the file is bpqtest.pdf.
        """
        if not self.out_path:
            return
        # Substitute real filename if the placeholder is still in place
        if self.filename and 'pending' in os.path.basename(self.out_path):
            dir_ = os.path.dirname(self.out_path)
            base = os.path.basename(self.out_path)
            # 'YYYYMMDD-HHMMSS-pending.bin' → 'YYYYMMDD-HHMMSS-<real>.<ext>'
            prefix = base.split('-pending.bin')[0]  # 'YYYYMMDD-HHMMSS'
            # Sanitize real filename — strip path, limit length
            safe_name = self.filename.replace('/', '_').replace('\\', '_')
            safe_name = ''.join(c for c in safe_name
                                if c.isalnum() or c in '._-')[:80]
            self.out_path = os.path.join(dir_, f'{prefix}-{safe_name}')
        try:
            with open(self.out_path, 'wb') as f:
                f.write(bytes(self.bytes_received))
            log.info(f"YAPP: wrote {len(self.bytes_received)} bytes "
                     f"to {self.out_path}")
        except IOError as e:
            log.error(f"YAPP: write to {self.out_path} failed: {e}")
            self.error_message = f"Write failed: {e}"


# ─── Module self-test ───────────────────────────────────────────────

if __name__ == '__main__':
    logging.basicConfig(level=logging.DEBUG)

    # Round-trip a small file
    payload = b'Hello, YAPP world!\nThis is a test file.\n' * 10
    print(f"Test payload: {len(payload)} bytes")

    sender = YappTransfer('send', filename='test.txt',
                          payload=payload, allow_yappc=True)
    receiver = YappTransfer('recv', out_path='/tmp/yapp-test-out.bin',
                            allow_yappc=True)

    sender.start()
    receiver.start()

    # Exchange bytes until both are done. feed() takes incoming bytes AND
    # returns any response bytes ready to send — don't double-drain via
    # take_out() in the same iteration.
    max_iter = 100
    from_sender = sender.take_out()  # initial SI
    from_recv = b''
    for i in range(max_iter):
        if from_sender:
            print(f"[{i}] sender→receiver: {len(from_sender)} bytes  {from_sender[:20].hex()}")
            from_recv = receiver.feed(from_sender)
            from_sender = b''
        if from_recv:
            print(f"[{i}] receiver→sender: {len(from_recv)} bytes  {from_recv[:20].hex()}")
            from_sender = sender.feed(from_recv)
            from_recv = b''
        if not from_sender and not from_recv:
            break
        if sender.state in (YappState.DONE, YappState.ABORT) and \
           receiver.state in (YappState.DONE, YappState.ABORT):
            break

    print(f"\nSender   final state: {sender.state.value}")
    print(f"Receiver final state: {receiver.state.value}")
    print(f"Sender   sent     : {sender.bytes_done} bytes")
    print(f"Receiver received : {receiver.bytes_done} bytes")
    print(f"YAPP-C negotiated : {sender.yappc} / {receiver.yappc}")

    # Verify round-trip
    received = bytes(receiver.bytes_received)
    if received == payload:
        print("\n✓ Round-trip SUCCESS — bytes match exactly")
    else:
        print(f"\n✗ Round-trip FAILED — sent {len(payload)} got {len(received)}")
        if len(received) > 0:
            print(f"  First 40 bytes received: {received[:40]!r}")
