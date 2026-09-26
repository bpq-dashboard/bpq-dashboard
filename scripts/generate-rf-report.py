#!/usr/bin/env python3
"""
generate-rf-report.py — Generate PDF activity report for a callsign

Reads from the rf_connections MySQL table populated by parse-vara-log.py
and produces a styled PDF report. Invoked by the dashboard's
generate-report.php endpoint when the user clicks Generate Report.

Usage:
    generate-rf-report.py --callsign N4SD --hours 24 --output /tmp/report.pdf
    generate-rf-report.py --callsign N4SD --hours 168 --direction outgoing \
                          --output /tmp/report.pdf

Environment variables (override defaults):
    BPQ_DB_HOST, BPQ_DB_NAME, BPQ_DB_USER, BPQ_DB_PASS
"""

import argparse
import os
import re
import sys
from datetime import datetime

try:
    import pymysql
except ImportError:
    print("ERROR: pymysql not installed. Run: sudo apt install python3-pymysql",
          file=sys.stderr)
    sys.exit(2)

try:
    from reportlab.lib import colors
    from reportlab.lib.pagesizes import letter, landscape
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.lib.units import inch
    from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer,
                                    Table, TableStyle)
    from reportlab.lib.enums import TA_CENTER
except ImportError:
    print("ERROR: reportlab not installed. Run: "
          "pip install reportlab --break-system-packages", file=sys.stderr)
    sys.exit(2)

DB_CONFIG = {
    'host':     os.environ.get('BPQ_DB_HOST', '127.0.0.1'),
    'database': os.environ.get('BPQ_DB_NAME', 'bpqdash'),
    'user':     os.environ.get('BPQ_DB_USER', 'bpqdash_user'),
    'password': os.environ.get('BPQ_DB_PASS', ''),
    'charset':  'utf8mb4',
    'cursorclass': pymysql.cursors.DictCursor,
}

CALLSIGN_RE = re.compile(r'^[A-Z0-9]{3,7}$')


def safe_callsign(callsign):
    """Validate callsign — uppercase, 3-7 alphanum chars (base call, no SSID)."""
    if not callsign:
        raise ValueError("callsign required")
    cs = callsign.upper().strip()
    # If the user passed an SSID-qualified callsign, strip the SSID
    if '-' in cs:
        cs = cs.split('-', 1)[0]
    if not CALLSIGN_RE.match(cs):
        raise ValueError(f"invalid callsign: {callsign!r}")
    return cs


def fetch_data(callsign, hours, direction='all'):
    """Query the DB for sessions matching the criteria."""
    conn = pymysql.connect(**DB_CONFIG)
    try:
        with conn.cursor() as cur:
            # Build WHERE clause
            where = ["base_call = %s",
                     "session_start >= DATE_SUB(NOW(), INTERVAL %s HOUR)"]
            params = [callsign, hours]
            if direction in ('outgoing', 'incoming'):
                where.append("direction = %s")
                params.append(direction)

            # Detail
            cur.execute(f"""
                SELECT callsign, direction, status, failure_reason,
                       session_start, session_end, duration_sec,
                       avg_snr_db, tx_bytes, rx_bytes,
                       tx_max_bps, rx_max_bps,
                       freq_khz, band
                FROM rf_connections
                WHERE {' AND '.join(where)}
                ORDER BY session_start DESC
            """, params)
            details = cur.fetchall()

            # Summary
            cur.execute(f"""
                SELECT
                    COUNT(*)                                                   AS total,
                    SUM(status='success')                                      AS successful,
                    SUM(status='failed')                                       AS failed,
                    ROUND(SUM(status='success') * 100.0 / NULLIF(COUNT(*),0),1) AS success_pct,
                    SUM(direction='outgoing')                                  AS outgoing,
                    SUM(direction='incoming')                                  AS incoming,
                    ROUND(AVG(NULLIF(avg_snr_db, 0)), 1)                       AS avg_snr,
                    MAX(tx_max_bps)                                            AS peak_tx,
                    MAX(rx_max_bps)                                            AS peak_rx,
                    ROUND(AVG(NULLIF(tx_max_bps, 0)))                          AS avg_peak_tx,
                    ROUND(AVG(NULLIF(rx_max_bps, 0)))                          AS avg_peak_rx,
                    SUM(tx_bytes)                                              AS total_tx,
                    SUM(rx_bytes)                                              AS total_rx,
                    SUM(duration_sec)                                          AS total_dur,
                    CASE WHEN SUM(duration_sec) > 0
                         THEN ROUND(SUM(tx_bytes+rx_bytes)*8.0/SUM(duration_sec))
                         ELSE 0 END                                            AS avg_rate
                FROM rf_connections
                WHERE {' AND '.join(where)}
            """, params)
            summary = cur.fetchone()

            # Per-frequency performance — best-performing frequencies first
            # (highest success rate, then highest avg S/N as tiebreaker).
            # Excludes rows with no resolved frequency (freq_khz IS NULL —
            # WL2K/unknown-mode sessions that never got BBS-log correlation).
            cur.execute(f"""
                SELECT
                    freq_khz,
                    band,
                    COUNT(*)                                                   AS total,
                    SUM(status='success')                                      AS successful,
                    ROUND(SUM(status='success') * 100.0 / NULLIF(COUNT(*),0),1) AS success_pct,
                    ROUND(AVG(NULLIF(avg_snr_db, 0)), 1)                       AS avg_snr
                FROM rf_connections
                WHERE {' AND '.join(where)} AND freq_khz IS NOT NULL
                GROUP BY freq_khz, band
                ORDER BY success_pct DESC, avg_snr DESC, total DESC
                LIMIT 10
            """, params)
            freq_stats = cur.fetchall()
    finally:
        conn.close()
    return details, summary, freq_stats


