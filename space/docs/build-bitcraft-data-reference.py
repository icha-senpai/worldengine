"""Build the readable PDF from the editable Markdown reference.

Dependencies: reportlab. Run from the Space root with:
python docs/build-bitcraft-data-reference.py
"""

from pathlib import Path
import html
import re
import sys

ROOT = Path(__file__).resolve().parent.parent
local_tools = ROOT / ".runtime" / "doc-tools"
if local_tools.exists():
    sys.path.insert(0, str(local_tools))

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, Frame, KeepTogether, PageBreak, PageTemplate, Paragraph,
    Preformatted, Spacer, Table, TableStyle,
)

SOURCE = ROOT / "docs" / "bitcraft-data-reference.md"
OUTPUT = ROOT / "output" / "pdf" / "BitCraft_Data_Sources_and_Subscriptions.pdf"
OUTPUT.parent.mkdir(parents=True, exist_ok=True)

font_regular = Path("C:/Windows/Fonts/arial.ttf")
font_bold = Path("C:/Windows/Fonts/arialbd.ttf")
font_italic = Path("C:/Windows/Fonts/ariali.ttf")
if font_regular.exists():
    for name, path in [("Reference", font_regular), ("ReferenceBold", font_bold), ("ReferenceItalic", font_italic)]:
        pdfmetrics.registerFont(TTFont(name, str(path)))
    pdfmetrics.registerFontFamily("Reference", normal="Reference", bold="ReferenceBold", italic="ReferenceItalic", boldItalic="ReferenceBold")
    regular, bold = "Reference", "ReferenceBold"
else:
    regular, bold = "Helvetica", "Helvetica-Bold"

INK = colors.HexColor("#17212d")
BLUE = colors.HexColor("#203f57")
PALE = colors.HexColor("#f2f6f8")
GRAY = colors.HexColor("#54616e")
styles = getSampleStyleSheet()
styles.add(ParagraphStyle("ReferenceBody", fontName=regular, fontSize=10.3, leading=14.2, textColor=INK, spaceAfter=8))
styles.add(ParagraphStyle("ReferenceTitle", fontName=bold, fontSize=24, leading=29, textColor=colors.black, spaceAfter=10))
styles.add(ParagraphStyle("ReferenceHeading", fontName=bold, fontSize=18, leading=23, textColor=colors.black, spaceAfter=12, keepWithNext=True))
styles.add(ParagraphStyle("ReferenceCard", fontName=bold, fontSize=13, leading=18, textColor=colors.black, spaceBefore=12, spaceAfter=8, keepWithNext=True))
styles.add(ParagraphStyle("ReferenceTable", fontName=regular, fontSize=9, leading=12.1, textColor=INK, spaceAfter=0))
styles.add(ParagraphStyle("ReferenceTableHead", parent=styles["ReferenceTable"], fontName=bold, textColor=colors.white))
styles.add(ParagraphStyle("ReferenceCode", fontName="Courier", fontSize=8.3, leading=11.6, textColor=BLUE, spaceBefore=3, spaceAfter=10))
styles.add(ParagraphStyle("ReferenceBullet", parent=styles["ReferenceBody"], leftIndent=12, firstLineIndent=-10))

def slug(text):
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")

def rich(text):
    text = html.escape(text, quote=False)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", lambda m: f'<link href="{html.escape(html.unescape(m[2]), quote=True)}" color="#245d83">{m[1]}</link>', text)
    text = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", text)
    text = re.sub(r"`([^`]+)`", r'<font name="Courier" size="9">\1</font>', text)
    return text

def heading(text, style, level):
    paragraph = Paragraph(rich(text), style)
    paragraph.reference_title = text
    paragraph.reference_anchor = slug(text)
    paragraph.reference_level = level
    return paragraph

class ReferencePDF(BaseDocTemplate):
    def afterFlowable(self, flowable):
        if hasattr(flowable, "reference_anchor"):
            self.canv.bookmarkPage(flowable.reference_anchor)
            self.canv.addOutlineEntry(flowable.reference_title, flowable.reference_anchor, flowable.reference_level, closed=False)

