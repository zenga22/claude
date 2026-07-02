#!/usr/bin/env python3
"""Convert an .xlsx workbook to .ods, typing plain-text cells as dates,
currency, or numbers based on their content.

Many exported spreadsheets (e.g. from web tools or CSV round-trips) store
every cell as plain text, even though the data is clearly a date, a price,
or a count. This script inspects each column, figures out whether it holds
dates, currency amounts, or plain numbers, and writes a real ODS spreadsheet
where those cells carry proper value types and number/currency/date
formatting -- instead of everything being untyped text.

Only the Python standard library is used (zipfile + xml.etree), so no
extra packages (openpyxl, odfpy, ...) need to be installed.

Usage:
    python3 xlsx_to_ods.py input.xlsx [-o output.ods]
    python3 xlsx_to_ods.py input.xlsx --currency-columns Open,Close
    python3 xlsx_to_ods.py input.xlsx --date-columns A --number-columns F

Column overrides accept a comma-separated list of column letters (A, B, ...)
or header names (case-insensitive) and apply to every sheet.
"""

import argparse
import re
import sys
import zipfile
from datetime import datetime, timedelta
from xml.etree import ElementTree as ET
from xml.sax.saxutils import escape as xml_escape, quoteattr

NS_MAIN = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
NS_REL_DOC = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
NS_REL_PKG = "http://schemas.openxmlformats.org/package/2006/relationships"

CELL_REF_RE = re.compile(r"([A-Z]+)(\d+)")

BUILTIN_DATE_FMT_IDS = {
    14, 15, 16, 17, 18, 19, 20, 21, 22,
    27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
    45, 46, 47, 50, 57,
}

CURRENCY_SYMBOL_TO_CODE = {"$": "USD", "€": "EUR", "£": "GBP", "¥": "JPY", "₹": "INR"}
CODE_TO_SYMBOL = {v: k for k, v in CURRENCY_SYMBOL_TO_CODE.items()}
CURRENCY_LOCALE = {
    "USD": ("en", "US"), "EUR": ("de", "DE"), "GBP": ("en", "GB"),
    "JPY": ("ja", "JP"), "INR": ("en", "IN"),
}

CURRENCY_KEYWORDS = {
    "price", "cost", "amount", "total", "open", "close", "high", "low",
    "revenue", "salary", "fee", "value", "rate", "balance", "payment",
    "fare", "cash", "income", "expense", "budget", "charge", "wage",
}

DATE_FORMATS = [
    "%m-%d-%Y", "%m/%d/%Y", "%Y-%m-%d", "%Y/%m/%d",
    "%d-%b-%Y", "%d %B %Y", "%B %d, %Y", "%b %d, %Y",
    "%m-%d-%Y %H:%M:%S", "%m/%d/%Y %H:%M:%S",
    "%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S",
]

NUMBER_RE = re.compile(
    r"^\s*(?P<paren_open>\()?\s*(?P<neg>-)?\s*(?P<cur>[$€£¥₹])?\s*"
    r"(?P<num>\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)\s*"
    r"(?P<pct>%)?\s*(?P<paren_close>\))?\s*$"
)


def tag(ns, name):
    return f"{{{ns}}}{name}"


def col_letters_to_index(letters):
    idx = 0
    for ch in letters:
        idx = idx * 26 + (ord(ch) - ord("A") + 1)
    return idx - 1


def index_to_col_letters(idx):
    idx += 1
    letters = ""
    while idx > 0:
        idx, rem = divmod(idx - 1, 26)
        letters = chr(rem + ord("A")) + letters
    return letters


def excel_serial_to_datetime(serial):
    epoch = datetime(1899, 12, 30)
    days = int(serial)
    frac = serial - days
    dt = epoch + timedelta(days=days)
    if frac:
        dt += timedelta(seconds=round(frac * 86400))
    return dt


def format_code_is_date(code):
    if not code:
        return False
    stripped = re.sub(r'"[^"]*"', "", code)
    return bool(re.search(r"[dmyhs]", stripped, re.IGNORECASE))


# ---------------------------------------------------------------------------
# XLSX reading
# ---------------------------------------------------------------------------

