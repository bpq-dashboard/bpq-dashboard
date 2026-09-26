#!/usr/bin/env python3
"""
generate-user-manual-pdf.py
============================

Converts USER-MANUAL.md to a polished PDF using ReportLab. The output
matches the style of previous releases (v1.5.6 manual) — letter-size,
clean sans-serif body, monospace code blocks, header/footer on each
page with version and page number.

Why this script exists rather than e.g. pandoc:
  - Pure-Python, no system deps beyond `pip install reportlab markdown`
  - We control the styling explicitly so it matches our visual brand
  - Future updates just edit USER-MANUAL.md and re-run this script

Usage:
  python3 generate-user-manual-pdf.py
  python3 generate-user-manual-pdf.py --input some.md --output some.pdf

The PDF version string in headers/footers comes from the first
"Version X.Y.Z" line in the markdown, so bumping the version in the
manual updates the headers automatically.
"""

import sys
import re
import argparse
from pathlib import Path

try:
    from reportlab.lib.pagesizes import letter
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.lib.units import inch
    from reportlab.lib import colors
    from reportlab.lib.enums import TA_LEFT, TA_CENTER
    from reportlab.platypus import (
        SimpleDocTemplate, Paragraph, Spacer, PageBreak, Table, TableStyle,
        KeepTogether, Preformatted
    )
    from reportlab.pdfgen import canvas
except ImportError:
    sys.stderr.write("Missing dependency. Install with:\n  pip install reportlab\n")
    sys.exit(1)


# ───────────────────────────────────────────────────────────────────────────
# Markdown → ReportLab conversion
# ───────────────────────────────────────────────────────────────────────────
#
# We don't use a full Markdown library because we want predictable styling
# control. Instead we parse the subset of Markdown features actually used
# in USER-MANUAL.md and emit ReportLab flowables for each.
#
# Supported:
#   # H1, ## H2, ### H3
#   **bold**, *italic*, `code`
#   - bullet list, 1. numbered list
#   | table | with | columns |
#   ```code blocks```
#   > blockquotes
#   --- horizontal rule (used as page break)
#   [link](url)
#
# Not supported (intentionally — not used in this manual):
#   nested lists, images, footnotes, HTML embeds
# ───────────────────────────────────────────────────────────────────────────