def footer(canvas, doc):
    canvas.saveState()
    canvas.setStrokeColor(colors.HexColor("#d5dde3"))
    canvas.line(48, 39, A4[0] - 48, 39)
    canvas.setFont(regular, 8)
    canvas.setFillColor(GRAY)
    canvas.drawString(48, 25, "SPACE BITCRAFT TOOLS  |  REFERENCE  |  7 OCT 2026")
    canvas.drawRightString(A4[0] - 48, 25, str(doc.page))
    canvas.restoreState()

doc = ReferencePDF(str(OUTPUT), pagesize=A4, leftMargin=48, rightMargin=48,
                   topMargin=44, bottomMargin=53, title="BitCraft data sources and subscription reference",
                   author="Space BitCraft tools", allowSplitting=True)
frame = Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height, leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
doc.addPageTemplates(PageTemplate(id="reference", frames=[frame], onPage=footer))

lines = SOURCE.read_text(encoding="utf-8").splitlines()
story = []
i = 0
while i < len(lines):
    line = lines[i].strip()
    if not line:
        i += 1
        continue
    if line.startswith("```"):
        block = []
        i += 1
        while i < len(lines) and not lines[i].startswith("```"):
            block.append(lines[i])
            i += 1
        story.append(Preformatted("\n".join(block), styles["ReferenceCode"]))
        i += 1
        continue
    if line.startswith("# "):
        story.append(heading(line[2:], styles["ReferenceTitle"], 0))
    elif line.startswith("## "):
        title = line[3:]
        if title != "What we have today":
            story.append(PageBreak())
        story.append(heading(title, styles["ReferenceHeading"], 1))
    elif line.startswith("### "):
        story.append(heading(line[4:], styles["ReferenceCard"], 2))
    elif line.startswith("| "):
        rows = []
        while i < len(lines) and lines[i].strip().startswith("|"):
            row = [cell.strip() for cell in lines[i].strip().strip("|").split("|")]
            if not all(re.fullmatch(r"[:\- ]+", cell) for cell in row):
                rows.append(row)
            i += 1
        columns = len(rows[0])
        if columns == 4:
            widths = [44, 116, 150, doc.width - 310] if rows[0][2] == "Intended source and scope" else [44, 165, 66, doc.width - 275]
        elif rows[0][0] == "Area":
            widths = [85, 212, doc.width - 297]
        else:
            widths = [doc.width * .55, doc.width * .225, doc.width * .225]
        formatted = [[Paragraph(rich(cell), styles["ReferenceTableHead" if r == 0 else "ReferenceTable"]) for cell in row] for r, row in enumerate(rows)]
        table = Table(formatted, colWidths=widths, repeatRows=1, hAlign="LEFT")
        table.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), BLUE),
            ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, PALE]),
            ("GRID", (0, 0), (-1, -1), .4, colors.HexColor("#d5dde3")),
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("LEFTPADDING", (0, 0), (-1, -1), 8), ("RIGHTPADDING", (0, 0), (-1, -1), 8),
            ("TOPPADDING", (0, 0), (-1, -1), 7), ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
        ]))
        story.extend([table, Spacer(1, 10)])
        continue
    elif line.startswith("- "):
        story.append(Paragraph("- " + rich(line[2:]), styles["ReferenceBullet"]))
    elif re.match(r"^\d+\. ", line):
        story.append(Paragraph(rich(line), styles["ReferenceBullet"]))
    else:
        paragraph = [line]
        while i + 1 < len(lines) and lines[i + 1].strip() and not re.match(r"^(#|\||```|- |\d+\. )", lines[i + 1]):
            i += 1
            paragraph.append(lines[i].strip())
        story.append(Paragraph(rich(" ".join(paragraph)), styles["ReferenceBody"]))
    i += 1

doc.build(story)
print(OUTPUT)