def parse_styles(zf):
    if "xl/styles.xml" not in zf.namelist():
        return {}, []
    root = ET.fromstring(zf.read("xl/styles.xml"))
    numfmts = {}
    numfmts_el = root.find(tag(NS_MAIN, "numFmts"))
    if numfmts_el is not None:
        for nf in numfmts_el.findall(tag(NS_MAIN, "numFmt")):
            numfmts[int(nf.get("numFmtId"))] = nf.get("formatCode")
    cellxfs = []
    cellxfs_el = root.find(tag(NS_MAIN, "cellXfs"))
    if cellxfs_el is not None:
        for xf in cellxfs_el.findall(tag(NS_MAIN, "xf")):
            cellxfs.append(int(xf.get("numFmtId", "0")))
    return numfmts, cellxfs


def style_is_date(style_idx, numfmts, cellxfs):
    if style_idx is None or style_idx >= len(cellxfs):
        return False
    fmt_id = cellxfs[style_idx]
    if fmt_id in BUILTIN_DATE_FMT_IDS:
        return True
    return format_code_is_date(numfmts.get(fmt_id))


def parse_shared_strings(zf):
    if "xl/sharedStrings.xml" not in zf.namelist():
        return []
    root = ET.fromstring(zf.read("xl/sharedStrings.xml"))
    strings = []
    for si in root.findall(tag(NS_MAIN, "si")):
        parts = []
        t = si.find(tag(NS_MAIN, "t"))
        if t is not None:
            parts.append(t.text or "")
        else:
            for r in si.findall(tag(NS_MAIN, "r")):
                rt = r.find(tag(NS_MAIN, "t"))
                if rt is not None:
                    parts.append(rt.text or "")
        strings.append("".join(parts))
    return strings


def parse_workbook_sheets(zf):
    wb_root = ET.fromstring(zf.read("xl/workbook.xml"))
    rels_root = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))
    rid_to_target = {
        rel.get("Id"): rel.get("Target")
        for rel in rels_root.findall(tag(NS_REL_PKG, "Relationship"))
    }
    sheets = []
    sheets_el = wb_root.find(tag(NS_MAIN, "sheets"))
    for sheet_el in sheets_el.findall(tag(NS_MAIN, "sheet")):
        name = sheet_el.get("name")
        rid = sheet_el.get(tag(NS_REL_DOC, "id"))
        target = rid_to_target.get(rid)
        if target is None:
            continue
        full = target.lstrip("/") if target.startswith("/") else "xl/" + target
        sheets.append((name, full))
    return sheets


def parse_worksheet(zf, path, shared_strings, numfmts, cellxfs):
    root = ET.fromstring(zf.read(path))
    sheet_data = root.find(tag(NS_MAIN, "sheetData"))
    grid = {}
    max_row = -1
    max_col = -1
    if sheet_data is None:
        return grid, max_row, max_col
    for row_el in sheet_data.findall(tag(NS_MAIN, "row")):
        for c_el in row_el.findall(tag(NS_MAIN, "c")):
            ref = c_el.get("r")
            m = CELL_REF_RE.match(ref)
            if not m:
                continue
            col_idx = col_letters_to_index(m.group(1))
            row_idx = int(m.group(2)) - 1
            max_row = max(max_row, row_idx)
            max_col = max(max_col, col_idx)

            t = c_el.get("t")
            s_attr = c_el.get("s")
            style_idx = int(s_attr) if s_attr is not None else 0
            v_el = c_el.find(tag(NS_MAIN, "v"))

            if t == "s":
                idx = int(v_el.text) if v_el is not None and v_el.text else 0
                value = shared_strings[idx] if idx < len(shared_strings) else ""
                kind = "text"
            elif t == "inlineStr":
                is_el = c_el.find(tag(NS_MAIN, "is"))
                t_el = is_el.find(tag(NS_MAIN, "t")) if is_el is not None else None
                value = t_el.text if t_el is not None and t_el.text else ""
                kind = "text"
            elif t == "b":
                value = "TRUE" if (v_el is not None and v_el.text == "1") else "FALSE"
                kind = "text"
            elif t == "e":
                value = v_el.text if v_el is not None else ""
                kind = "text"
            elif t == "str":
                value = v_el.text if v_el is not None and v_el.text is not None else ""
                kind = "text"
            else:
                raw = v_el.text if v_el is not None else None
                if raw is None or raw == "":
                    continue
                if style_is_date(style_idx, numfmts, cellxfs):
                    try:
                        value = excel_serial_to_datetime(float(raw))
                        kind = "date"
                    except ValueError:
                        value, kind = raw, "text"
                else:
                    try:
                        value = float(raw)
                        kind = "number_raw"
                    except ValueError:
                        value, kind = raw, "text"

            if value == "" or value is None:
                continue
            grid[(row_idx, col_idx)] = (value, kind)
    return grid, max_row, max_col