def inline_format(text):
    """Convert Markdown inline formatting to ReportLab XML markup.

    Strategy: pull code spans out first into placeholder tokens so that
    bold/italic patterns can't match inside them. After all other inline
    conversions, swap the placeholders back to their rendered form. This
    avoids a bug where an asterisk inside `status *` got picked up by
    the italic regex looking outside it.
    """
    # Replace common emojis with bracketed text equivalents — ReportLab's
    # built-in fonts don't include emoji glyphs, so emoji characters render
    # as black boxes. We swap them for readable text that conveys the
    # same meaning. The source markdown keeps the emojis (GitHub renders
    # them fine); this substitution happens only at PDF generation time.
    emoji_subs = {
        '👤': '[Visitors]',
        '📡': '[Sysops]',
        '🔧': '[Admins]',
        '🌙': '(moon)',
        '☀️': '(sun)',
        '🟢': '[OK]',
        '🟡': '[Warn]',
        '🔴': '[Crit]',
        '✅': '[OK]',
        '❌': '[FAIL]',
        '⚠️': '[!]',
        '⚠': '[!]',
        '✓': '[OK]',
        '✗': '[X]',
        '📂': '[Folder]',
        '📁': '[YAPP]',
        '🛰': '[Antenna]',
        '🛰️': '[Antenna]',
        '🚗': '(car)',
        '🏠': '(home)',
        '🌦️': '(wx)',
        '🌦': '(wx)',
        '🌩️': '(storm)',
        '🌩': '(storm)',
        '✉️': '(mail)',
        '🕐': '(clock)',
        '⚙': '(gear)',
        '⚙️': '(gear)',
        '🔄': '[Refresh]',
        '×': 'x',
        '—': '—',  # em-dash, already supported
        '–': '–',  # en-dash, already supported
        '•': '•',  # bullet, already supported
        '·': '·',  # middle dot, already supported
    }
    for emoji, replacement in emoji_subs.items():
        text = text.replace(emoji, replacement)
    # Strip variation selectors (U+FE0F is appended to many emojis to make
    # them render in emoji style). After replacing the base emoji glyph,
    # the variation selector remains and shows as a box. Remove any
    # stragglers.
    text = re.sub(r'[\uFE0E\uFE0F\u200D]', '', text)
    # Catch-all: replace any remaining characters outside the printable
    # ASCII + common punctuation range with a question mark. This prevents
    # box-glyphs from emojis we forgot to include in the substitution dict.
    # We allow common typography (em-dash, en-dash, curly quotes, etc.)
    # but strip pictographic characters.
    def _safe_char(ch):
        cp = ord(ch)
        # ASCII printable and whitespace
        if cp < 0x80:
            return ch
        # Latin-1 supplement and Latin extended
        if cp < 0x2000:
            return ch
        # General punctuation (em-dash, en-dash, smart quotes, ellipsis, etc.)
        if 0x2000 <= cp <= 0x206F:
            return ch
        # Letterlike symbols
        if 0x2100 <= cp <= 0x214F:
            return ch
        # Arrows — many ARE in basic Helvetica/Times fonts
        if 0x2190 <= cp <= 0x21FF:
            return ch
        # Mathematical operators
        if 0x2200 <= cp <= 0x22FF:
            return ch
        # Box drawing
        if 0x2500 <= cp <= 0x257F:
            return ch
        # Geometric shapes (includes ■ which we DO want to allow as
        # legitimate text-art character)
        if 0x25A0 <= cp <= 0x25FF:
            return ch
        # Anything else (most emoji ranges, dingbats with emoji variants,
        # etc.) gets stripped to avoid box-glyphs.
        if cp >= 0x2600:
            return ''
        return ch
    text = ''.join(_safe_char(c) for c in text)

    # First escape & < > for ReportLab's mini-XML parser. ReportLab uses
    # XML-like markup so any literal < or > in user text must be escaped.
    text = text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

    # Pull code spans into placeholders. Use a marker unlikely to appear
    # in source text. Order: collect, replace with token, restore later.
    code_spans = []
    def _code_capture(m):
        code_spans.append(m.group(1))
        return f'\x01CODE{len(code_spans)-1}\x01'
    text = re.sub(r'`([^`]+)`', _code_capture, text)

    # Links — render the link text in blue
    text = re.sub(
        r'\[([^\]]+)\]\(([^)]+)\)',
        lambda m: f'<link href="{m.group(2)}" color="#1d4ed8"><u>{m.group(1)}</u></link>',
        text
    )

    # Bold
    text = re.sub(r'\*\*([^*]+)\*\*', r'<b>\1</b>', text)

    # Italic — single asterisks (do AFTER bold so ** doesn't get caught)
    text = re.sub(r'(?<!\*)\*([^*]+)\*(?!\*)', r'<i>\1</i>', text)

    # Restore code spans now that bold/italic are done
    def _code_restore(m):
        idx = int(m.group(1))
        return f'<font name="Courier" color="#5b21b6">{code_spans[idx]}</font>'
    text = re.sub(r'\x01CODE(\d+)\x01', _code_restore, text)

    return text


