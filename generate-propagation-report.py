#!/usr/bin/env python3
"""
BPQ Dashboard - Propagation Recommendation Report Generator
Analyzes VARA HF connection logs to recommend optimal bands, times, and stations.

Usage: python3 generate-propagation-report.py [logs_directory] [output_file]
"""

import os
import sys
import re
import glob
from datetime import datetime, timedelta
from collections import defaultdict
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import inch
from reportlab.lib.colors import HexColor, black, white, gray
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak, Image
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT

# Configuration
LOGS_DIR = './logs'
OUTPUT_FILE = './propagation-report.pdf'

# Band definitions (frequency ranges in MHz)
BAND_RANGES = {
    '160m': (1.8, 2.0),
    '80m': (3.5, 4.0),
    '60m': (5.3, 5.4),
    '40m': (7.0, 7.3),
    '30m': (10.1, 10.15),
    '20m': (14.0, 14.35),
    '17m': (18.068, 18.168),
    '15m': (21.0, 21.45),
    '12m': (24.89, 24.99),
    '10m': (28.0, 29.7),
}

# Time periods (UTC)
TIME_PERIODS = [
    ('Night (00-06Z)', 0, 6),
    ('Morning (06-12Z)', 6, 12),
    ('Afternoon (12-18Z)', 12, 18),
    ('Evening (18-00Z)', 18, 24),
]

# Colors for the report
COLORS = {
    'primary': HexColor('#667eea'),
    'secondary': HexColor('#764ba2'),
    'success': HexColor('#10b981'),
    'warning': HexColor('#f59e0b'),
    'danger': HexColor('#ef4444'),
    'light': HexColor('#f3f4f6'),
    'dark': HexColor('#1f2937'),
}


def freq_to_band(freq_mhz):
    """Convert frequency to band name."""
    if not freq_mhz:
        return None
    for band, (low, high) in BAND_RANGES.items():
        if low <= freq_mhz <= high:
            return band
    return None


def parse_vara_logs(logs_dir):
    """Parse VARA HF logs and extract connection data."""
    connections = []
    
    # Find all VARA log files
    log_patterns = [
        os.path.join(logs_dir, 'VARAHF_*.txt'),
        os.path.join(logs_dir, 'VARA_*.txt'),
        os.path.join(logs_dir, 'vara*.log'),
        os.path.join(logs_dir, '*.vara'),  # Includes yourcall.vara
    ]
    
    log_files = []
    for pattern in log_patterns:
        log_files.extend(glob.glob(pattern))
    
    if not log_files:
        print(f"Warning: No VARA log files found in {logs_dir}")
        return connections
    
    current_year = datetime.now().year
    
    for log_file in log_files:
        try:
            with open(log_file, 'r', encoding='utf-8', errors='ignore') as f:
                lines = f.readlines()
        except Exception as e:
            print(f"Error reading {log_file}: {e}")
            continue
        
        current_conn = None
        
        for line in lines:
            line = line.strip()
            
            # Parse timestamp: "Jan 19 12:34:56"
            ts_match = re.match(r'^(\w{3})\s+(\d{1,2})\s+(\d{2}):(\d{2}):(\d{2})', line)
            if not ts_match:
                continue
            
            mon_str, day, hour, minute, second = ts_match.groups()
            
            # Convert month name to number
            months = {'Jan': 1, 'Feb': 2, 'Mar': 3, 'Apr': 4, 'May': 5, 'Jun': 6,
                     'Jul': 7, 'Aug': 8, 'Sep': 9, 'Oct': 10, 'Nov': 11, 'Dec': 12}
            month = months.get(mon_str, 1)
            
            # Handle year rollover
            year = current_year
            if month > datetime.now().month:
                year -= 1
            
            try:
                timestamp = datetime(year, month, int(day), int(hour), int(minute), int(second))
            except ValueError:
                continue
            
            # Only process last 7 days
            if datetime.now() - timestamp > timedelta(days=7):
                continue
            
            # Outgoing connection: "Connected to K7EK VARA HF"
            conn_match = re.search(r'Connected to ([A-Z0-9-]+)\s+VARA HF', line, re.IGNORECASE)
            if conn_match:
                current_conn = {
                    'callsign': conn_match.group(1),
                    'type': 'outgoing',
                    'timestamp': timestamp,
                    'hour': int(hour),
                    'snr': None,
                    'freq': None,
                    'tx_bytes': 0,
                    'rx_bytes': 0,
                    'max_bps': 0,
                    'status': 'connected'
                }
                continue
            
            # Incoming connection: "VARAHF K7EK connected VARA HF"
            incoming_match = re.search(r'VARAHF\s+([A-Z0-9-]+)\s+connected\s+VARA HF', line, re.IGNORECASE)
            if incoming_match:
                current_conn = {
                    'callsign': incoming_match.group(1),
                    'type': 'incoming',
                    'timestamp': timestamp,
                    'hour': int(hour),
                    'snr': None,
                    'freq': None,
                    'tx_bytes': 0,
                    'rx_bytes': 0,
                    'max_bps': 0,
                    'status': 'connected'
                }
                continue
            
            # S/N ratio: "K7EK Average S/N: 12.5 dB"
            snr_match = re.search(r'Average S/N:\s+([-\d.]+)\s+dB', line, re.IGNORECASE)
            if snr_match and current_conn:
                current_conn['snr'] = float(snr_match.group(1))
                continue
            
            # Disconnection with stats
            disc_match = re.search(r'Disconnected\s+TX:\s+(\d+)\s+Bytes\s+\(Max:\s+(\d+)\s+bps\)\s+RX:\s+(\d+)\s+Bytes\s+\(Max:\s+(\d+)\s+bps\)', line, re.IGNORECASE)
            if disc_match and current_conn:
                tx, tx_bps, rx, rx_bps = disc_match.groups()
                current_conn['tx_bytes'] = int(tx)
                current_conn['rx_bytes'] = int(rx)
                current_conn['max_bps'] = max(int(tx_bps), int(rx_bps))
                
                # Determine success
                total_bytes = current_conn['tx_bytes'] + current_conn['rx_bytes']
                current_conn['status'] = 'success' if total_bytes > 0 or current_conn['snr'] else 'failed'
                
                connections.append(current_conn)
                current_conn = None
    
    return connections