def value_to_str(value):
    if isinstance(value, float):
        if value == int(value):
            return str(int(value))
        return repr(value)
    return str(value)


# ---------------------------------------------------------------------------
# Column type detection
# ---------------------------------------------------------------------------

def try_date_format(strings):
    strings = [s.strip() for s in strings if s.strip()]
    if not strings:
        return None
    best = None
    for fmt in DATE_FORMATS:
        ok = 0
        for s in strings:
            try:
                datetime.strptime(s, fmt)
                ok += 1
            except ValueError:
                pass
        if ok / len(strings) >= 0.9 and (best is None or ok > best[1]):
            best = (fmt, ok)
    return best[0] if best else None


def parse_number(s):
    m = NUMBER_RE.match(s.strip())
    if not m:
        return None
    num = float(m.group("num").replace(",", ""))
    negative = bool(m.group("neg")) or bool(m.group("paren_open") and m.group("paren_close"))
    if negative:
        num = -num
    decimals = len(m.group("num").split(".")[1]) if "." in m.group("num") else 0
    return num, m.group("cur"), bool(m.group("pct")), decimals


def classify_columns(grid, max_row, max_col, header_row, overrides, default_currency_code):
    col_types = {}
    for col in range(max_col + 1):
        override = overrides.get(col)
        cells = [
            grid[(r, col)] for r in range(header_row + 1, max_row + 1) if (r, col) in grid
        ]
        if override:
            kind = override
            meta = {}
            if kind == "date":
                strs = [value_to_str(v) for v, k in cells if k != "date"]
                meta["fmt"] = try_date_format(strs) or "%m-%d-%Y"
            elif kind == "currency":
                meta["code"] = default_currency_code
                meta["decimals"] = 2
            elif kind == "number":
                meta["decimals"] = 0
            col_types[col] = (kind, meta)
            continue

        if not cells:
            col_types[col] = ("text", {})
            continue

        date_pretyped = sum(1 for _, k in cells if k == "date")
        if cells and date_pretyped / len(cells) >= 0.8:
            col_types[col] = ("date", {"fmt": None})
            continue

        strs = [value_to_str(v) for v, k in cells]
        date_fmt = try_date_format(strs)
        if date_fmt:
            col_types[col] = ("date", {"fmt": date_fmt})
            continue

        parsed = [parse_number(s) for s in strs]
        matched = [p for p in parsed if p is not None]
        if strs and len(matched) / len(strs) >= 0.9:
            currency_syms = [p[1] for p in matched if p[1]]
            header_cell = grid.get((header_row, col))
            header_text = value_to_str(header_cell[0]).lower() if header_cell else ""
            is_currency_header = any(kw in header_text for kw in CURRENCY_KEYWORDS)
            max_decimals = max((p[3] for p in matched), default=0)
            if currency_syms:
                code = CURRENCY_SYMBOL_TO_CODE.get(currency_syms[0], default_currency_code)
                col_types[col] = ("currency", {"code": code, "decimals": max(2, max_decimals)})
            elif is_currency_header:
                col_types[col] = ("currency", {"code": default_currency_code, "decimals": max(2, max_decimals)})
            else:
                col_types[col] = ("number", {"decimals": max_decimals})
            continue

        col_types[col] = ("text", {})
    return col_types


def resolve_overrides(spec, header_row_values):
    """spec: comma-separated column letters or header names -> {col_idx: kind}"""
    result = {}
    if not spec:
        return result
    header_lookup = {value_to_str(v).strip().lower(): idx for idx, v in header_row_values.items()}
    for token in spec[0].split(","):
        token = token.strip()
        if not token:
            continue
        if re.fullmatch(r"[A-Za-z]+", token) and token.upper() not in header_lookup and token.lower() not in header_lookup:
            idx = col_letters_to_index(token.upper())
        else:
            idx = header_lookup.get(token.lower())
        if idx is not None:
            result[idx] = spec[1]
    return result


# ---------------------------------------------------------------------------
# ODS writing
# ---------------------------------------------------------------------------