def parse_table(lines, idx):
    """Parse a Markdown pipe-table starting at lines[idx].

    Returns (Table flowable, next_idx). Skips the separator row (---).
    """
    # Header row
    header_line = lines[idx]
    headers = [c.strip() for c in header_line.strip('|').split('|')]
    # Skip separator
    next_idx = idx + 2
    rows = [headers]
    while next_idx < len(lines) and lines[next_idx].strip().startswith('|'):
        row = [c.strip() for c in lines[next_idx].strip('|').split('|')]
        rows.append(row)
        next_idx += 1

    # Build flowable — wrap each cell in a Paragraph so inline formatting works
    cell_style = ParagraphStyle(
        'TableCell',
        fontName='Helvetica',
        fontSize=9,
        leading=11,
        textColor=colors.HexColor('#1f2937'),
    )
    header_style = ParagraphStyle(
        'TableHeader',
        fontName='Helvetica-Bold',
        fontSize=9,
        leading=11,
        textColor=colors.white,
    )

    data = []
    for i, row in enumerate(rows):
        style = header_style if i == 0 else cell_style
        data.append([Paragraph(inline_format(c), style) for c in row])

    table = Table(data, repeatRows=1, hAlign='LEFT')
    table.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#7c3aed')),
        ('GRID', (0, 0), (-1, -1), 0.5, colors.HexColor('#cbd5e1')),
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('LEFTPADDING', (0, 0), (-1, -1), 6),
        ('RIGHTPADDING', (0, 0), (-1, -1), 6),
        ('TOPPADDING', (0, 0), (-1, -1), 4),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 4),
        ('ROWBACKGROUNDS', (0, 1), (-1, -1),
            [colors.white, colors.HexColor('#f8fafc')]),
    ]))
    return table, next_idx


def build_image_flowable(path, alt, styles):
    """Build flowables to embed an image at the given path.

    Returns a list of flowables: typically [Image, caption-Paragraph]
    or just [Image]. Returns None if the file can't be loaded.

    SVG handling: rasterizes to PNG at high resolution (DPI ~150) using
    cairosvg, then embeds the PNG. Vector quality is preserved as much
    as PDF can show, and ReportLab handles PNG natively without needing
    SVG support compiled in.

    Path resolution: tried relative to the markdown file's directory
    first, then relative to CWD.
    """
    from reportlab.platypus import Image as RLImage
    import os
    import tempfile

    # Resolve path — try relative to CWD; markdown image references
    # are typically relative to the .md file's directory which is
    # usually the CWD when generating.
    if not os.path.exists(path):
        # Try resolving relative to current working directory
        candidate = os.path.join(os.getcwd(), path)
        if os.path.exists(candidate):
            path = candidate
        else:
            sys.stderr.write(f"WARN: image not found: {path}\n")
            return None

    # Determine if SVG
    is_svg = path.lower().endswith('.svg')
    image_path = path
    tmp_to_clean = None

    if is_svg:
        try:
            import cairosvg
        except ImportError:
            sys.stderr.write(
                f"WARN: SVG support requires cairosvg. "
                f"Install: pip install cairosvg --break-system-packages\n"
                f"Skipping image: {path}\n"
            )
            return None
        # Rasterize SVG to a temporary PNG. Use 1600px width — gives
        # roughly 200 DPI for a 6.5" wide page, plenty of detail for
        # zoom inspection without bloating the PDF.
        tmp_to_clean = tempfile.NamedTemporaryFile(
            suffix='.png', delete=False
        )
        tmp_to_clean.close()
        cairosvg.svg2png(
            url=path,
            write_to=tmp_to_clean.name,
            output_width=1600,
        )
        image_path = tmp_to_clean.name

    # Build the Image flowable. Width = page text width (6.5"). Height
    # auto-scales to preserve aspect ratio.
    page_text_width = 7.0 * inch  # 8.5" page - 2 * 0.75" margins = 7.0"
    try:
        img = RLImage(image_path, width=page_text_width, height=None)
        # Scale height to maintain aspect ratio
        if img.imageWidth and img.imageHeight:
            scale = page_text_width / img.imageWidth
            img.drawWidth = page_text_width
            img.drawHeight = img.imageHeight * scale
        else:
            img.drawWidth = page_text_width
    except Exception as e:
        sys.stderr.write(f"WARN: failed to load image {path}: {e}\n")
        if tmp_to_clean:
            try:
                os.unlink(tmp_to_clean.name)
            except Exception:
                pass
        return None

    flowables = [Spacer(1, 8), img]

    # Add caption if alt text provided
    if alt and alt.strip():
        caption_style = ParagraphStyle(
            'Caption',
            fontName='Helvetica-Oblique',
            fontSize=9,
            leading=11,
            alignment=TA_CENTER,
            textColor=colors.HexColor('#6b7280'),
            spaceBefore=4,
            spaceAfter=10,
        )
        flowables.append(Paragraph(inline_format(alt), caption_style))
    else:
        flowables.append(Spacer(1, 8))

    return flowables