def parse_bbs_logs(logs_dir):
    """Parse BBS logs to extract frequency information."""
    freq_data = {}
    
    log_patterns = [
        os.path.join(logs_dir, 'BBS_*.txt'),
        os.path.join(logs_dir, 'bbs*.log'),
    ]
    
    log_files = []
    for pattern in log_patterns:
        log_files.extend(glob.glob(pattern))
    
    for log_file in log_files:
        try:
            with open(log_file, 'r', encoding='utf-8', errors='ignore') as f:
                for line in f:
                    # Look for frequency info: "Freq 14108500"
                    freq_match = re.search(r'Freq\s+(\d+)', line)
                    if freq_match:
                        freq_hz = int(freq_match.group(1))
                        freq_mhz = freq_hz / 1000000
                        
                        # Extract callsign if present
                        call_match = re.search(r'([A-Z]{1,2}[0-9][A-Z]{1,3}(-\d+)?)', line)
                        if call_match:
                            callsign = call_match.group(1)
                            # Extract time
                            time_match = re.search(r'(\d{2}):(\d{2}):(\d{2})', line)
                            if time_match:
                                key = f"{callsign}_{time_match.group(1)}_{time_match.group(2)}"
                                freq_data[key] = freq_mhz
        except Exception as e:
            print(f"Error reading {log_file}: {e}")
    
    return freq_data