def fmt_dur(secs):
    """Format seconds as MmSSs."""
    if secs is None or secs == 0:
        return '-'
    s = int(secs)
    return f"{s // 60}m{s % 60:02d}s"


def fmt_status(status, failure_reason):
    """Pretty status with checkmark/x."""
    if status == 'success':
        return '\u2713 ok'
    mapping = {
        'no_data': '\u2717 no data',
        'superseded': '\u26a0 superseded',
        'port_in_use': '\u2717 port busy',
        'incomplete_no_disconnect': '\u26a0 stuck',
        'app_disconnect': '\u2717 app gone',
    }
    return mapping.get(failure_reason,
                       f"? {failure_reason}" if failure_reason else '? unknown')


def fmt_dir(direction):
    return 'OUT \u2192' if direction == 'outgoing' else '\u2190 IN'


def fmt_freq(khz):
    if not khz:
        return '-'
    return f"{int(khz) / 1000:.4f}"


def fmt_avg_rate(tx, rx, dur):
    if not dur or dur == 0:
        return '-'
    return f"{round((int(tx or 0) + int(rx or 0)) * 8.0 / int(dur))}"


def fmt_bytes(n):
    """Human readable byte count."""
    if n is None:
        return '-'
    n = int(n)
    if n < 1024:
        return str(n)
    if n < 1024 * 1024:
        return f"{n / 1024:.1f}K"
    return f"{n / (1024 * 1024):.1f}M"