def parse_code_block(lines, idx):
    """Parse a ``` fenced code block starting at lines[idx].

    Returns (Preformatted flowable, next_idx).
    """
    # Strip the opening fence (and optional language tag)
    start = idx + 1
    end = start
    while end < len(lines) and not lines[end].strip().startswith('```'):
        end += 1
    code_text = '\n'.join(lines[start:end])
    # Substitute emojis in code blocks too — they appear in ASCII art diagrams
    # and would otherwise render as black boxes
    code_text = _emoji_subs_for_code(code_text)
    code_style = ParagraphStyle(
        'Code',
        fontName='Courier',
        fontSize=8.5,
        leading=11,
        leftIndent=14,
        rightIndent=14,
        textColor=colors.HexColor('#1e293b'),
        backColor=colors.HexColor('#f1f5f9'),
        borderColor=colors.HexColor('#cbd5e1'),
        borderWidth=0.5,
        borderPadding=8,
        spaceBefore=6,
        spaceAfter=6,
    )
    return Preformatted(code_text, code_style), end + 1


def _emoji_subs_for_code(text):
    """Replace emojis in code blocks with safe ASCII equivalents."""
    subs = {
        '👤': '(V)', '📡': '(S)', '🔧': '(A)',
        '🌙': 'D', '☀️': 'L', '🕐': '(t)',
        '✉️': '(@)', '⚙': '(*)', '⚙️': '(*)',
        '📂': '[D]', '📁': '[F]', '🏠': '(H)',
        '🛰': '<>', '🛰️': '<>',
        '🚗': '(c)', '🌦️': '(W)', '🌦': '(W)',
        '🌩️': '(!)', '🌩': '(!)',
        '🔄': 'R', '↗': '->',
        '✓': 'OK', '✗': 'X',
        '↑': '^', '↓': 'v', '←': '<', '→': '>',
        '×': 'x',
    }
    for k, v in subs.items():
        text = text.replace(k, v)
    # Strip variation selectors and zero-width joiners
    text = re.sub(r'[\uFE0E\uFE0F\u200D]', '', text)
    # Strip remaining emoji-range characters (anything U+2600 and up that
    # isn't a recognized box-drawing or geometric shape)
    def _safe(ch):
        cp = ord(ch)
        if cp < 0x2600:
            return ch
        if 0x2500 <= cp <= 0x25FF:  # box drawing + geometric shapes
            return ch
        return ''
    text = ''.join(_safe(c) for c in text)
    return text


# ───────────────────────────────────────────────────────────────────────────
# Styles
# ───────────────────────────────────────────────────────────────────────────