def analyze_connections(connections):
    """Analyze connections and generate statistics."""
    stats = {
        'total': len(connections),
        'successful': 0,
        'failed': 0,
        'by_station': defaultdict(lambda: {
            'successful': 0, 'failed': 0, 'snr_values': [], 'bps_values': [],
            'by_band': defaultdict(lambda: {'successful': 0, 'failed': 0, 'snr_values': []}),
            'by_period': defaultdict(lambda: {'successful': 0, 'failed': 0, 'snr_values': []})
        }),
        'by_band': defaultdict(lambda: {
            'successful': 0, 'failed': 0, 'snr_values': [], 'bps_values': [],
            'by_period': defaultdict(lambda: {'successful': 0, 'failed': 0, 'snr_values': []})
        }),
        'by_period': defaultdict(lambda: {
            'successful': 0, 'failed': 0, 'snr_values': [],
            'by_band': defaultdict(lambda: {'successful': 0, 'failed': 0, 'snr_values': []})
        }),
        'recommendations': []
    }
    
    for conn in connections:
        callsign = conn['callsign'].split('-')[0]  # Base callsign
        is_success = conn['status'] == 'success'
        hour = conn['hour']
        snr = conn['snr']
        bps = conn['max_bps']
        freq = conn.get('freq')
        band = freq_to_band(freq) if freq else None
        
        # Determine time period
        period = None
        for name, start, end in TIME_PERIODS:
            if start <= hour < end or (end == 24 and hour >= start):
                period = name
                break
        
        # Overall stats
        if is_success:
            stats['successful'] += 1
        else:
            stats['failed'] += 1
        
        # By station
        station_stats = stats['by_station'][callsign]
        if is_success:
            station_stats['successful'] += 1
        else:
            station_stats['failed'] += 1
        if snr is not None:
            station_stats['snr_values'].append(snr)
        if bps > 0:
            station_stats['bps_values'].append(bps)
        
        # Station by band
        if band:
            band_stats = station_stats['by_band'][band]
            if is_success:
                band_stats['successful'] += 1
            else:
                band_stats['failed'] += 1
            if snr is not None:
                band_stats['snr_values'].append(snr)
            if bps > 0:
                if 'bps_values' not in band_stats:
                    band_stats['bps_values'] = []
                band_stats['bps_values'].append(bps)
        
        # Station by period
        if period:
            period_stats = station_stats['by_period'][period]
            if is_success:
                period_stats['successful'] += 1
            else:
                period_stats['failed'] += 1
            if snr is not None:
                period_stats['snr_values'].append(snr)
        
        # By band
        if band:
            band_all = stats['by_band'][band]
            if is_success:
                band_all['successful'] += 1
            else:
                band_all['failed'] += 1
            if snr is not None:
                band_all['snr_values'].append(snr)
            if bps > 0:
                band_all['bps_values'].append(bps)
            
            # Band by period
            if period:
                bp_stats = band_all['by_period'][period]
                if is_success:
                    bp_stats['successful'] += 1
                else:
                    bp_stats['failed'] += 1
                if snr is not None:
                    bp_stats['snr_values'].append(snr)
        
        # By period
        if period:
            period_all = stats['by_period'][period]
            if is_success:
                period_all['successful'] += 1
            else:
                period_all['failed'] += 1
            if snr is not None:
                period_all['snr_values'].append(snr)
            
            # Period by band
            if band:
                pb_stats = period_all['by_band'][band]
                if is_success:
                    pb_stats['successful'] += 1
                else:
                    pb_stats['failed'] += 1
                if snr is not None:
                    pb_stats['snr_values'].append(snr)
    
    return stats