def cell_xml_string(text):
    return f"<text:p>{xml_escape(text)}</text:p>"


def build_sheet_xml(name, grid, max_row, max_col, col_types, header_row, style_names):
    lines = [f"<table:table table:name={quoteattr(name)}>"]
    if max_col >= 0:
        lines.append(f'<table:table-column table:number-columns-repeated="{max_col + 1}"/>')

    for row in range(header_row, max_row + 1):
        lines.append("<table:table-row>")
        for col in range(max_col + 1):
            cell = grid.get((row, col))
            if cell is None:
                lines.append("<table:table-cell/>")
                continue
            value, kind = cell

            if row == header_row:
                text = value_to_str(value)
                style_attr = f' table:style-name="{style_names["header"]}"' if "header" in style_names else ""
                lines.append(
                    f'<table:table-cell{style_attr} office:value-type="string">{cell_xml_string(text)}</table:table-cell>'
                )
                continue

            col_type, meta = col_types.get(col, ("text", {}))

            if col_type == "date":
                dt = None
                if kind == "date":
                    dt = value
                else:
                    s = value_to_str(value).strip()
                    fmt = meta.get("fmt")
                    if fmt:
                        try:
                            dt = datetime.strptime(s, fmt)
                        except ValueError:
                            dt = None
                if dt is not None:
                    has_time = dt.hour or dt.minute or dt.second
                    style_key = "date_time" if has_time else "date"
                    date_value = dt.strftime("%Y-%m-%dT%H:%M:%S") if has_time else dt.strftime("%Y-%m-%d")
                    display = dt.strftime("%m/%d/%Y %H:%M:%S") if has_time else dt.strftime("%m/%d/%Y")
                    lines.append(
                        f'<table:table-cell table:style-name="{style_names[style_key]}" '
                        f'office:value-type="date" office:date-value="{date_value}">'
                        f"{cell_xml_string(display)}</table:table-cell>"
                    )
                    continue
                # fall through to text if parsing failed

            elif col_type in ("currency", "number"):
                s = value_to_str(value)
                parsed = parse_number(s)
                if parsed is not None:
                    num, _cur, _pct, _dec = parsed
                    decimals = meta.get("decimals", 2 if col_type == "currency" else 0)
                    if col_type == "currency":
                        code = meta.get("code", "USD")
                        symbol = CODE_TO_SYMBOL.get(code, code + " ")
                        display = f"-{symbol}{-num:,.{decimals}f}" if num < 0 else f"{symbol}{num:,.{decimals}f}"
                        style_key = f"currency_{code}_{decimals}"
                        lines.append(
                            f'<table:table-cell table:style-name="{style_names[style_key]}" '
                            f'office:value-type="currency" office:currency="{code}" '
                            f'office:value="{num}">{cell_xml_string(display)}</table:table-cell>'
                        )
                    else:
                        display = f"{num:,.{decimals}f}" if decimals else f"{int(num):,}"
                        style_key = f"number_{decimals}"
                        lines.append(
                            f'<table:table-cell table:style-name="{style_names[style_key]}" '
                            f'office:value-type="float" office:value="{num}">'
                            f"{cell_xml_string(display)}</table:table-cell>"
                        )
                    continue
                # fall through to text if parsing failed

            text = value_to_str(value)
            lines.append(f'<table:table-cell office:value-type="string">{cell_xml_string(text)}</table:table-cell>')
        lines.append("</table:table-row>")
    lines.append("</table:table>")
    return "\n".join(lines)


def collect_style_needs(all_col_types, header_present):
    needs = {"date": False, "date_time": False, "header": header_present}
    currencies = set()
    numbers = set()
    for col_types in all_col_types:
        for col_type, meta in col_types.values():
            if col_type == "date":
                needs["date"] = True
                needs["date_time"] = True  # cheap to always define both
            elif col_type == "currency":
                currencies.add((meta.get("code", "USD"), meta.get("decimals", 2)))
            elif col_type == "number":
                numbers.add(meta.get("decimals", 0))
    return needs, currencies, numbers