def build_styles():
    """Return a dict of named ParagraphStyle objects for the manual."""
    base = getSampleStyleSheet()
    return {
        'Title': ParagraphStyle(
            'ManualTitle', parent=base['Title'],
            fontName='Helvetica-Bold', fontSize=28, leading=34,
            alignment=TA_CENTER, textColor=colors.HexColor('#7c3aed'),
            spaceAfter=4,
        ),
        'Subtitle': ParagraphStyle(
            'ManualSubtitle', parent=base['Normal'],
            fontName='Helvetica', fontSize=14, leading=18,
            alignment=TA_CENTER, textColor=colors.HexColor('#475569'),
            spaceAfter=2,
        ),
        'H1': ParagraphStyle(
            'H1', parent=base['Heading1'],
            fontName='Helvetica-Bold', fontSize=18, leading=22,
            textColor=colors.HexColor('#1e293b'),
            spaceBefore=16, spaceAfter=8,
            keepWithNext=True,
        ),
        'H2': ParagraphStyle(
            'H2', parent=base['Heading2'],
            fontName='Helvetica-Bold', fontSize=14, leading=18,
            textColor=colors.HexColor('#7c3aed'),
            spaceBefore=20, spaceAfter=8,
            keepWithNext=True,
        ),
        'H3': ParagraphStyle(
            'H3', parent=base['Heading3'],
            fontName='Helvetica-Bold', fontSize=11, leading=14,
            textColor=colors.HexColor('#475569'),
            spaceBefore=10, spaceAfter=6,
            keepWithNext=True,
        ),
        'Body': ParagraphStyle(
            'Body', parent=base['Normal'],
            fontName='Helvetica', fontSize=10, leading=14,
            textColor=colors.HexColor('#1f2937'),
            spaceAfter=6,
        ),
        'Bullet': ParagraphStyle(
            'Bullet', parent=base['Normal'],
            fontName='Helvetica', fontSize=10, leading=14,
            leftIndent=18, bulletIndent=6,
            textColor=colors.HexColor('#1f2937'),
            spaceAfter=2,
        ),
        'BlockQuote': ParagraphStyle(
            'BlockQuote', parent=base['Normal'],
            fontName='Helvetica-Oblique', fontSize=10, leading=14,
            leftIndent=14, rightIndent=14,
            textColor=colors.HexColor('#7c3aed'),
            backColor=colors.HexColor('#faf5ff'),
            borderColor=colors.HexColor('#7c3aed'),
            borderWidth=0,
            borderPadding=10,
            spaceBefore=12, spaceAfter=10,
        ),
        'TocItem': ParagraphStyle(
            'TocItem', parent=base['Normal'],
            fontName='Helvetica', fontSize=10, leading=14,
            leftIndent=18,
            textColor=colors.HexColor('#1f2937'),
        ),
    }


# ───────────────────────────────────────────────────────────────────────────
# Markdown parser → flowables
# ───────────────────────────────────────────────────────────────────────────