def generate_recommendations(stats):
    """Generate band/time/station recommendations based on analysis."""
    recommendations = []
    
    # Best overall bands
    band_scores = []
    for band, data in stats['by_band'].items():
        total = data['successful'] + data['failed']
        if total >= 2:  # Minimum connections for recommendation
            success_rate = data['successful'] / total * 100
            avg_snr = sum(data['snr_values']) / len(data['snr_values']) if data['snr_values'] else 0
            avg_bps = sum(data['bps_values']) / len(data['bps_values']) if data['bps_values'] else 0
            score = success_rate * 0.4 + min(avg_snr + 20, 40) * 0.3 + min(avg_bps / 100, 30) * 0.3
            band_scores.append((band, score, success_rate, avg_snr, avg_bps, total))
    
    band_scores.sort(key=lambda x: x[1], reverse=True)
    
    # Best band by time period
    period_recommendations = {}
    for period_name, _, _ in TIME_PERIODS:
        if period_name in stats['by_period']:
            period_data = stats['by_period'][period_name]
            best_band = None
            best_score = -1
            
            for band, data in period_data['by_band'].items():
                total = data['successful'] + data['failed']
                if total >= 1:
                    success_rate = data['successful'] / total * 100
                    avg_snr = sum(data['snr_values']) / len(data['snr_values']) if data['snr_values'] else 0
                    score = success_rate * 0.6 + min(avg_snr + 20, 40) * 0.4
                    if score > best_score:
                        best_score = score
                        best_band = (band, success_rate, avg_snr, total)
            
            if best_band:
                period_recommendations[period_name] = best_band
    
    # Best stations to connect to
    station_scores = []
    for callsign, data in stats['by_station'].items():
        total = data['successful'] + data['failed']
        if total >= 2:  # Minimum connections
            success_rate = data['successful'] / total * 100
            avg_snr = sum(data['snr_values']) / len(data['snr_values']) if data['snr_values'] else 0
            avg_bps = sum(data['bps_values']) / len(data['bps_values']) if data['bps_values'] else 0
            score = success_rate * 0.5 + min(avg_snr + 20, 30) * 0.3 + min(avg_bps / 100, 20) * 0.2
            
            # Find best band/time for this station
            best_band = max(data['by_band'].items(), 
                          key=lambda x: x[1]['successful'], default=(None, None))[0] if data['by_band'] else None
            best_period = max(data['by_period'].items(),
                            key=lambda x: x[1]['successful'], default=(None, None))[0] if data['by_period'] else None
            
            # Calculate best bands by S/N and Bitrate for this station
            station_band_details = []
            for band, band_data in data['by_band'].items():
                band_total = band_data['successful'] + band_data['failed']
                if band_total >= 1:
                    band_snr = sum(band_data['snr_values']) / len(band_data['snr_values']) if band_data['snr_values'] else 0
                    band_bps = sum(band_data.get('bps_values', [])) / len(band_data.get('bps_values', [])) if band_data.get('bps_values') else 0
                    band_sr = band_data['successful'] / band_total * 100
                    station_band_details.append({
                        'band': band,
                        'snr': band_snr,
                        'bps': band_bps,
                        'success_rate': band_sr,
                        'connections': band_total
                    })
            
            # Sort by S/N to find best S/N band
            best_snr_band = max(station_band_details, key=lambda x: x['snr'], default=None) if station_band_details else None
            # Sort by bitrate to find best speed band
            best_bps_band = max(station_band_details, key=lambda x: x['bps'], default=None) if station_band_details else None
            
            station_scores.append({
                'callsign': callsign,
                'score': score,
                'success_rate': success_rate,
                'avg_snr': avg_snr,
                'avg_bps': avg_bps,
                'total': total,
                'best_band': best_band,
                'best_period': best_period,
                'band_details': station_band_details,
                'best_snr_band': best_snr_band,
                'best_bps_band': best_bps_band
            })
    
    station_scores.sort(key=lambda x: x['score'], reverse=True)
    
    return {
        'best_bands': band_scores[:5],
        'period_recommendations': period_recommendations,
        'best_stations': station_scores[:15],
    }