def build_automatic_styles(needs, currencies, numbers):
    parts = []
    style_names = {}

    if needs["date"]:
        parts.append(
            '<number:date-style style:name="N-Date">'
            '<number:month number:style="long"/><number:text>/</number:text>'
            '<number:day number:style="long"/><number:text>/</number:text>'
            '<number:year/></number:date-style>'
        )
        style_names["date"] = "ce-date"
        parts.append('<style:style style:name="ce-date" style:family="table-cell" style:data-style-name="N-Date"/>')

    if needs["date_time"]:
        parts.append(
            '<number:date-style style:name="N-DateTime">'
            '<number:month number:style="long"/><number:text>/</number:text>'
            '<number:day number:style="long"/><number:text>/</number:text>'
            '<number:year/><number:text> </number:text>'
            '<number:hours number:style="long"/><number:text>:</number:text>'
            '<number:minutes number:style="long"/><number:text>:</number:text>'
            '<number:seconds number:style="long"/></number:date-style>'
        )
        style_names["date_time"] = "ce-date-time"
        parts.append('<style:style style:name="ce-date-time" style:family="table-cell" style:data-style-name="N-DateTime"/>')

    for code, decimals in sorted(currencies):
        lang, country = CURRENCY_LOCALE.get(code, ("en", "US"))
        symbol = CODE_TO_SYMBOL.get(code, code)
        style_id = f"N-Currency-{code}-{decimals}"
        cell_id = f"ce-currency-{code}-{decimals}"
        parts.append(
            f'<number:currency-style style:name="{style_id}">'
            f'<number:currency-symbol number:language="{lang}" number:country="{country}">{xml_escape(symbol)}</number:currency-symbol>'
            f'<number:number number:decimal-places="{decimals}" number:min-integer-digits="1" number:grouping="true"/>'
            f"</number:currency-style>"
        )
        parts.append(f'<style:style style:name="{cell_id}" style:family="table-cell" style:data-style-name="{style_id}"/>')
        style_names[f"currency_{code}_{decimals}"] = cell_id

    for decimals in sorted(numbers):
        style_id = f"N-Number-{decimals}"
        cell_id = f"ce-number-{decimals}"
        parts.append(
            f'<number:number-style style:name="{style_id}">'
            f'<number:number number:decimal-places="{decimals}" number:min-integer-digits="1" number:grouping="true"/>'
            f"</number:number-style>"
        )
        parts.append(f'<style:style style:name="{cell_id}" style:family="table-cell" style:data-style-name="{style_id}"/>')
        style_names[f"number_{decimals}"] = cell_id

    if needs["header"]:
        parts.append(
            '<style:style style:name="ce-header" style:family="table-cell">'
            '<style:text-properties fo:font-weight="bold"/></style:style>'
        )
        style_names["header"] = "ce-header"

    return "\n".join(parts), style_names


CONTENT_XML_HEADER = """<?xml version="1.0" encoding="UTF-8"?>
<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
  xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
  xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
  xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
  xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"
  xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
  xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"
  xmlns:xlink="http://www.w3.org/1999/xlink"
  office:version="1.2">
"""

STYLES_XML = """<?xml version="1.0" encoding="UTF-8"?>
<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
  xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
  xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
  office:version="1.2">
  <office:styles>
    <style:default-style style:family="table-cell"/>
  </office:styles>
</office:document-styles>
"""

META_XML = """<?xml version="1.0" encoding="UTF-8"?>
<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
  xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" office:version="1.2">
  <office:meta>
    <meta:generator>xlsx_to_ods.py</meta:generator>
  </office:meta>
</office:document-meta>
"""

MANIFEST_XML = """<?xml version="1.0" encoding="UTF-8"?>
<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">
  <manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>
  <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
  <manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
  <manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>
</manifest:manifest>
"""


