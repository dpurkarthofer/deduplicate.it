#!/usr/bin/env python3
"""
Literature Search Deduplication — v6
--------------------------------------
Deduplicates literature search exports using a compound key of
(normalised DOI, normalised title).  Supports all major database
export formats and produces output in one or more formats.

What's new vs the old single-file version
------------------------------------------
* Compound key:  DOI + normalised title (not DOI alone)
* Title normalisation pipeline v6 (Greek expansion, trademark fix,
  line-break hyphen fix, transliteration, diacritic stripping …)
* Format auto-detection (RIS, MEDLINE, WoS, BibTeX, CSV)
* DOI collision reporting  (doi_collisions.csv with in_deduplicated_output)
* Multi-format output:  RIS, CSV, MEDLINE tagged text, XML
* Flowchart HTML generated from draw.io template

Uses only the Python standard library — no pip install required.

Usage
-----
    1. Place all export files in a folder called "source/" next to this script.
    2. Run: python3 literature_deduplication.py

All supported formats are auto-detected; files are processed in
alphabetical order, which also sets tie-break priority (files earlier
in the alphabet are preferred when selecting among duplicates).
"""

import re, csv, io, os, unicodedata, html
from pathlib import Path
from urllib.parse import unquote
from collections import defaultdict, Counter

# ── CONFIGURATION ──────────────────────────────────────────────────────────────
# Place all input files in the 'source/' folder next to this script.
# All supported formats (.ris, .txt, .nbib, .bib, .csv, .tsv, .ciw, .enw)
# are auto-detected. Files are processed in alphabetical order.

SOURCE_DIR = Path(__file__).parent / 'source'

OUTPUT_RIS        = "deduplicated.ris"
OUTPUT_CSV        = "excluded_duplicates.csv"
OUTPUT_COLLISIONS = "doi_collisions.csv"
OUTPUT_FORMATS    = ['ris']  # options: 'ris', 'csv', 'medline', 'xml'
# ──────────────────────────────────────────────────────────────────────────────



# ═══════════════════════════════════════════════════════════════════════════════
# DOI NORMALISATION  (per DOI Handbook §3.4-3.8 and ISO 26324)
# ═══════════════════════════════════════════════════════════════════════════════

_BL_FOLD = str.maketrans(
    'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
    'abcdefghijklmnopqrstuvwxyz'
)
_DOI_VALID = re.compile(r'^10\.\d{4,}/')


def normalize_doi(raw: str) -> str:
    if not raw:
        return ''
    s = unquote(raw.strip())
    s = re.sub(r'^urn\s*:\s*doi\s*:\s*', '', s, flags=re.IGNORECASE)
    s = re.sub(r'^doi\s*:\s*', '', s, flags=re.IGNORECASE)
    s = re.sub(r'^https?://(?:dx\.)?doi\.org/', '', s, flags=re.IGNORECASE)
    s = s.strip().rstrip(' \t.,;:')
    s = s.translate(_BL_FOLD)
    return s if _DOI_VALID.match(s) else ''


# ═══════════════════════════════════════════════════════════════════════════════
# TITLE NORMALISATION  (v6)
# ═══════════════════════════════════════════════════════════════════════════════
# Steps:
#   0.  html.unescape
#   0b. Strip trademark indicators (™ ® ℠, parenthesised (TM), literal TM suffix)
#   0c. Rejoin line-break hyphens: "insuf- ficiency" → "insufficiency"
#   1.  Unicode NFC composition
#   2.  Transliterate special Latin characters and subscript digits
#   3a. NFD decomposition
#   3b. Strip combining diacritical marks
#   3c. Expand Greek letters: α → alpha, β → beta … (v6)
#   4.  Lowercase
#   5.  Remove all non-alphanumeric, non-space characters
#   6.  Collapse whitespace

_TITLE_TRANSLIT = str.maketrans({
    ord('æ'): 'ae', ord('Æ'): 'ae',
    ord('œ'): 'oe', ord('Œ'): 'oe',
    ord('ø'): 'o',  ord('Ø'): 'o',
    ord('ß'): 'ss',
    ord('ð'): 'd',  ord('Ð'): 'd',
    ord('þ'): 'th', ord('Þ'): 'th',
    ord('ı'): 'i',
    ord('ł'): 'l',  ord('Ł'): 'l',
    ord('đ'): 'd',  ord('Đ'): 'd',
    ord('₀'): '0', ord('₁'): '1', ord('₂'): '2',
    ord('₃'): '3', ord('₄'): '4', ord('₅'): '5',
    ord('₆'): '6', ord('₇'): '7', ord('₈'): '8', ord('₉'): '9',
})

_GREEK_EXPAND = str.maketrans({
    ord('\u03b1'): 'alpha',   ord('\u03b2'): 'beta',    ord('\u03b3'): 'gamma',
    ord('\u03b4'): 'delta',   ord('\u03b5'): 'epsilon', ord('\u03b6'): 'zeta',
    ord('\u03b7'): 'eta',     ord('\u03b8'): 'theta',   ord('\u03b9'): 'iota',
    ord('\u03ba'): 'kappa',   ord('\u03bb'): 'lambda',  ord('\u03bc'): 'mu',
    ord('\u03bd'): 'nu',      ord('\u03be'): 'xi',      ord('\u03bf'): 'omicron',
    ord('\u03c0'): 'pi',      ord('\u03c1'): 'rho',     ord('\u03c3'): 'sigma',
    ord('\u03c2'): 'sigma',   ord('\u03c4'): 'tau',     ord('\u03c5'): 'upsilon',
    ord('\u03c6'): 'phi',     ord('\u03c7'): 'chi',     ord('\u03c8'): 'psi',
    ord('\u03c9'): 'omega',
    ord('\u0391'): 'alpha',   ord('\u0392'): 'beta',    ord('\u0393'): 'gamma',
    ord('\u0394'): 'delta',   ord('\u0395'): 'epsilon', ord('\u0396'): 'zeta',
    ord('\u0397'): 'eta',     ord('\u0398'): 'theta',   ord('\u0399'): 'iota',
    ord('\u039a'): 'kappa',   ord('\u039b'): 'lambda',  ord('\u039c'): 'mu',
    ord('\u039d'): 'nu',      ord('\u039e'): 'xi',      ord('\u039f'): 'omicron',
    ord('\u03a0'): 'pi',      ord('\u03a1'): 'rho',     ord('\u03a3'): 'sigma',
    ord('\u03a4'): 'tau',     ord('\u03a5'): 'upsilon', ord('\u03a6'): 'phi',
    ord('\u03a7'): 'chi',     ord('\u03a8'): 'psi',     ord('\u03a9'): 'omega',
})