def create_pdf_report(stats, recommendations, output_file):
    """Create the PDF report."""
    doc = SimpleDocTemplate(output_file, pagesize=letter,
                           leftMargin=0.75*inch, rightMargin=0.75*inch,
                           topMargin=0.75*inch, bottomMargin=0.75*inch)
    
    styles = getSampleStyleSheet()
    
    # Custom styles
    styles.add(ParagraphStyle(
        'CustomTitle',
        parent=styles['Title'],
        fontSize=24,
        textColor=COLORS['primary'],
        spaceAfter=20
    ))
    
    styles.add(ParagraphStyle(
        'CustomHeading',
        parent=styles['Heading1'],
        fontSize=16,
        textColor=COLORS['secondary'],
        spaceBefore=20,
        spaceAfter=10
    ))
    
    styles.add(ParagraphStyle(
        'CustomHeading2',
        parent=styles['Heading2'],
        fontSize=13,
        textColor=COLORS['dark'],
        spaceBefore=15,
        spaceAfter=8
    ))
    
    styles.add(ParagraphStyle(
        'RecommendationText',
        parent=styles['Normal'],
        fontSize=11,
        leading=16,
        spaceBefore=5,
        spaceAfter=5
    ))
    
    story = []
    
    # Title
    story.append(Paragraph("📡 BPQ Propagation Report", styles['CustomTitle']))
    story.append(Paragraph(f"7-Day Connection Analysis • Generated {datetime.now().strftime('%Y-%m-%d %H:%M UTC')}", 
                          styles['Normal']))
    story.append(Spacer(1, 20))
    
    # Executive Summary
    story.append(Paragraph("📊 Executive Summary", styles['CustomHeading']))
    
    total = stats['total']
    successful = stats['successful']
    failed = stats['failed']
    success_rate = (successful / total * 100) if total > 0 else 0
    
    summary_data = [
        ['Metric', 'Value'],
        ['Total Connections (7 days)', str(total)],
        ['Successful Connections', f"{successful} ({success_rate:.1f}%)"],
        ['Failed Connections', str(failed)],
        ['Unique Stations', str(len(stats['by_station']))],
        ['Bands Used', str(len(stats['by_band']))],
    ]
    
    summary_table = Table(summary_data, colWidths=[3*inch, 2*inch])
    summary_table.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, 0), COLORS['primary']),
        ('TEXTCOLOR', (0, 0), (-1, 0), white),
        ('FONTNAME', (0, 0), (-1, 0), 'Helvetica-Bold'),
        ('FONTSIZE', (0, 0), (-1, 0), 11),
        ('BACKGROUND', (0, 1), (-1, -1), COLORS['light']),
        ('FONTNAME', (0, 1), (-1, -1), 'Helvetica'),
        ('FONTSIZE', (0, 1), (-1, -1), 10),
        ('ALIGN', (1, 0), (1, -1), 'CENTER'),
        ('GRID', (0, 0), (-1, -1), 0.5, gray),
        ('TOPPADDING', (0, 0), (-1, -1), 8),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 8),
    ]))
    story.append(summary_table)
    story.append(Spacer(1, 20))
    
    # Best Bands Section
    story.append(Paragraph("🏆 Recommended Bands (Overall)", styles['CustomHeading']))
    
    if recommendations['best_bands']:
        band_data = [['Rank', 'Band', 'Success Rate', 'Avg S/N', 'Avg Speed', 'Connections']]
        for i, (band, score, sr, snr, bps, total) in enumerate(recommendations['best_bands'], 1):
            band_data.append([
                f"#{i}",
                band,
                f"{sr:.1f}%",
                f"{snr:.1f} dB" if snr else "N/A",
                f"{bps:.0f} bps" if bps else "N/A",
                str(total)
            ])
        
        band_table = Table(band_data, colWidths=[0.5*inch, 0.8*inch, 1.2*inch, 1*inch, 1*inch, 1*inch])
        band_table.setStyle(TableStyle([
            ('BACKGROUND', (0, 0), (-1, 0), COLORS['success']),
            ('TEXTCOLOR', (0, 0), (-1, 0), white),
            ('FONTNAME', (0, 0), (-1, 0), 'Helvetica-Bold'),
            ('FONTSIZE', (0, 0), (-1, 0), 10),
            ('BACKGROUND', (0, 1), (-1, -1), white),
            ('FONTNAME', (0, 1), (-1, -1), 'Helvetica'),
            ('FONTSIZE', (0, 1), (-1, -1), 9),
            ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
            ('GRID', (0, 0), (-1, -1), 0.5, gray),
            ('TOPPADDING', (0, 0), (-1, -1), 6),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 6),
        ]))
        story.append(band_table)
    else:
        story.append(Paragraph("Insufficient data to recommend bands.", styles['Normal']))
    
    story.append(Spacer(1, 20))
    
    # Time Period Recommendations
    story.append(Paragraph("🕐 Best Band by Time of Day (UTC)", styles['CustomHeading']))
    
    if recommendations['period_recommendations']:
        period_data = [['Time Period', 'Recommended Band', 'Success Rate', 'Avg S/N', 'Connections']]
        for period_name, _, _ in TIME_PERIODS:
            if period_name in recommendations['period_recommendations']:
                band, sr, snr, total = recommendations['period_recommendations'][period_name]
                period_data.append([
                    period_name,
                    band,
                    f"{sr:.1f}%",
                    f"{snr:.1f} dB" if snr else "N/A",
                    str(total)
                ])
            else:
                period_data.append([period_name, "No data", "-", "-", "0"])
        
        period_table = Table(period_data, colWidths=[1.5*inch, 1.3*inch, 1.1*inch, 1*inch, 1*inch])
        period_table.setStyle(TableStyle([
            ('BACKGROUND', (0, 0), (-1, 0), COLORS['secondary']),
            ('TEXTCOLOR', (0, 0), (-1, 0), white),
            ('FONTNAME', (0, 0), (-1, 0), 'Helvetica-Bold'),
            ('FONTSIZE', (0, 0), (-1, 0), 10),
            ('BACKGROUND', (0, 1), (-1, -1), white),
            ('FONTNAME', (0, 1), (-1, -1), 'Helvetica'),
            ('FONTSIZE', (0, 1), (-1, -1), 9),
            ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
            ('GRID', (0, 0), (-1, -1), 0.5, gray),
            ('TOPPADDING', (0, 0), (-1, -1), 6),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 6),
        ]))
        story.append(period_table)
    else:
        story.append(Paragraph("Insufficient data for time-based recommendations.", styles['Normal']))
    
    story.append(PageBreak())
    
    # Station Recommendations
    story.append(Paragraph("📻 Top Stations to Connect To", styles['CustomHeading']))
    story.append(Paragraph("Ranked by overall reliability, signal quality, and throughput.", styles['Normal']))
    story.append(Spacer(1, 10))
    
    if recommendations['best_stations']:
        station_data = [['Rank', 'Callsign', 'Success', 'Avg S/N', 'Speed', 'Best Band', 'Best Time']]
        for i, station in enumerate(recommendations['best_stations'][:15], 1):
            # Shorten period name for table
            period_short = station['best_period'].split('(')[0].strip() if station['best_period'] else "N/A"
            station_data.append([
                f"#{i}",
                station['callsign'],
                f"{station['success_rate']:.0f}% ({station['total']})",
                f"{station['avg_snr']:.1f} dB" if station['avg_snr'] else "N/A",
                f"{station['avg_bps']:.0f}" if station['avg_bps'] else "N/A",
                station['best_band'] or "N/A",
                period_short
            ])
        
        station_table = Table(station_data, colWidths=[0.5*inch, 1*inch, 1*inch, 0.9*inch, 0.7*inch, 0.8*inch, 1*inch])
        station_table.setStyle(TableStyle([
            ('BACKGROUND', (0, 0), (-1, 0), COLORS['primary']),
            ('TEXTCOLOR', (0, 0), (-1, 0), white),
            ('FONTNAME', (0, 0), (-1, 0), 'Helvetica-Bold'),
            ('FONTSIZE', (0, 0), (-1, 0), 9),
            ('BACKGROUND', (0, 1), (-1, -1), white),
            ('FONTNAME', (0, 1), (-1, -1), 'Helvetica'),
            ('FONTSIZE', (0, 1), (-1, -1), 8),
            ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
            ('GRID', (0, 0), (-1, -1), 0.5, gray),
            ('TOPPADDING', (0, 0), (-1, -1), 5),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 5),
            # Highlight top 3
            ('BACKGROUND', (0, 1), (-1, 1), HexColor('#dcfce7')),
            ('BACKGROUND', (0, 2), (-1, 2), HexColor('#dcfce7')),
            ('BACKGROUND', (0, 3), (-1, 3), HexColor('#dcfce7')),
        ]))
        story.append(station_table)
    else:
        story.append(Paragraph("Insufficient data for station recommendations.", styles['Normal']))
    
    story.append(Spacer(1, 20))
    
    # Best Bands Per Station Section
    story.append(Paragraph("📊 Best Bands Per Station (by S/N and Bitrate)", styles['CustomHeading']))
    story.append(Paragraph("Optimal band recommendations for each station based on signal quality and throughput.", styles['Normal']))
    story.append(Spacer(1, 10))
    
    if recommendations['best_stations']:
        band_per_station_data = [['Callsign', 'Best S/N Band', 'S/N (dB)', 'Best Speed Band', 'Speed (bps)', 'Conns']]
        
        for station in recommendations['best_stations'][:15]:
            best_snr = station.get('best_snr_band')
            best_bps = station.get('best_bps_band')
            
            snr_band = best_snr['band'] if best_snr else "N/A"
            snr_val = f"{best_snr['snr']:.1f}" if best_snr and best_snr['snr'] else "N/A"
            bps_band = best_bps['band'] if best_bps else "N/A"
            bps_val = f"{best_bps['bps']:.0f}" if best_bps and best_bps['bps'] else "N/A"
            
            band_per_station_data.append([
                station['callsign'],
                snr_band,
                snr_val,
                bps_band,
                bps_val,
                str(station['total'])
            ])
        
        band_station_table = Table(band_per_station_data, colWidths=[1.1*inch, 1.1*inch, 0.8*inch, 1.1*inch, 0.9*inch, 0.6*inch])
        band_station_table.setStyle(TableStyle([
            ('BACKGROUND', (0, 0), (-1, 0), HexColor('#0891b2')),  # Cyan
            ('TEXTCOLOR', (0, 0), (-1, 0), white),
            ('FONTNAME', (0, 0), (-1, 0), 'Helvetica-Bold'),
            ('FONTSIZE', (0, 0), (-1, 0), 9),
            ('BACKGROUND', (0, 1), (-1, -1), white),
            ('FONTNAME', (0, 1), (-1, -1), 'Helvetica'),
            ('FONTSIZE', (0, 1), (-1, -1), 8),
            ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
            ('ALIGN', (0, 1), (0, -1), 'LEFT'),
            ('GRID', (0, 0), (-1, -1), 0.5, gray),
            ('TOPPADDING', (0, 0), (-1, -1), 5),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 5),
        ]))
        story.append(band_station_table)
        
        story.append(Spacer(1, 15))
        
        # Detailed band breakdown for top 5 stations
        story.append(Paragraph("📋 Detailed Band Performance (Top 5 Stations)", styles['CustomHeading2']))
        
        for station in recommendations['best_stations'][:5]:
            if station['band_details']:
                story.append(Spacer(1, 8))
                story.append(Paragraph(f"<b>{station['callsign']}</b> - {station['total']} connections, {station['success_rate']:.0f}% success", styles['Normal']))
                
                detail_data = [['Band', 'Success Rate', 'Avg S/N', 'Avg Speed', 'Connections']]
                # Sort by S/N
                sorted_bands = sorted(station['band_details'], key=lambda x: x['snr'], reverse=True)
                for bd in sorted_bands:
                    detail_data.append([
                        bd['band'],
                        f"{bd['success_rate']:.0f}%",
                        f"{bd['snr']:.1f} dB" if bd['snr'] else "N/A",
                        f"{bd['bps']:.0f} bps" if bd['bps'] else "N/A",
                        str(bd['connections'])
                    ])
                
                detail_table = Table(detail_data, colWidths=[0.8*inch, 1*inch, 1*inch, 1*inch, 1*inch])
                detail_table.setStyle(TableStyle([
                    ('BACKGROUND', (0, 0), (-1, 0), HexColor('#6366f1')),  # Indigo
                    ('TEXTCOLOR', (0, 0), (-1, 0), white),
                    ('FONTNAME', (0, 0), (-1, 0), 'Helvetica-Bold'),
                    ('FONTSIZE', (0, 0), (-1, 0), 8),
                    ('BACKGROUND', (0, 1), (-1, -1), HexColor('#f5f3ff')),  # Light indigo
                    ('FONTNAME', (0, 1), (-1, -1), 'Helvetica'),
                    ('FONTSIZE', (0, 1), (-1, -1), 8),
                    ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
                    ('GRID', (0, 0), (-1, -1), 0.5, HexColor('#c7d2fe')),
                    ('TOPPADDING', (0, 0), (-1, -1), 4),
                    ('BOTTOMPADDING', (0, 0), (-1, -1), 4),
                ]))
                story.append(detail_table)
    
    story.append(Spacer(1, 20))
    
    # Detailed Station Recommendations
    story.append(Paragraph("📝 Detailed Station Recommendations", styles['CustomHeading2']))
    
    if recommendations['best_stations']:
        for i, station in enumerate(recommendations['best_stations'][:5], 1):
            rec_text = f"<b>#{i} {station['callsign']}</b>: "
            rec_parts = []
            
            best_band = station['best_band']
            best_period = station['best_period']
            snr = station['avg_snr']
            sr = station['success_rate']
            best_snr_band = station.get('best_snr_band')
            best_bps_band = station.get('best_bps_band')
            
            if best_band and best_period:
                period_short = best_period.split('(')[1].rstrip(')') if '(' in best_period else best_period
                rec_parts.append(f"Try <b>{best_band}</b> during <b>{period_short}</b>")
            elif best_band:
                rec_parts.append(f"Best results on <b>{best_band}</b>")
            elif best_period:
                period_short = best_period.split('(')[1].rstrip(')') if '(' in best_period else best_period
                rec_parts.append(f"Best results during <b>{period_short}</b>")
            
            # Add best S/N band recommendation
            if best_snr_band and best_snr_band['band'] != best_band:
                rec_parts.append(f"Best S/N on <b>{best_snr_band['band']}</b> ({best_snr_band['snr']:.0f} dB)")
            
            # Add best speed band recommendation
            if best_bps_band and best_bps_band['bps'] > 0 and best_bps_band['band'] != best_band:
                rec_parts.append(f"Fastest on <b>{best_bps_band['band']}</b> ({best_bps_band['bps']:.0f} bps)")
            
            if snr and snr > 10:
                rec_parts.append(f"Excellent signal ({snr:.0f} dB avg)")
            elif snr and snr > 0:
                rec_parts.append(f"Good signal ({snr:.0f} dB avg)")
            
            if sr >= 90:
                rec_parts.append(f"Very reliable ({sr:.0f}% success)")
            elif sr >= 70:
                rec_parts.append(f"Reliable ({sr:.0f}% success)")
            
            rec_text += ". ".join(rec_parts) + "."
            story.append(Paragraph(rec_text, styles['RecommendationText']))
    
    story.append(Spacer(1, 20))
    
    # Propagation Tips
    story.append(Paragraph("💡 General Propagation Tips", styles['CustomHeading']))
    
    tips = [
        "<b>80m (3.5-4 MHz)</b>: Best at night and early morning. Good for regional contacts up to ~500 miles during day, much longer at night.",
        "<b>40m (7 MHz)</b>: Excellent day and night band. Reliable for medium distances during day, longer paths at night.",
        "<b>30m (10 MHz)</b>: 24-hour band, good compromise between day/night propagation. Often less crowded.",
        "<b>20m (14 MHz)</b>: Primary daytime band. Best for long-distance during daylight hours. May stay open into evening.",
        "<b>Higher bands (15m, 12m, 10m)</b>: Daylight only, dependent on solar activity. Check solar flux index (SFI).",
    ]
    
    for tip in tips:
        story.append(Paragraph(f"• {tip}", styles['RecommendationText']))
    
    story.append(Spacer(1, 20))
    
    # Footer
    story.append(Paragraph("—" * 50, styles['Normal']))
    story.append(Paragraph(
        f"Report generated by BPQ Dashboard Propagation Analyzer<br/>"
        f"Based on {stats['total']} connections over the last 7 days<br/>"
        f"For best results, use this report alongside real-time propagation data from NOAA/SWPC",
        ParagraphStyle('Footer', parent=styles['Normal'], fontSize=8, textColor=gray, alignment=TA_CENTER)
    ))
    
    # Build PDF
    doc.build(story)
    return output_file