def write_ods(output_path, sheets):
    """sheets: list of (name, grid, max_row, max_col, col_types, header_row)"""
    all_col_types = [s[4] for s in sheets]
    header_present = any(s[5] is not None for s in sheets)
    needs, currencies, numbers = collect_style_needs(all_col_types, header_present)
    styles_xml_block, style_names = build_automatic_styles(needs, currencies, numbers)

    body_parts = []
    for name, grid, max_row, max_col, col_types, header_row in sheets:
        hr = header_row if header_row is not None else 0
        body_parts.append(build_sheet_xml(name, grid, max_row, max_col, col_types, hr, style_names))

    content = (
        CONTENT_XML_HEADER
        + "<office:automatic-styles>\n" + styles_xml_block + "\n</office:automatic-styles>\n"
        + "<office:body><office:spreadsheet>\n"
        + "\n".join(body_parts)
        + "\n</office:spreadsheet></office:body>\n</office:document-content>\n"
    )

    with zipfile.ZipFile(output_path, "w") as zf:
        zf.writestr("mimetype", "application/vnd.oasis.opendocument.spreadsheet", compress_type=zipfile.ZIP_STORED)
        zf.writestr("META-INF/manifest.xml", MANIFEST_XML, compress_type=zipfile.ZIP_DEFLATED)
        zf.writestr("content.xml", content, compress_type=zipfile.ZIP_DEFLATED)
        zf.writestr("styles.xml", STYLES_XML, compress_type=zipfile.ZIP_DEFLATED)
        zf.writestr("meta.xml", META_XML, compress_type=zipfile.ZIP_DEFLATED)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def convert(input_path, output_path, date_spec=None, currency_spec=None, number_spec=None,
            currency_code="USD", no_header=False):
    with zipfile.ZipFile(input_path) as zf:
        numfmts, cellxfs = parse_styles(zf)
        shared_strings = parse_shared_strings(zf)
        sheet_infos = parse_workbook_sheets(zf)

        sheets = []
        for name, path in sheet_infos:
            grid, max_row, max_col = parse_worksheet(zf, path, shared_strings, numfmts, cellxfs)
            if max_row < 0:
                sheets.append((name, grid, max_row, max_col, {}, None))
                continue

            header_row = None if no_header else 0
            header_values = {}
            if header_row is not None:
                header_values = {
                    col: grid[(header_row, col)][0]
                    for col in range(max_col + 1)
                    if (header_row, col) in grid
                }

            overrides = {}
            for spec, kind in ((date_spec, "date"), (currency_spec, "currency"), (number_spec, "number")):
                overrides.update(resolve_overrides((spec, kind) if spec else None, header_values))

            col_types = classify_columns(
                grid, max_row, max_col, header_row if header_row is not None else -1,
                overrides, currency_code,
            )
            sheets.append((name, grid, max_row, max_col, col_types, header_row))

    write_ods(output_path, sheets)
    return sheets


def summarize(sheets):
    for name, grid, max_row, max_col, col_types, header_row in sheets:
        print(f"Sheet '{name}': {max_row + 1} rows x {max_col + 1} cols")
        hr = header_row if header_row is not None else -1
        for col in range(max_col + 1):
            header = grid.get((hr, col))
            header_text = value_to_str(header[0]) if header else index_to_col_letters(col)
            col_type, meta = col_types.get(col, ("text", {}))
            detail = ""
            if col_type == "currency":
                detail = f" ({meta.get('code')}, {meta.get('decimals')}dp)"
            elif col_type == "number":
                detail = f" ({meta.get('decimals')}dp)"
            elif col_type == "date":
                detail = f" (parsed as {meta.get('fmt') or 'native date'})"
            print(f"  {index_to_col_letters(col)} {header_text!r}: {col_type}{detail}")


def main():
    parser = argparse.ArgumentParser(description="Convert .xlsx to .ods with date/currency/number typed cells.")
    parser.add_argument("input", help="Path to the source .xlsx file")
    parser.add_argument("-o", "--output", help="Path to the output .ods file (default: same name, .ods extension)")
    parser.add_argument("--date-columns", help="Comma-separated column letters or header names to force as dates")
    parser.add_argument("--currency-columns", help="Comma-separated column letters or header names to force as currency")
    parser.add_argument("--number-columns", help="Comma-separated column letters or header names to force as plain numbers")
    parser.add_argument("--currency-code", default="USD", help="Default ISO currency code when no symbol is present (default: USD)")
    parser.add_argument("--no-header", action="store_true", help="Treat the first row as data, not a header")
    parser.add_argument("-q", "--quiet", action="store_true", help="Suppress the column-type summary")
    args = parser.parse_args()

    output_path = args.output or re.sub(r"\.xlsx$", "", args.input, flags=re.IGNORECASE) + ".ods"

    sheets = convert(
        args.input, output_path,
        date_spec=args.date_columns, currency_spec=args.currency_columns, number_spec=args.number_columns,
        currency_code=args.currency_code, no_header=args.no_header,
    )

    if not args.quiet:
        summarize(sheets)
    print(f"Wrote {output_path}")


if __name__ == "__main__":
    sys.exit(main())