def parse_markdown(md_text, styles):
    """Convert the markdown text into a list of ReportLab flowables."""
    flowables = []
    lines = md_text.split('\n')
    i = 0
    n = len(lines)

    # Skip the title block — we render it specially up top
    title_emitted = False

    while i < n:
        line = lines[i].rstrip()
        stripped = line.strip()

        # Blank line — emits a small spacer
        if not stripped:
            i += 1
            continue

        # Horizontal rule → visual separator, NOT a page break.
        # Page breaks come from H1 headings (handled below). Treating ---
        # as page breaks creates many sparse pages because the markdown
        # uses --- liberally between subsections.
        if stripped == '---':
            flowables.append(Spacer(1, 8))
            i += 1
            continue

        # Image (markdown syntax: ![alt](path))
        # Supports both raster (PNG/JPG) and vector (SVG). SVGs are
        # rasterized to PNG at print resolution using cairosvg, then
        # embedded as an Image flowable scaled to page width.
        m = re.match(r'^\s*!\[([^\]]*)\]\(([^)]+)\)\s*$', line)
        if m:
            alt = m.group(1)
            path = m.group(2)
            img_flow = build_image_flowable(path, alt, styles)
            if img_flow:
                flowables.extend(img_flow)
            i += 1
            continue

        # Code block
        if stripped.startswith('```'):
            code_flow, i = parse_code_block(lines, i)
            flowables.append(code_flow)
            continue

        # Table (look for line starting with | and a separator below it)
        if (stripped.startswith('|')
                and i + 1 < n
                and lines[i + 1].strip().startswith('|')
                and '---' in lines[i + 1]):
            table_flow, i = parse_table(lines, i)
            flowables.append(table_flow)
            flowables.append(Spacer(1, 6))
            continue

        # Heading
        m = re.match(r'^(#{1,6})\s+(.+)$', stripped)
        if m:
            # #### and deeper render like ### (was: not matched at all, which
            # left the line unconsumed and looped forever — 2026-09-26)
            level = min(len(m.group(1)), 3)
            text = inline_format(m.group(2))
            style = {1: styles['H1'], 2: styles['H2'], 3: styles['H3']}[level]
            # Let content flow naturally; H2 styling provides enough visual
            # separation. Forced page breaks at each section created many
            # sparse half-empty pages where a section's intro spilled
            # by a few lines.
            flowables.append(Paragraph(text, style))
            title_emitted = True
            i += 1
            continue

        # Blockquote
        if stripped.startswith('> '):
            # Collect contiguous blockquote lines
            block_lines = []
            while i < n and lines[i].strip().startswith('> '):
                block_lines.append(lines[i].strip()[2:])
                i += 1
            block_text = inline_format(' '.join(block_lines))
            flowables.append(Paragraph(block_text, styles['BlockQuote']))
            continue

        # Bullet list
        if re.match(r'^\s*[-*]\s+', line):
            list_items = []
            while i < n and re.match(r'^\s*[-*]\s+', lines[i]):
                item_text = re.sub(r'^\s*[-*]\s+', '', lines[i])
                list_items.append(item_text)
                i += 1
            for item in list_items:
                flowables.append(Paragraph(
                    f'<bullet>•</bullet> {inline_format(item)}',
                    styles['Bullet']))
            continue

        # Numbered list
        if re.match(r'^\s*\d+\.\s+', line):
            list_items = []
            while i < n and re.match(r'^\s*\d+\.\s+', lines[i]):
                m = re.match(r'^\s*(\d+)\.\s+(.*)$', lines[i])
                list_items.append((m.group(1), m.group(2)))
                i += 1
            for num, item in list_items:
                flowables.append(Paragraph(
                    f'<bullet>{num}.</bullet> {inline_format(item)}',
                    styles['Bullet']))
            continue

        # Regular paragraph — collect contiguous non-special lines
        para_lines = []
        while i < n:
            ln = lines[i].rstrip()
            ls = ln.strip()
            # Stop conditions
            if (not ls
                    or ls == '---'
                    or ls.startswith('#')
                    or ls.startswith('```')
                    or ls.startswith('|')
                    or ls.startswith('> ')
                    or re.match(r'^\s*[-*]\s+', ln)
                    or re.match(r'^\s*\d+\.\s+', ln)):
                break
            para_lines.append(ls)
            i += 1
        if para_lines:
            text = inline_format(' '.join(para_lines))
            flowables.append(Paragraph(text, styles['Body']))
        elif i < n and lines[i].strip() and not any((
                lines[i].strip() == '---', lines[i].strip().startswith(('```', '|', '> ', '#')),
                re.match(r'^\s*[-*]\s+', lines[i]), re.match(r'^\s*\d+\.\s+', lines[i]))):
            i += 1   # safety: never spin on a line nothing above consumed

    return flowables


# ───────────────────────────────────────────────────────────────────────────
# Page header/footer
# ───────────────────────────────────────────────────────────────────────────


def make_header_footer(version, subtitle='User Manual & Installation Guide'):
    """Return a closure suitable for SimpleDocTemplate.build's onLaterPages."""
    def draw(canvas_obj, doc):
        canvas_obj.saveState()
        # Header — version left, subtitle right
        canvas_obj.setFont('Helvetica-Bold', 9)
        canvas_obj.setFillColor(colors.HexColor('#7c3aed'))
        canvas_obj.drawString(0.75 * inch, 10.5 * inch,
                              'BPQ Dashboard ' + version)
        canvas_obj.setFont('Helvetica', 9)
        canvas_obj.setFillColor(colors.HexColor('#64748b'))
        canvas_obj.drawRightString(7.75 * inch, 10.5 * inch, subtitle)
        # Thin divider line
        canvas_obj.setStrokeColor(colors.HexColor('#e2e8f0'))
        canvas_obj.setLineWidth(0.5)
        canvas_obj.line(0.75 * inch, 10.4 * inch, 7.75 * inch, 10.4 * inch)

        # Footer — page number centered
        canvas_obj.setFont('Helvetica', 9)
        canvas_obj.setFillColor(colors.HexColor('#94a3b8'))
        page_num = canvas_obj.getPageNumber()
        canvas_obj.drawCentredString(4.25 * inch, 0.5 * inch,
                                     f'Page {page_num}')
        canvas_obj.restoreState()
    return draw