def main():
    """Main entry point."""
    # Parse arguments
    logs_dir = sys.argv[1] if len(sys.argv) > 1 else LOGS_DIR
    output_file = sys.argv[2] if len(sys.argv) > 2 else OUTPUT_FILE
    
    print(f"BPQ Propagation Report Generator")
    print(f"=" * 40)
    print(f"Logs directory: {logs_dir}")
    print(f"Output file: {output_file}")
    print()
    
    # Check logs directory
    if not os.path.exists(logs_dir):
        print(f"Error: Logs directory '{logs_dir}' not found")
        sys.exit(1)
    
    # Parse logs
    print("Parsing VARA HF logs...")
    connections = parse_vara_logs(logs_dir)
    print(f"  Found {len(connections)} connections in last 7 days")
    
    if not connections:
        print("Error: No connection data found. Check log file paths.")
        sys.exit(1)
    
    # Analyze
    print("Analyzing connection data...")
    stats = analyze_connections(connections)
    print(f"  Successful: {stats['successful']}, Failed: {stats['failed']}")
    print(f"  Unique stations: {len(stats['by_station'])}")
    print(f"  Bands used: {list(stats['by_band'].keys())}")
    
    # Generate recommendations
    print("Generating recommendations...")
    recommendations = generate_recommendations(stats)
    
    # Create PDF
    print(f"Creating PDF report: {output_file}")
    create_pdf_report(stats, recommendations, output_file)
    
    print()
    print(f"✅ Report generated successfully: {output_file}")
    
    return output_file


if __name__ == '__main__':
    main()