def normalize_title(raw: str) -> str:
    if not raw:
        return ''
    s = html.unescape(raw)
    s = re.sub(r'[™®©℠℗]', '', s)
    s = re.sub(r'\s*\(TM\)\s*', ' ', s, flags=re.IGNORECASE)
    s = re.sub(r'(?<=[a-z])TM(?=\W|$)', '', s)
    s = re.sub(r'(?<!\s)-\s+', '', s)
    s = unicodedata.normalize('NFC', s)
    s = s.translate(_TITLE_TRANSLIT)
    s = unicodedata.normalize('NFD', s)
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    s = s.translate(_GREEK_EXPAND)
    s = s.lower()
    s = re.sub(r'[^a-z0-9\s]', '', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s


def _get_raw_title(rec: dict) -> str:
    return ' '.join(rec['ris_fields'].get('TI', rec['ris_fields'].get('T1', []))[:1])


# ═══════════════════════════════════════════════════════════════════════════════
# FORMAT DETECTION
# ═══════════════════════════════════════════════════════════════════════════════

def detect_format(path: str) -> str:
    try:
        with open(path, encoding='utf-8', errors='replace') as fh:
            sample = fh.read(4096)
    except OSError:
        return 'unknown'
    sample = sample.lstrip('\ufeff\ufffe\xef\xbb\xbf')
    if re.search(r'^TY\s{1,2}-\s', sample, re.MULTILINE):
        return 'ris'
    if re.search(r'^PMID-\s', sample, re.MULTILINE):
        return 'medline'
    n_numbered = len(re.findall(r'^\d+:\s+[A-Z]', sample, re.MULTILINE))
    has_inline_doi = bool(re.search(r'\bdoi:\s*10\.', sample, re.IGNORECASE))
    if n_numbered >= 2 and has_inline_doi:
        return 'pubmed_summary'
    if re.search(r'^FN\s+(?:Clarivate|Web of Science|Thomson|ISI)',
                 sample, re.MULTILINE | re.IGNORECASE):
        return 'wos'
    if (re.search(r'^PT\s+[A-Z]', sample, re.MULTILINE) and
            re.search(r'^UT\s+WOS:', sample, re.MULTILINE)):
        return 'wos'
    if re.search(r'^\s*@\w+\s*[\{\(]', sample, re.MULTILINE):
        return 'bibtex'
    first_line = sample.split('\n')[0].strip().strip('\ufeff')
    if (re.search(r'\bDOI\b', first_line, re.IGNORECASE) and
            (',' in first_line or '\t' in first_line)):
        return 'csv'
    return 'unknown'


# ═══════════════════════════════════════════════════════════════════════════════
# UNIFIED RECORD CONSTRUCTOR
# ═══════════════════════════════════════════════════════════════════════════════

def _make_rec(raw_doi, has_abstract, ris_fields, path, fmt=''):
    return {
        'source_file':   os.path.basename(path),
        'source_format': fmt,
        'raw_doi':       raw_doi,
        'norm_doi':      normalize_doi(raw_doi),
        'has_abstract':  has_abstract,
        'ris_fields':    ris_fields,
        'uid':           None,
    }


# ═══════════════════════════════════════════════════════════════════════════════
# PARSER — MEDLINE / PubMed NBIB  (.txt, .nbib)
# ═══════════════════════════════════════════════════════════════════════════════

def parse_medline(path: str) -> list:
    records, raw, last_tag = [], {}, None
    with open(path, encoding='utf-8', errors='replace') as fh:
        for line in fh:
            line = line.rstrip('\r\n')
            if not line.strip():
                if raw:
                    records.append(_medline_to_rec(raw, path))
                    raw, last_tag = {}, None
                continue
            if len(line) >= 6 and line[4:6] == '- ':
                last_tag = line[:4].strip()
                raw.setdefault(last_tag, []).append(line[6:])
            elif line.startswith('      ') and last_tag:
                raw[last_tag][-1] += ' ' + line.strip()
    if raw:
        records.append(_medline_to_rec(raw, path))
    return records


def _medline_to_rec(raw: dict, path: str) -> dict:
    raw_doi = ''
    for tag in ('LID', 'AID'):
        for v in raw.get(tag, []):
            if '[doi]' in v.lower():
                raw_doi = re.sub(r'\s*\[doi\].*$', '', v, flags=re.IGNORECASE).strip()
                break
        if raw_doi:
            break
    ris = {'TY': [_ml_pt_to_ris(raw.get('PT', ['Journal Article'])[0])]}
    for ml, rs in [('TI', 'TI'), ('AB', 'AB'), ('JT', 'JO'), ('TA', 'J2'),
                   ('VI', 'VL'), ('IP', 'IS'), ('DP', 'PY'), ('PMID', 'AN'), ('SN', 'SN')]:
        if ml in raw:
            ris[rs] = raw[ml]
    if 'PG' in raw:
        parts = re.split(r'\s*-\s*', raw['PG'][0], maxsplit=1)
        ris['SP'] = [parts[0].strip()]
        if len(parts) > 1:
            ris['EP'] = [parts[1].strip()]
    ris['AU'] = raw.get('FAU', raw.get('AU', []))
    if 'MH' in raw:
        ris['KW'] = raw['MH']
    if raw_doi:
        ris['DO'] = [raw_doi]
    if 'PMID' in raw:
        ris['UR'] = [f"https://pubmed.ncbi.nlm.nih.gov/{raw['PMID'][0].strip()}/"]
    return _make_rec(raw_doi, bool(' '.join(raw.get('AB', [])).strip()), ris, path, 'MEDLINE')


def _ml_pt_to_ris(pt: str) -> str:
    pt_l = pt.lower()
    if 'review' in pt_l or 'journal' in pt_l or 'article' in pt_l: return 'JOUR'
    if 'conference' in pt_l or 'congress' in pt_l: return 'CONF'
    if 'book' in pt_l or 'chapter' in pt_l: return 'CHAP'
    if 'report' in pt_l: return 'RPRT'
    return 'JOUR'



# ═══════════════════════════════════════════════════════════════════════════════
# PARSER — RIS  (.ris)
# ═══════════════════════════════════════════════════════════════════════════════

def parse_ris(path: str) -> list:
    records, fields = [], {}
    with open(path, encoding='utf-8', errors='replace') as fh:
        for line in fh:
            line = line.rstrip('\r\n').lstrip('\ufeff')
            if re.match(r'^ER\s*-?\s*$', line.strip()):
                if fields:
                    records.append(_ris_to_rec(fields, path))
                    fields = {}
                continue
            m = re.match(r'^([A-Z][A-Z0-9])\s{1,2}-\s?(.*)', line)
            if m:
                fields.setdefault(m.group(1), []).append(m.group(2))
    if fields:
        records.append(_ris_to_rec(fields, path))
    return records


def _ris_to_rec(fields: dict, path: str) -> dict:
    raw_doi = next((v.strip() for v in fields.get('DO', []) if v.strip()), '')
    if not raw_doi:
        for v in fields.get('L3', []) + fields.get('UR', []):
            if 'doi.org/' in v.lower() or re.match(r'^\s*10\.\d{4,}/', v):
                raw_doi = v.strip()
                break
    if 'TY' not in fields:
        fields['TY'] = ['JOUR']
    abstract = ' '.join(fields.get('AB', fields.get('N2', [])))
    return _make_rec(raw_doi, bool(abstract.strip()), fields, path, 'RIS')


# ═══════════════════════════════════════════════════════════════════════════════
# PARSER — Web of Science ISI-tagged  (.txt, .ciw)
# ═══════════════════════════════════════════════════════════════════════════════

def parse_wos(path: str) -> list:
    records, raw, last_tag = [], {}, None
    with open(path, encoding='utf-8', errors='replace') as fh:
        for line in fh:
            line = line.rstrip('\r\n')
            stripped = line.strip()
            if not stripped or re.match(r'^(?:FN|VR)\s', line):
                continue
            if stripped == 'ER':
                if raw:
                    records.append(_wos_to_rec(raw, path))
                    raw, last_tag = {}, None
                continue
            if len(line) >= 3 and re.match(r'^[A-Z][A-Z0-9] ', line):
                last_tag = line[:2]
                val = line[3:].strip()
                if val:
                    raw.setdefault(last_tag, []).append(val)
            elif line.startswith('   ') and last_tag:
                raw[last_tag][-1] += ' ' + stripped
    if raw:
        records.append(_wos_to_rec(raw, path))
    return records


def _wos_to_rec(raw: dict, path: str) -> dict:
    raw_doi = raw.get('DI', [''])[0].strip()
    ris = {'TY': ['JOUR']}
    for wt, rt in [('TI', 'TI'), ('AB', 'AB'), ('SO', 'JO'), ('VL', 'VL'),
                   ('IS', 'IS'), ('BP', 'SP'), ('EP', 'EP'), ('SN', 'SN'),
                   ('PY', 'PY'), ('UT', 'AN'), ('PU', 'PB')]:
        if wt in raw:
            ris[rt] = [raw[wt][0]]
    ris['AU'] = raw.get('AF', raw.get('AU', []))
    for kw_tag in ('DE', 'ID'):
        if kw_tag in raw:
            ris.setdefault('KW', []).extend(raw[kw_tag])
    if raw_doi:
        ris['DO'] = [raw_doi]
    return _make_rec(raw_doi, bool(' '.join(raw.get('AB', [])).strip()), ris, path, 'WOS')


# ═══════════════════════════════════════════════════════════════════════════════
# PARSER — BibTeX  (.bib)
# ═══════════════════════════════════════════════════════════════════════════════

def parse_bibtex(path: str) -> list:
    with open(path, encoding='utf-8', errors='replace') as fh:
        content = fh.read().lstrip('\ufeff')
    records = []
    for m in re.finditer(r'@(\w+)\s*[\{\(]([^@]*)', content, re.DOTALL):
        entry_type = m.group(1).lower()
        if entry_type in ('string', 'preamble', 'comment'):
            continue
        body = m.group(2)
        fields = {}
        for fm in re.finditer(
            r'(\w+)\s*=\s*(?:\{((?:[^{}]|\{[^{}]*\})*)\}|"([^"]*)")',
            body, re.DOTALL
        ):
            fields[fm.group(1).lower()] = (fm.group(2) or fm.group(3) or '').strip()
        if fields:
            records.append(_bibtex_to_rec(fields, entry_type, path))
    return records


_BIB_TY_MAP = {
    'article': 'JOUR', 'inproceedings': 'CONF', 'proceedings': 'CONF',
    'book': 'BOOK', 'incollection': 'CHAP', 'misc': 'GEN',
    'techreport': 'RPRT', 'thesis': 'THES', 'phdthesis': 'THES',
    'mastersthesis': 'THES', 'unpublished': 'UNPB',
}


def _bibtex_to_rec(fields: dict, entry_type: str, path: str) -> dict:
    raw_doi = fields.get('doi', '').strip()
    ris = {'TY': [_BIB_TY_MAP.get(entry_type, 'GEN')]}
    for bf, rt in [('title', 'TI'), ('abstract', 'AB'), ('journal', 'JO'),
                   ('booktitle', 'JO'), ('volume', 'VL'), ('number', 'IS'),
                   ('year', 'PY'), ('issn', 'SN'), ('isbn', 'SN'), ('url', 'UR')]:
        if bf in fields and fields[bf] and rt not in ris:
            ris[rt] = [fields[bf]]
    if 'author' in fields:
        ris['AU'] = [a.strip() for a in
                     re.split(r'\s+and\s+', fields['author'], flags=re.IGNORECASE)]
    if 'pages' in fields:
        pg = re.split(r'\s*-+\s*', fields['pages'], maxsplit=1)
        ris['SP'] = [pg[0].strip()]
        if len(pg) > 1:
            ris['EP'] = [pg[-1].strip()]
    if 'keywords' in fields:
        ris['KW'] = [k.strip() for k in re.split(r'[;,]', fields['keywords']) if k.strip()]
    if raw_doi:
        ris['DO'] = [raw_doi]
    return _make_rec(raw_doi, bool(fields.get('abstract', '').strip()), ris, path, 'BibTeX')


# ═══════════════════════════════════════════════════════════════════════════════
# PARSER — CSV / TSV  (.csv, .tsv)
# ═══════════════════════════════════════════════════════════════════════════════

def parse_csv_file(path: str) -> list:
    with open(path, encoding='utf-8', errors='replace') as fh:
        raw_text = fh.read().lstrip('\ufeff')
    first_line = raw_text.split('\n')[0]
    sep = '\t' if first_line.count('\t') > first_line.count(',') else ','
    reader = csv.DictReader(io.StringIO(raw_text), delimiter=sep)
    records = []
    for row in reader:
        col = {(k or '').strip().lower(): (v or '').strip() for k, v in row.items() if k}
        raw_doi  = next((col[c] for c in ('doi', 'doi link', 'digital object identifier') if col.get(c)), '')
        title    = next((col[c] for c in ('title', 'article title', 'document title') if col.get(c)), '')
        abstract = next((col[c] for c in ('abstract', 'author abstract') if col.get(c)), '')
        au_raw   = next((col[c] for c in ('authors', 'author', 'author full names') if col.get(c)), '')
        year     = next((col[c][:4] for c in ('year', 'publication year', 'pub year') if col.get(c)), '')
        authors  = [a.strip() for a in re.split(r';', au_raw) if a.strip()]
        ris = {'TY': ['JOUR']}
        if title:    ris['TI'] = [title]
        if abstract: ris['AB'] = [abstract]
        if authors:  ris['AU'] = authors
        if year:     ris['PY'] = [year]
        for rt, cols in [
            ('JO', ('source title', 'source', 'journal', 'publication name', 'journal title')),
            ('VL', ('volume',)), ('IS', ('issue', 'number')),
            ('SP', ('page start', 'start page', 'art. no.')), ('EP', ('page end', 'end page')),
            ('SN', ('issn', 'isbn', 'eissn')),
            ('AN', ('eid', 'accession number', 'pubmed id', 'ut (unique wos id)', 'ut')),
        ]:
            v = next((col[c] for c in cols if col.get(c)), '')
            if v: ris[rt] = [v]
        if raw_doi: ris['DO'] = [raw_doi]
        records.append(_make_rec(raw_doi, bool(abstract), ris, path, 'CSV'))
    return records


# ═══════════════════════════════════════════════════════════════════════════════
# FORMAT DISPATCHER
# ═══════════════════════════════════════════════════════════════════════════════

_EXT_FALLBACK = {
    '.txt': 'medline', '.nbib': 'medline', '.ris': 'ris', '.enw': 'ris',
    '.ciw': 'wos', '.bib': 'bibtex', '.csv': 'csv', '.tsv': 'csv',
}
_PARSERS = {
    'medline': parse_medline,
    'ris': parse_ris, 'wos': parse_wos, 'bibtex': parse_bibtex, 'csv': parse_csv_file,
}


def load_file(path: str) -> tuple:
    fmt = detect_format(path)
    if fmt == 'pubmed_summary':
        return [], 'pubmed_summary'
    if fmt == 'unknown':
        fmt = _EXT_FALLBACK.get(Path(path).suffix.lower(), 'ris')
    return _PARSERS.get(fmt, parse_ris)(path), fmt


# ═══════════════════════════════════════════════════════════════════════════════
# RIS WRITER
# ═══════════════════════════════════════════════════════════════════════════════

_SINGLE = {'TY', 'TI', 'T1', 'AB', 'N2', 'DO', 'VL', 'IS', 'SP', 'EP',
           'PY', 'Y1', 'JO', 'JF', 'J2', 'SN', 'AN', 'UR', 'PB', 'CY'}
_TAG_ORDER = ['T1', 'TI', 'AU', 'A1', 'PY', 'Y1', 'AB', 'N2', 'JO', 'JF',
              'J2', 'VL', 'IS', 'SP', 'EP', 'SN', 'DO', 'UR', 'L2', 'AN',
              'ID', 'KW', 'PT', 'DB']


def to_ris(rec: dict) -> str:
    f = rec['ris_fields']
    lines = [f"TY  - {(f.get('TY') or ['JOUR'])[0]}",
             f"DB  - {rec['source_file']}"]
    written = {'TY'}
    for tag in _TAG_ORDER:
        if tag not in f or tag in written:
            continue
        vals = f[tag]
        lines += ([f"{tag}  - {vals[0]}"] if tag in _SINGLE
                  else [f"{tag}  - {v}" for v in vals])
        written.add(tag)
    for tag, vals in f.items():
        if tag in written or tag in {'TY', 'ER'}:
            continue
        lines += ([f"{tag}  - {vals[0]}"] if tag in _SINGLE
                  else [f"{tag}  - {v}" for v in vals])
    lines.append('ER  -')
    return '\n'.join(lines)


# ═══════════════════════════════════════════════════════════════════════════════
# FORMAT WRITERS — deduplicated records in CSV, MEDLINE txt, or XML
# ═══════════════════════════════════════════════════════════════════════════════

def _ris_get(rec, *tags):
    for tag in tags:
        vals = rec['ris_fields'].get(tag, [])
        if vals:
            return vals[0]
    return ''


def write_deduplicated_ris(recs, path):
    with open(path, 'w', encoding='utf-8') as fh:
        for rec in recs:
            fh.write(to_ris(rec) + '\n\n')


_CSV_COLS = [
    'record_type', 'title', 'authors', 'year', 'journal',
    'volume', 'issue', 'start_page', 'end_page',
    'doi', 'issn', 'abstract', 'keywords',
    'accession_number', 'url', 'source_file', 'source_format',
]


def _to_csv_row(rec):
    f = rec['ris_fields']
    return {
        'record_type':      (f.get('TY') or [''])[0],
        'title':            _ris_get(rec, 'TI', 'T1'),
        'authors':          '; '.join(f.get('AU', f.get('A1', []))),
        'year':             _ris_get(rec, 'PY', 'Y1'),
        'journal':          _ris_get(rec, 'JO', 'JF', 'J2'),
        'volume':           _ris_get(rec, 'VL'),
        'issue':            _ris_get(rec, 'IS'),
        'start_page':       _ris_get(rec, 'SP'),
        'end_page':         _ris_get(rec, 'EP'),
        'doi':              _ris_get(rec, 'DO'),
        'issn':             _ris_get(rec, 'SN'),
        'abstract':         _ris_get(rec, 'AB', 'N2'),
        'keywords':         '; '.join(f.get('KW', [])),
        'accession_number': _ris_get(rec, 'AN'),
        'url':              _ris_get(rec, 'UR'),
        'source_file':      rec['source_file'],
        'source_format':    rec['source_format'],
    }


def write_deduplicated_csv(recs, path):
    with open(path, 'w', newline='', encoding='utf-8') as fh:
        writer = csv.DictWriter(fh, fieldnames=_CSV_COLS)
        writer.writeheader()
        writer.writerows(_to_csv_row(r) for r in recs)


def _to_medline(rec):
    f = rec['ris_fields']
    lines = []

    def emit(tag, value):
        lines.append(f'{tag:<4}- {value}')

    ti = _ris_get(rec, 'TI', 'T1')
    if ti:  emit('TI', ti)
    for au in f.get('AU', f.get('A1', [])):
        emit('AU', au)
    yr = _ris_get(rec, 'PY', 'Y1')
    if yr:  emit('DP', yr)
    jt = _ris_get(rec, 'JO', 'JF')
    if jt:  emit('JT', jt)
    ta = _ris_get(rec, 'J2')
    if ta:  emit('TA', ta)
    vl = _ris_get(rec, 'VL')
    if vl:  emit('VI', vl)
    ip = _ris_get(rec, 'IS')
    if ip:  emit('IP', ip)
    sp, ep = _ris_get(rec, 'SP'), _ris_get(rec, 'EP')
    if sp and ep:   emit('PG', f'{sp}-{ep}')
    elif sp:        emit('PG', sp)
    doi = _ris_get(rec, 'DO')
    if doi: emit('LID', f'{doi} [doi]')
    ab = _ris_get(rec, 'AB', 'N2')
    if ab:  emit('AB', ab)
    for kw in f.get('KW', []):
        emit('MH', kw)
    sn = _ris_get(rec, 'SN')
    if sn:  emit('IS', sn)
    an = _ris_get(rec, 'AN')
    if an and an.isdigit():
        emit('PMID', an)
    emit('SO', rec['source_file'])
    lines.append('')
    return '\n'.join(lines)


def write_deduplicated_medline(recs, path):
    with open(path, 'w', encoding='utf-8') as fh:
        for rec in recs:
            fh.write(_to_medline(rec) + '\n')


def _xml_esc(s):
    return (str(s)
            .replace('&', '&amp;').replace('<', '&lt;')
            .replace('>', '&gt;').replace('"', '&quot;'))


def _to_xml_record(rec, indent=2):
    f = rec['ris_fields']
    sp = ' ' * indent
    lines = [f'{sp}<record>']

    def tag(name, value):
        if value:
            lines.append(f'{sp}  <{name}>{_xml_esc(value)}</{name}>')

    tag('type',    _ris_get(rec, 'TY'))
    tag('title',   _ris_get(rec, 'TI', 'T1'))
    authors = f.get('AU', f.get('A1', []))
    if authors:
        lines.append(f'{sp}  <authors>')
        for au in authors:
            lines.append(f'{sp}    <author>{_xml_esc(au)}</author>')
        lines.append(f'{sp}  </authors>')
    tag('year',        _ris_get(rec, 'PY', 'Y1'))
    tag('journal',     _ris_get(rec, 'JO', 'JF', 'J2'))
    tag('volume',      _ris_get(rec, 'VL'))
    tag('issue',       _ris_get(rec, 'IS'))
    tag('start_page',  _ris_get(rec, 'SP'))
    tag('end_page',    _ris_get(rec, 'EP'))
    tag('doi',         _ris_get(rec, 'DO'))
    tag('issn',        _ris_get(rec, 'SN'))
    tag('abstract',    _ris_get(rec, 'AB', 'N2'))
    kws = f.get('KW', [])
    if kws:
        lines.append(f'{sp}  <keywords>')
        for kw in kws:
            lines.append(f'{sp}    <keyword>{_xml_esc(kw)}</keyword>')
        lines.append(f'{sp}  </keywords>')
    tag('accession',     _ris_get(rec, 'AN'))
    tag('url',           _ris_get(rec, 'UR'))
    tag('source_file',   rec['source_file'])
    tag('source_format', rec['source_format'])
    lines.append(f'{sp}</record>')
    return '\n'.join(lines)


def write_deduplicated_xml(recs, path):
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write('<?xml version="1.0" encoding="utf-8"?>\n<records>\n')
        for rec in recs:
            fh.write(_to_xml_record(rec) + '\n')
        fh.write('</records>\n')


# ═══════════════════════════════════════════════════════════════════════════════
# FLOWCHART — fill PRISMA draw.io HTML templates with deduplication counts
# ═══════════════════════════════════════════════════════════════════════════════

def _find_file(name: str) -> str:
    """Locate a template file relative to this script or CWD."""
    candidates = [
        Path(__file__).parent / name,
        Path(__file__).parent.parent / name,
        Path(name),
    ]
    for p in candidates:
        if p.exists():
            return str(p)
    return ''


def _write_xxx_flowchart(template_name: str, n_total: int, n_excluded: int,
                          out_path: str) -> bool:
    """Fill the two (n = XXX) placeholders and write the PRISMA template.
    Returns True on success, False if template not found."""
    tpl = _find_file(template_name)
    if not tpl:
        return False
    count = [0]
    def _fill(m):
        val = n_total if count[0] == 0 else n_excluded
        count[0] += 1
        return f'(n\u00a0=\u00a0{val:,})'
    html = re.sub(r'\(n = XXX\)', _fill, Path(tpl).read_text(encoding='utf-8'))
    Path(out_path).write_text(html, encoding='utf-8')
    return True


def write_simple_flowchart(n_total: int, n_excluded: int, out_path: str) -> None:
    if _write_xxx_flowchart('FlowchartPRISMAsimple.drawio.html', n_total, n_excluded, out_path):
        print(f'  {out_path:<60} PRISMA flowchart (simple)')
    else:
        print('  (simple PRISMA flowchart skipped: FlowchartPRISMAsimple.drawio.html not found)')


def write_complex_flowchart(n_total: int, n_excluded: int, out_path: str) -> None:
    if _write_xxx_flowchart('FlowchartPRISMAcomplex.drawio.html', n_total, n_excluded, out_path):
        print(f'  {out_path:<60} PRISMA flowchart (extended)')
    else:
        print('  (extended PRISMA flowchart skipped: FlowchartPRISMAcomplex.drawio.html not found)')


# ═══════════════════════════════════════════════════════════════════════════════
# MAIN
# ═══════════════════════════════════════════════════════════════════════════════

def main():
    # ── STEP 1 — LOAD ─────────────────────────────────────────────────────────
    print('=' * 70)
    print('  STEP 1 — Load input files')
    print('=' * 70)

    all_records  = []
    file_priority = {}

    _supported_exts = set(_EXT_FALLBACK.keys())
    if not SOURCE_DIR.is_dir():
        print(f'  ERROR: Source folder not found: {SOURCE_DIR}')
        print(f'         Create a "source/" folder next to this script and place your export files there.')
        return
    input_files = sorted(p for p in SOURCE_DIR.iterdir()
                         if p.is_file() and p.suffix.lower() in _supported_exts)
    if not input_files:
        print(f'  ERROR: No supported files found in: {SOURCE_DIR}')
        print(f'         Supported extensions: {", ".join(sorted(_supported_exts))}')
        return
    print(f'  Found {len(input_files)} file(s) in: {SOURCE_DIR}')

    for fpath in input_files:
        recs, fmt = load_file(str(fpath))
        bn = fpath.name
        print(f'  {bn}')
        if fmt == 'pubmed_summary':
            print(f'  WARNING: PubMed Summary format is not supported.')
            print(f'           Please export from PubMed using MEDLINE (.txt / .nbib) or RIS instead.')
            print(f'           This file has been skipped.')
            continue
        file_priority[bn] = len(file_priority)
        n_doi    = sum(1 for r in recs if r['norm_doi'])
        n_no_doi = len(recs) - n_doi
        print(f'    format : {fmt}')
        print(f'    records: {len(recs):,}  ({n_doi:,} with valid DOI | {n_no_doi:,} without DOI)')
        if len(recs) < 5:
            print(f'  WARNING: Only {len(recs)} record(s) could be read.')
            print(f'           This file may not be in a supported format.')
        all_records.extend(recs)

    for i, r in enumerate(all_records):
        r['uid'] = i

    n_total    = len(all_records)
    n_with_doi = sum(1 for r in all_records if r['norm_doi'])
    n_no_doi   = n_total - n_with_doi

    print(f'  {"─" * 67}')
    print(f'  Total loaded          : {n_total:>6,}')
    print(f'  With valid DOI        : {n_with_doi:>6,}')
    print(f'  Without DOI (kept)    : {n_no_doi:>6,}')

    # ── STEP 2 — DETECT DUPLICATES ────────────────────────────────────────────
    print()
    print('=' * 70)
    print('  STEP 2 — Detect duplicates  (DOI + normalised title compound key)')
    print('=' * 70)
    print('  Method : exact equality of (normalised DOI, normalised title)')
    print()

    dedup_groups   = defaultdict(list)
    no_title_uids  = []   # valid DOI but no usable title → kept as singletons
    for i, r in enumerate(all_records):
        if r['norm_doi']:
            nt = normalize_title(_get_raw_title(r))
            if not nt:
                no_title_uids.append(i)   # no title → singleton (per manuscript spec)
            else:
                dedup_groups[(r['norm_doi'], nt)].append(i)

    dup_groups  = {k: v for k, v in dedup_groups.items() if len(v) > 1}
    sing_groups = {k: v for k, v in dedup_groups.items() if len(v) == 1}
    no_doi_uids = [i for i, r in enumerate(all_records) if not r['norm_doi']] + no_title_uids

    doi_to_keys     = defaultdict(set)
    for (norm_doi, nt_key) in dedup_groups:
        doi_to_keys[norm_doi].add(nt_key)
    collision_dois  = {doi: keys for doi, keys in doi_to_keys.items() if len(keys) > 1}
    n_collision_records = sum(
        len(dedup_groups[(doi, nt_key)])
        for doi, keys in collision_dois.items()
        for nt_key in keys
    )
    n_rescued = n_collision_records - len(collision_dois)

    size_dist       = Counter(len(v) for v in dup_groups.values())
    n_in_dup_groups = sum(len(v) for v in dup_groups.values())

    print(f'  Duplicate clusters (>=2 references each) : {len(dup_groups):>6,}')
    for s in sorted(size_dist):
        print(f'    references in clusters of {s:<4}          : {s * size_dist[s]:>6,}')
    print(f'  References in duplicate clusters         : {n_in_dup_groups:>6,}')

    within_db = sum(
        1 for idxs in dup_groups.values()
        if len(set(all_records[i]['source_file'] for i in idxs)) == 1
    )
    cross_db = len(dup_groups) - within_db
    print(f'  Within-database duplicate clusters       : {within_db:>6,}')
    print(f'  Cross-database duplicate clusters        : {cross_db:>6,}')

    if collision_dois:
        print()
        print(f'  DOI collisions (same DOI, different titles): {len(collision_dois):>4,}')
        print(f'    Records in colliding DOI clusters        : {n_collision_records:>6,}')
        print(f'    Records rescued from false merging       : {n_rescued:>6,}')
        print(f'    (Showing up to 20 collisions)')
        for doi, keys in list(collision_dois.items())[:20]:
            print(f'    DOI: {doi}')
            for nt_key in sorted(keys):
                uids_here = dedup_groups[(doi, nt_key)]
                raw_t = _get_raw_title(all_records[uids_here[0]])[:80]
                print(f'      [{len(uids_here)} rec] {raw_t or "(no title)"}')
    else:
        print()
        print('  No DOI collisions detected.')

    cross_pair_counts = Counter()
    for idxs in dup_groups.values():
        srcs = sorted(set(all_records[i]['source_file'] for i in idxs))
        if len(srcs) > 1:
            for a in range(len(srcs)):
                for b in range(a + 1, len(srcs)):
                    cross_pair_counts[(srcs[a], srcs[b])] += 1

    if cross_pair_counts:
        print()
        print('  Cross-database overlap (groups shared between file pairs):')
        for (s1, s2), cnt in cross_pair_counts.most_common():
            lbl = f'{s1[:28]} <-> {s2[:28]}'
            print(f'    {lbl:<62} {cnt:>4,}')

    # ── STEP 3 — RESOLVE GROUPS ───────────────────────────────────────────────
    print()
    print('=' * 70)
    print('  STEP 3 — Resolve groups (keep best record per group)')
    print('=' * 70)
    print('  Priority: (1) has abstract  (2) alphabetical source file order  (3) longer abstract')

    def _score(rec):
        abs_text = ' '.join(rec['ris_fields'].get('AB', rec['ris_fields'].get('N2', [])))
        return (
            int(rec['has_abstract']),
            -file_priority.get(rec['source_file'], 999),
            len(abs_text),
        )

    def _title(r):
        return ' '.join(r['ris_fields'].get('TI', r['ris_fields'].get('T1', []))[:1])[:120]

    kept_uids     = set()
    excluded_rows = []
    n_abs_won     = 0

    for uid in no_doi_uids:
        kept_uids.add(uid)
    for idxs in sing_groups.values():
        kept_uids.add(idxs[0])

    for (norm_doi, nt_key), idxs in dup_groups.items():
        ranked = sorted([(i, all_records[i]) for i in idxs],
                        key=lambda x: _score(x[1]), reverse=True)
        wi, wr = ranked[0]
        kept_uids.add(wi)
        norm_title_col = nt_key
        for li, lr in ranked[1:]:
            if wr['has_abstract'] and not lr['has_abstract']:
                n_abs_won += 1
            excluded_rows.append({
                'excluded_uid':           li,
                'excluded_source_file':   lr['source_file'],
                'excluded_source_format': lr['source_format'],
                'excluded_raw_doi':       lr['raw_doi'],
                'excluded_norm_doi':      lr['norm_doi'],
                'excluded_norm_title':    norm_title_col,
                'excluded_has_abstract':  lr['has_abstract'],
                'excluded_title':         _title(lr),
                'kept_uid':               wi,
                'kept_source_file':       wr['source_file'],
                'kept_source_format':     wr['source_format'],
                'kept_raw_doi':           wr['raw_doi'],
                'kept_norm_doi':          wr['norm_doi'],
                'kept_norm_title':        norm_title_col,
                'kept_has_abstract':      wr['has_abstract'],
                'kept_title':             _title(wr),
            })

    n_excluded = len(excluded_rows)
    print(f'  Records excluded as duplicates           : {n_excluded:>6,}')
    print(f'    decided by abstract presence           : {n_abs_won:>6,}')
    print(f'    decided by source priority/length      : {n_excluded - n_abs_won:>6,}')

    # ── STEP 4 — WRITE OUTPUTS ────────────────────────────────────────────────
    print()
    print('=' * 70)
    print('  STEP 4 — Write outputs')
    print('=' * 70)

    # DOI-collision records sorted to top for easy manual review
    recs_out = [all_records[i] for i in sorted(
        kept_uids,
        key=lambda uid: (0 if all_records[uid]['norm_doi'] in collision_dois else 1, uid)
    )]

    # Deduplicated records — write in all requested formats
    _out_base = re.sub(r'\.ris$', '', OUTPUT_RIS, flags=re.IGNORECASE)
    _fmt_writers = {
        'ris':     (OUTPUT_RIS,              write_deduplicated_ris),
        'csv':     (_out_base + '.csv',      write_deduplicated_csv),
        'medline': (_out_base + '.txt',      write_deduplicated_medline),
        'xml':     (_out_base + '.xml',      write_deduplicated_xml),
    }
    for fmt in OUTPUT_FORMATS:
        if fmt not in _fmt_writers:
            print(f'  WARNING: unknown format {fmt!r}, skipping')
            continue
        fpath, writer = _fmt_writers[fmt]
        writer(recs_out, fpath)
        print(f'  {fpath:<60} {len(recs_out):>5,} records  [{fmt}]')

    # Excluded duplicates audit trail
    csv_cols = [
        'excluded_uid', 'excluded_source_file', 'excluded_source_format',
        'excluded_raw_doi', 'excluded_norm_doi', 'excluded_norm_title',
        'excluded_has_abstract', 'excluded_title',
        'kept_uid', 'kept_source_file', 'kept_source_format',
        'kept_raw_doi', 'kept_norm_doi', 'kept_norm_title',
        'kept_has_abstract', 'kept_title',
    ]
    with open(OUTPUT_CSV, 'w', newline='', encoding='utf-8') as fh:
        writer = csv.DictWriter(fh, fieldnames=csv_cols)
        writer.writeheader()
        writer.writerows(excluded_rows)
    print(f'  {OUTPUT_CSV:<60} {n_excluded:>5,} rows')

    # DOI collision log
    collision_rows = []
    for doi, keys in collision_dois.items():
        n_titles = len(keys)
        for nt_key in sorted(keys):
            for uid in dedup_groups[(doi, nt_key)]:
                r = all_records[uid]
                collision_rows.append({
                    'norm_doi':                doi,
                    'norm_title':             nt_key,
                    'raw_title':              _get_raw_title(r)[:200],
                    'source_file':            r['source_file'],
                    'source_format':          r['source_format'],
                    'n_titles_this_doi':      n_titles,
                    'in_deduplicated_output': uid in kept_uids,
                })
    collision_cols = [
        'norm_doi', 'norm_title', 'raw_title',
        'source_file', 'source_format', 'n_titles_this_doi',
        'in_deduplicated_output',
    ]
    with open(OUTPUT_COLLISIONS, 'w', newline='', encoding='utf-8') as fh:
        writer = csv.DictWriter(fh, fieldnames=collision_cols)
        writer.writeheader()
        writer.writerows(collision_rows)
    print(f'  {OUTPUT_COLLISIONS:<60} {len(collision_rows):>5,} rows')

    # Flowcharts
    _base = re.sub(r'\.ris$', '', OUTPUT_RIS, flags=re.IGNORECASE)
    write_simple_flowchart(n_total, n_excluded, _base + '_prisma_flowchart.html')
    write_complex_flowchart(n_total, n_excluded, _base + '_prisma_flowchart_extended.html')

    # ── SUMMARY ───────────────────────────────────────────────────────────────
    print()
    print('=' * 70)
    print('  SUMMARY')
    print('=' * 70)

    for fpath in input_files:
        bn  = fpath.name
        cnt = sum(1 for r in all_records if r['source_file'] == bn)
        if cnt:
            fmt_label = next(
                (r['source_format'] for r in all_records if r['source_file'] == bn), ''
            )
            print(f'  {bn:<55} {cnt:>5,}  [{fmt_label}]')

    print(f'  {"─" * 67}')
    print(f'  {"Total input records":<55} {n_total:>5,}')
    if collision_dois:
        print(f'  {"DOI collisions (records rescued from false merging)":<55} {n_rescued:>5,}')
    print(f'  {"Removed as duplicates":<55} {n_excluded:>5,}'
          f'  ({n_excluded / n_total * 100:.1f}%)')
    print(f'  {"Final deduplicated set":<55} {len(recs_out):>5,}')
    print('=' * 70)
    print('  Done.')
    for fmt in OUTPUT_FORMATS:
        fpath, _ = _fmt_writers[fmt]
        hints = {'ris': 'import into Rayyan, Covidence, Endnote, ...',
                 'csv': 'tabular records for spreadsheet review',
                 'medline': 'PubMed MEDLINE tagged export',
                 'xml': 'structured XML export'}
        print(f'    -> {fpath}')
        print(f'       ({hints.get(fmt, fmt)} format)')
    print(f'    -> {OUTPUT_CSV}')
    print(f'       (audit trail of excluded duplicates, with norm_title columns)')
    print(f'    -> {OUTPUT_COLLISIONS}')
    print(f'       (DOI collision log for manual review; includes in_deduplicated_output column)')


if __name__ == '__main__':
    main()