def draw_first_page(canvas_obj, doc):
    """First page: no header/footer (the cover handles its own design)."""
    pass


# ───────────────────────────────────────────────────────────────────────────
# Main
# ───────────────────────────────────────────────────────────────────────────


def extract_cover_metadata(md_text):
    """Find the metadata table at the top of the markdown.

    Looks for the first pipe-table that appears before the Table of
    Contents. Returns a list of [key, value] pairs.

    The expected format is a two-column markdown table:

        | | |
        |---|---|
        | Platform | Linux — Debian ... |
        | Requirements | ... |

    We accept either an empty header row or a normal header row.
    """
    lines = md_text.split('\n')
    rows = []
    in_table = False
    for line in lines:
        ls = line.strip()
        # Stop scanning once we reach the TOC heading
        if ls.startswith('## ') and 'Table of Contents' in ls:
            break
        if ls.startswith('|') and ls.endswith('|'):
            cells = [c.strip() for c in ls.strip('|').split('|')]
            # Skip the separator row (---|---)
            if all(re.match(r'^[-:]+$', c.strip()) for c in cells):
                in_table = True
                continue
            # Skip empty header row
            if all(not c for c in cells):
                continue
            # Capture rows once we've seen the separator
            if in_table and len(cells) == 2 and cells[0]:
                rows.append(cells)
        elif in_table:
            # Table ended (a blank line or non-pipe line)
            break
    return rows


def extract_version(md_text):
    """Find the first 'Version X.Y.Z' line in the markdown."""
    m = re.search(r'\*\*Version\s+(\d+(?:\.\d+)+)\*\*', md_text)
    return m.group(1) if m else 'X.Y.Z'


def extract_doc_kind(md_text):
    """Detect whether the source is the User Manual or the User Guide.

    Looks for an italicized subtitle line like "**User Manual**" or
    "**User Guide**" near the top. Defaults to "User Manual".
    """
    # Check the first 30 lines for a recognizable subtitle
    head = '\n'.join(md_text.split('\n')[:30])
    if re.search(r'\*\*User Guide\*\*', head, re.IGNORECASE):
        return 'User Guide'
    return 'User Manual'