def build_pdf(callsign, hours, direction, details, summary, freq_stats,
              output_path):
    """Render the PDF document."""
    doc = SimpleDocTemplate(
        output_path,
        pagesize=landscape(letter),
        rightMargin=0.4 * inch, leftMargin=0.4 * inch,
        topMargin=0.4 * inch, bottomMargin=0.4 * inch,
        title=f"{callsign} RF Activity Report",
        author="BPQ Dashboard",
    )
    styles = getSampleStyleSheet()
    elements = []

    # Title
    title_style = ParagraphStyle('TitleCustom', parent=styles['Heading1'],
                                  fontSize=18,
                                  textColor=colors.HexColor('#6366f1'),
                                  spaceAfter=8, alignment=TA_CENTER)
    elements.append(Paragraph(f"{callsign} \u2014 RF Activity Report",
                              title_style))

    # Subtitle
    dir_label = {'all': 'All directions', 'outgoing': 'Outgoing only',
                 'incoming': 'Incoming only'}.get(direction, 'All directions')
    sub_style = ParagraphStyle('Sub', parent=styles['Normal'],
                                fontSize=10,
                                textColor=colors.HexColor('#666666'),
                                alignment=TA_CENTER, spaceAfter=14)
    elements.append(Paragraph(
        f"Last {hours} hours \u00b7 {dir_label} \u00b7 "
        f"Generated {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
        sub_style))

    # Shared section-header style, used by Summary / Best Frequencies /
    # Connections below.
    h_style = ParagraphStyle('SectionHeader', parent=styles['Heading2'],
                              fontSize=12,
                              textColor=colors.HexColor('#374151'),
                              spaceAfter=6)

    # Summary section
    if summary and summary['total']:
        elements.append(Paragraph("Summary", h_style))

        peak_tx = summary.get('peak_tx') or 0
        peak_rx = summary.get('peak_rx') or 0
        ap_tx = summary.get('avg_peak_tx') or 0
        ap_rx = summary.get('avg_peak_rx') or 0
        total_dur = int(summary.get('total_dur') or 0)
        h = total_dur // 3600
        m = (total_dur % 3600) // 60
        s = total_dur % 60

        rows = [
            ['Total connections', str(summary['total'])],
            ['Successful',
             f"{summary['successful']} ({summary.get('success_pct') or 0}%)"],
            ['Failed', str(summary['failed'])],
            ['Outgoing / Incoming',
             f"{summary['outgoing']} / {summary['incoming']}"],
            ['Average S/N',
             f"{summary['avg_snr']} dB" if summary.get('avg_snr') is not None
             else '-'],
            ['Peak TX / RX', f"{peak_tx} / {peak_rx} bps"],
            ['Avg peak TX / RX', f"{ap_tx} / {ap_rx} bps"],
            ['Effective avg rate',
             f"{summary.get('avg_rate') or 0} bps"],
            ['Total bytes TX / RX',
             f"{fmt_bytes(summary['total_tx'])} / "
             f"{fmt_bytes(summary['total_rx'])}"],
            ['Total airtime', f"{h:d}h {m:02d}m {s:02d}s"],
        ]

        summary_table = Table(rows, colWidths=[2.5 * inch, 3.5 * inch])
        summary_table.setStyle(TableStyle([
            ('FONT', (0, 0), (-1, -1), 'Helvetica', 10),
            ('FONT', (0, 0), (0, -1), 'Helvetica-Bold', 10),
            ('BACKGROUND', (0, 0), (0, -1), colors.HexColor('#f3f4f6')),
            ('TEXTCOLOR', (0, 0), (0, -1), colors.HexColor('#374151')),
            ('TEXTCOLOR', (1, 0), (1, -1), colors.HexColor('#111827')),
            ('ROWBACKGROUNDS', (1, 0), (1, -1),
             [colors.white, colors.HexColor('#fafafa')]),
            ('GRID', (0, 0), (-1, -1), 0.25, colors.HexColor('#e5e7eb')),
            ('LEFTPADDING', (0, 0), (-1, -1), 8),
            ('RIGHTPADDING', (0, 0), (-1, -1), 8),
            ('TOPPADDING', (0, 0), (-1, -1), 5),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 5),
        ]))
        elements.append(summary_table)
        elements.append(Spacer(1, 0.25 * inch))

    # Best-frequencies section — per-frequency success rate / avg S/N,
    # best-performing first. Only shown when there's at least one session
    # with a resolved frequency.
    if freq_stats:
        elements.append(Paragraph("Best Frequencies", h_style))

        freq_headers = ['Freq MHz', 'Band', 'Connections', 'Success %',
                         'Avg S/N']
        freq_rows = [freq_headers]
        for fs in freq_stats:
            freq_rows.append([
                fmt_freq(fs['freq_khz']),
                fs['band'] or '-',
                str(fs['total']),
                f"{fs.get('success_pct') or 0}%",
                f"{fs['avg_snr']} dB" if fs.get('avg_snr') is not None
                else '-',
            ])

        freq_table = Table(freq_rows,
                            colWidths=[1.1 * inch, 0.9 * inch, 1.2 * inch,
                                       1.1 * inch, 1.0 * inch])
        freq_table.setStyle(TableStyle([
            ('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#6366f1')),
            ('TEXTCOLOR', (0, 0), (-1, 0), colors.white),
            ('FONT', (0, 0), (-1, 0), 'Helvetica-Bold', 9),
            ('ALIGN', (0, 0), (-1, 0), 'CENTER'),
            ('ALIGN', (0, 1), (-1, -1), 'CENTER'),
            ('TOPPADDING', (0, 0), (-1, 0), 6),
            ('BOTTOMPADDING', (0, 0), (-1, 0), 6),
            ('FONT', (0, 1), (-1, -1), 'Helvetica', 9),
            ('ROWBACKGROUNDS', (0, 1), (-1, -1),
             [colors.white, colors.HexColor('#f9fafb')]),
            ('GRID', (0, 0), (-1, -1), 0.25, colors.HexColor('#e5e7eb')),
            ('TOPPADDING', (0, 1), (-1, -1), 4),
            ('BOTTOMPADDING', (0, 1), (-1, -1), 4),
        ]))
        elements.append(freq_table)
        elements.append(Spacer(1, 0.25 * inch))

    # Detail table
    elements.append(Paragraph(f"Connections ({len(details)})", h_style))

    if not details:
        elements.append(Paragraph(
            f"No connections to/from {callsign} in the last {hours} hours.",
            styles['Normal']))
    else:
        headers = ['Station', 'Start', 'Dir', 'Result', 'Duration', 'SNR',
                   'TX/RX bytes', 'Max bps', 'Avg bps', 'Freq MHz', 'Band']
        table_data = [headers]

        for row in details:
            ts = row['session_start']
            ts_str = ts.strftime('%a %m-%d %H:%M:%S') if ts else '-'
            table_data.append([
                row['callsign'],
                ts_str,
                fmt_dir(row['direction']),
                fmt_status(row['status'], row['failure_reason']),
                fmt_dur(row['duration_sec']),
                f"{row['avg_snr_db']}" if row.get('avg_snr_db') is not None
                else '-',
                f"{row['tx_bytes'] or 0}/{row['rx_bytes'] or 0}",
                f"{row['tx_max_bps'] or 0}/{row['rx_max_bps'] or 0}",
                fmt_avg_rate(row['tx_bytes'], row['rx_bytes'],
                             row['duration_sec']),
                fmt_freq(row['freq_khz']),
                row['band'] or '-',
            ])

        col_widths = [0.75 * inch, 1.15 * inch, 0.55 * inch, 0.95 * inch,
                      0.7 * inch, 0.5 * inch, 0.95 * inch, 0.95 * inch,
                      0.7 * inch, 0.7 * inch, 0.5 * inch]
        detail_table = Table(table_data, colWidths=col_widths, repeatRows=1)
        detail_table.setStyle(TableStyle([
            # Header
            ('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#6366f1')),
            ('TEXTCOLOR', (0, 0), (-1, 0), colors.white),
            ('FONT', (0, 0), (-1, 0), 'Helvetica-Bold', 9),
            ('ALIGN', (0, 0), (-1, 0), 'CENTER'),
            ('TOPPADDING', (0, 0), (-1, 0), 6),
            ('BOTTOMPADDING', (0, 0), (-1, 0), 6),

            # Body
            ('FONT', (0, 1), (-1, -1), 'Helvetica', 8),
            ('ROWBACKGROUNDS', (0, 1), (-1, -1),
             [colors.white, colors.HexColor('#f9fafb')]),
            ('GRID', (0, 0), (-1, -1), 0.25, colors.HexColor('#e5e7eb')),
            ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
            ('LEFTPADDING', (0, 1), (-1, -1), 5),
            ('RIGHTPADDING', (0, 1), (-1, -1), 5),
            ('TOPPADDING', (0, 1), (-1, -1), 3),
            ('BOTTOMPADDING', (0, 1), (-1, -1), 3),
        ]))

        # Per-row status coloring
        for i, row in enumerate(details, start=1):
            color = (colors.HexColor('#10b981') if row['status'] == 'success'
                     else colors.HexColor('#ef4444'))
            detail_table.setStyle(TableStyle([
                ('TEXTCOLOR', (3, i), (3, i), color),
                ('FONT', (3, i), (3, i), 'Helvetica-Bold', 8),
            ]))

        elements.append(detail_table)

    # Footer
    elements.append(Spacer(1, 0.2 * inch))
    footer_style = ParagraphStyle('Footer', parent=styles['Normal'],
                                   fontSize=8,
                                   textColor=colors.HexColor('#9ca3af'),
                                   alignment=TA_CENTER)
    elements.append(Paragraph(
        "Generated by BPQ Dashboard \u2014 RF Connections database",
        footer_style))

    doc.build(elements)


def main():
    parser = argparse.ArgumentParser(
        description="Generate PDF RF activity report for a callsign")
    parser.add_argument('--callsign', required=True,
                        help="Base callsign (without SSID) e.g. N4SD")
    parser.add_argument('--hours', type=int, default=24,
                        help="Hours of history (default: 24)")
    parser.add_argument('--direction', default='all',
                        choices=['all', 'outgoing', 'incoming'],
                        help="Direction filter (default: all)")
    parser.add_argument('--output', required=True,
                        help="Output PDF path")
    args = parser.parse_args()

    if args.hours < 1 or args.hours > 24 * 365:
        print("ERROR: --hours must be 1 to 8760 (one year)", file=sys.stderr)
        sys.exit(1)

    try:
        callsign = safe_callsign(args.callsign)
    except ValueError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)

    try:
        details, summary, freq_stats = fetch_data(callsign, args.hours,
                                                   args.direction)
    except pymysql.MySQLError as e:
        print(f"ERROR: database query failed: {e}", file=sys.stderr)
        sys.exit(3)

    build_pdf(callsign, args.hours, args.direction, details, summary,
              freq_stats, args.output)

    print(f"PDF written: {args.output} ({len(details)} sessions)")


if __name__ == '__main__':
    main()