def main():
    p = argparse.ArgumentParser(description='Generate User Manual PDF from Markdown.')
    p.add_argument('--input', '-i', default='USER-MANUAL.md',
                   help='Markdown source (default: USER-MANUAL.md)')
    p.add_argument('--output', '-o', default=None,
                   help='Output PDF path (default: BPQ-Dashboard-vX.Y.Z-Manual.pdf)')
    args = p.parse_args()

    md_path = Path(args.input)
    if not md_path.exists():
        sys.stderr.write(f"Not found: {md_path}\n")
        sys.exit(1)

    md_text = md_path.read_text(encoding='utf-8')
    version = extract_version(md_text)
    doc_kind = extract_doc_kind(md_text)
    # Compact slug for filename: "User Manual" → "Manual", "User Guide" → "User-Guide"
    if doc_kind == 'User Guide':
        filename_slug = 'User-Guide'
        subtitle_for_header = 'User Guide'
    else:
        filename_slug = 'Manual'
        subtitle_for_header = 'User Manual & Installation Guide'

    output = args.output or f'BPQ-Dashboard-v{version}-{filename_slug}.pdf'

    styles = build_styles()

    # Cover page — title and metadata before the regular content
    cover = [
        Spacer(1, 1.5 * inch),
        Paragraph('BPQ Dashboard', styles['Title']),
        Spacer(1, 0.15 * inch),
        Paragraph(f'Version {version}', styles['Subtitle']),
        Paragraph(doc_kind, styles['Subtitle']),
        Paragraph('Amateur Radio Packet Node Operations Suite',
                  styles['Subtitle']),
        Spacer(1, 0.4 * inch),
    ]

    # Cover metadata table — pulled from the markdown's own metadata table
    # at the top of the document, so each doc's cover reflects its source.
    parsed_meta = extract_cover_metadata(md_text)
    if not parsed_meta:
        # Fallback if parsing fails
        parsed_meta = [
            ['Platform', 'Linux — Debian / Ubuntu / Raspberry Pi'],
            ['Requirements', 'LinBPQ running · nginx or Apache · PHP 8.x'],
            ['Target audience', 'Amateur radio operators'],
            ['Updated', 'August 2026'],
            ['Repository', 'https://github.com/bpq-dashboard/bpq-dashboard'],
        ]
    cover_meta = parsed_meta
    meta_style = ParagraphStyle(
        'Meta', fontName='Helvetica', fontSize=10, leading=14,
        textColor=colors.HexColor('#1f2937'),
    )
    meta_label = ParagraphStyle(
        'MetaLabel', fontName='Helvetica-Bold', fontSize=10, leading=14,
        textColor=colors.HexColor('#7c3aed'),
    )
    cover_table_data = [
        [Paragraph(label, meta_label), Paragraph(value, meta_style)]
        for label, value in cover_meta
    ]
    cover_table = Table(cover_table_data, colWidths=[1.5 * inch, 5 * inch])
    cover_table.setStyle(TableStyle([
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('LEFTPADDING', (0, 0), (-1, -1), 8),
        ('RIGHTPADDING', (0, 0), (-1, -1), 8),
        ('TOPPADDING', (0, 0), (-1, -1), 6),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 6),
        ('ROWBACKGROUNDS', (0, 0), (-1, -1),
            [colors.white, colors.HexColor('#f8fafc')]),
        ('BOX', (0, 0), (-1, -1), 0.5, colors.HexColor('#e2e8f0')),
    ]))
    cover.append(cover_table)
    cover.append(Spacer(1, 1.5 * inch))
    # Footer of cover
    cover.append(Paragraph(
        '<font color="#94a3b8">'
        f'BPQ Dashboard v{version} — Open-source, free for amateur radio use'
        '</font>',
        ParagraphStyle('CoverFooter', alignment=TA_CENTER, fontSize=9)))
    cover.append(PageBreak())

    # Body content
    body_flowables = parse_markdown(md_text, styles)

    # Skip the markdown's own title block (first H1 and immediate metadata),
    # since we've handled that on the cover page.
    body_content = []
    skip_to_toc = False
    for flow in body_flowables:
        # Heuristic: skip everything until we hit the "Table of Contents" H2
        # or first numbered H1
        if not skip_to_toc:
            if isinstance(flow, Paragraph):
                text = str(flow.text) if hasattr(flow, 'text') else ''
                if 'Table of Contents' in text or text.startswith('1. What Is'):
                    skip_to_toc = True
                    body_content.append(flow)
                    continue
            continue
        body_content.append(flow)

    # If our heuristic failed, just include everything
    if not body_content:
        body_content = body_flowables

    doc = SimpleDocTemplate(
        output,
        pagesize=letter,
        leftMargin=0.75 * inch,
        rightMargin=0.75 * inch,
        topMargin=0.85 * inch,
        bottomMargin=0.75 * inch,
        title=f'BPQ Dashboard v{version} {doc_kind}',
        subject=f'{doc_kind} for Amateur Radio Operators',
        author='BPQ Dashboard Project',
    )

    doc.build(
        cover + body_content,
        onFirstPage=draw_first_page,
        onLaterPages=make_header_footer(version, subtitle_for_header),
    )

    print(f'Generated: {output}')
    print(f'  Pages:    (run pdfinfo to verify)')
    print(f'  Source:   {md_path}')
    print(f'  Version:  {version}')


if __name__ == '__main__':
    main()
