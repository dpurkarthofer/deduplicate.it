<?php
// ═══════════════════════════════════════════════════════════════════════════
// Literature Search Deduplication: Web Interface
// PHP port of literature_deduplication_v6 (Python / Jupyter notebook)
//
// Supported formats (auto-detected from file content):
//   MEDLINE .txt/.nbib · RIS .ris · Web of Science tagged .ciw/.txt
//   BibTeX .bib · CSV/TSV .csv/.tsv
//
// Deduplication logic:  exact equality of (normalised DOI, normalised title)
// DOI normalisation:    per DOI Handbook §3.4–3.8 / ISO 26324
// Title normalisation:  html-unescape → trademark strip → NFC → transliterate
//                       → NFD → strip combining marks → Greek expansion
//                       → lowercase → keep [a-z0-9 ] → collapse whitespace
// ═══════════════════════════════════════════════════════════════════════════

session_start();
@set_time_limit(120);
@ini_set('memory_limit', '256M');

// ── View handlers (inline, no download prompt) ───────────────────────────────
if (isset($_GET['view']) && !empty($_SESSION['dedup_token'])) {
    if (($_GET['tok'] ?? '') !== $_SESSION['dedup_token']) {
        http_response_code(403); exit;
    }
    if ($_GET['view'] === 'flowchart_complex' && !empty($_SESSION['dedup_flowchart_complex'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo $_SESSION['dedup_flowchart_complex'];
        exit;
    }
}

// ── Download handlers ────────────────────────────────────────────────────────
if (isset($_GET['download']) && !empty($_SESSION['dedup_token'])) {
    if (($_GET['tok'] ?? '') !== $_SESSION['dedup_token']) {
        http_response_code(403); exit;
    }
    if ($_GET['download'] === 'ris' && !empty($_SESSION['dedup_ris'])) {
        header('Content-Type: application/x-research-info-systems; charset=utf-8');
        header('Content-Disposition: attachment; filename="deduplicated.ris"');
        echo $_SESSION['dedup_ris'];
        exit;
    }
    if ($_GET['download'] === 'csv' && !empty($_SESSION['dedup_csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="excluded_duplicates.csv"');
        echo $_SESSION['dedup_csv'];
        exit;
    }
    if ($_GET['download'] === 'collisions' && !empty($_SESSION['dedup_collisions'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="doi_collisions.csv"');
        echo $_SESSION['dedup_collisions'];
        exit;
    }
    if ($_GET['download'] === 'flowchart' && !empty($_SESSION['dedup_flowchart'])) {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="prisma_flowchart.html"');
        echo $_SESSION['dedup_flowchart'];
        exit;
    }
    if ($_GET['download'] === 'flowchart_complex' && !empty($_SESSION['dedup_flowchart_complex'])) {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="prisma_flowchart_extended.html"');
        echo $_SESSION['dedup_flowchart_complex'];
        exit;
    }
    if ($_GET['download'] === 'dedupcsv' && !empty($_SESSION['dedup_dedupcsv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="deduplicated.csv"');
        echo $_SESSION['dedup_dedupcsv'];
        exit;
    }
    if ($_GET['download'] === 'medline' && !empty($_SESSION['dedup_medline'])) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="deduplicated.txt"');
        echo $_SESSION['dedup_medline'];
        exit;
    }
    if ($_GET['download'] === 'xml' && !empty($_SESSION['dedup_xml'])) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="deduplicated.xml"');
        echo $_SESSION['dedup_xml'];
        exit;
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// DOI NORMALISATION  (per DOI Handbook §3.4–3.8 / ISO 26324)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Produce a canonical DOI string for exact-equality duplicate detection.
 *
 * Steps:
 *  1. rawurldecode() (equivalent to Python's urllib.parse.unquote())
 *     Note: rawurldecode() does NOT convert '+' to space (correct per DOI spec).
 *  2. Strip urn:doi: prefix                              (§3.5.3)
 *  3. Strip doi: prefix                                  (§3.5.1/3.5.2)
 *  4. Strip https?://[dx.]doi.org/ prefix                (§3.5.4)
 *  5. Strip surrounding whitespace and trailing artefacts
 *  6. Basic Latin case fold ONLY (A–Z → a–z via strtr)  (§3.4.4)
 *     strtr() is used explicitly (NOT strtolower()/mb_strtolower()) to
 *     ensure only U+0041–U+005A are folded, leaving all other codepoints
 *     unchanged as required by the DOI standard.
 *  7. Validate: must match ^10\.\d{4,}/; invalid strings return ''
 */
function normalize_doi(string $raw): string {
    if ($raw === '') return '';
    $s = rawurldecode(trim($raw));                                         // 1
    $s = preg_replace('/^urn\s*:\s*doi\s*:\s*/i', '', $s);               // 2
    $s = preg_replace('/^doi\s*:\s*/i', '', $s);                          // 3
    $s = preg_replace('/^https?:\/\/(?:dx\.)?doi\.org\//i', '', $s);     // 4
    $s = rtrim(trim($s), " \t.,;:");                                       // 5
    $s = strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
                    'abcdefghijklmnopqrstuvwxyz');                          // 6
    return preg_match('/^10\.\d{4,}\//', $s) ? $s : '';                   // 7
}


// ═══════════════════════════════════════════════════════════════════════════
// TITLE NORMALISATION  (v6 pipeline)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Produce a canonical title string for exact-equality duplicate detection.
 *
 * Pipeline:
 *  0.  HTML entity decode (handles &alpha; → α, &trade; → ™, etc.)
 *  0b. Strip trademark/copyright symbols (™ ® © ℠ ℗)
 *  0c. Rejoin line-break hyphens (word-\nword → wordword)
 *  1.  NFC normalisation (via PHP intl Normalizer if available)
 *  2.  Extended Latin transliteration (strtr; ä→a, æ→ae, ß→ss, etc.)
 *  3a. NFD decomposition
 *  3b. Strip Unicode combining diacritical marks (\p{Mn})
 *  3c. Greek letter expansion (α→alpha, β→beta, …)
 *  4.  Lowercase (mb_strtolower)
 *  5.  Remove all characters except [a-z0-9 ]
 *  6.  Collapse/trim whitespace
 */
function normalize_title(string $raw): string {
    if ($raw === '') return '';

    // Step 0: HTML entity decode
    $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Step 0b: strip trademark / copyright symbols
    $s = str_replace(['™', '®', '©', '℠', '℗'], '', $s);

    // Step 0c: rejoin line-break hyphenated words
    $s = preg_replace('/([A-Za-z])-\s*[\r\n]+\s*([A-Za-z])/', '$1$2', $s);

    // Step 1: NFC normalisation
    if (class_exists('Normalizer', false)) {
        $t = \Normalizer::normalize($s, \Normalizer::NFC);
        if ($t !== false) $s = $t;
    }

    // Step 2: Extended Latin transliteration
    static $translit = null;
    if ($translit === null) {
        $translit = [
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
            'Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
            'Ð'=>'D','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','Þ'=>'TH','ß'=>'ss',
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae',
            'ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ð'=>'d','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','þ'=>'th','ÿ'=>'y',
            'Ā'=>'A','ā'=>'a','Ē'=>'E','ē'=>'e','Ī'=>'I','ī'=>'i',
            'Ō'=>'O','ō'=>'o','Ū'=>'U','ū'=>'u',
            'Č'=>'C','č'=>'c','Š'=>'S','š'=>'s','Ž'=>'Z','ž'=>'z',
            'Ő'=>'O','ő'=>'o','Ű'=>'U','ű'=>'u',
            'Œ'=>'OE','œ'=>'oe','Ÿ'=>'Y',
            'Ĺ'=>'L','ĺ'=>'l','Ľ'=>'L','ľ'=>'l','Ł'=>'L','ł'=>'l',
            'Ń'=>'N','ń'=>'n','Ň'=>'N','ň'=>'n',
            'Ŕ'=>'R','ŕ'=>'r','Ř'=>'R','ř'=>'r',
            'Ś'=>'S','ś'=>'s','Ş'=>'S','ş'=>'s',
            'Ţ'=>'T','ţ'=>'t','Ť'=>'T','ť'=>'t',
            'Ů'=>'U','ů'=>'u',
            'Ŷ'=>'Y','ŷ'=>'y',
            'Ź'=>'Z','ź'=>'z','Ż'=>'Z','ż'=>'z',
        ];
    }
    $s = str_replace(array_keys($translit), array_values($translit), $s);

    // Step 3a: NFD decomposition
    if (class_exists('Normalizer', false)) {
        $t = \Normalizer::normalize($s, \Normalizer::NFD);
        if ($t !== false) $s = $t;
    }

    // Step 3b: strip combining diacritical marks (Unicode Mn category)
    $s = preg_replace('/\p{Mn}/u', '', $s);

    // Step 3c: Greek letter expansion
    static $greek = null;
    if ($greek === null) {
        $greek = [
            'α'=>'alpha','β'=>'beta','γ'=>'gamma','δ'=>'delta','ε'=>'epsilon',
            'ζ'=>'zeta','η'=>'eta','θ'=>'theta','ι'=>'iota','κ'=>'kappa',
            'λ'=>'lambda','μ'=>'mu','ν'=>'nu','ξ'=>'xi','ο'=>'omicron',
            'π'=>'pi','ρ'=>'rho','σ'=>'sigma','ς'=>'sigma','τ'=>'tau',
            'υ'=>'upsilon','φ'=>'phi','χ'=>'chi','ψ'=>'psi','ω'=>'omega',
            'Α'=>'alpha','Β'=>'beta','Γ'=>'gamma','Δ'=>'delta','Ε'=>'epsilon',
            'Ζ'=>'zeta','Η'=>'eta','Θ'=>'theta','Ι'=>'iota','Κ'=>'kappa',
            'Λ'=>'lambda','Μ'=>'mu','Ν'=>'nu','Ξ'=>'xi','Ο'=>'omicron',
            'Π'=>'pi','Ρ'=>'rho','Σ'=>'sigma','Τ'=>'tau','Υ'=>'upsilon',
            'Φ'=>'phi','Χ'=>'chi','Ψ'=>'psi','Ω'=>'omega',
        ];
    }
    $s = str_replace(array_keys($greek), array_values($greek), $s);

    // Step 4: lowercase
    $s = mb_strtolower($s, 'UTF-8');

    // Step 5: keep only a-z, 0-9, whitespace
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);

    // Step 6: collapse whitespace
    return trim(preg_replace('/\s+/', ' ', $s));
}


// ═══════════════════════════════════════════════════════════════════════════
// TITLE EXTRACTION HELPER
// ═══════════════════════════════════════════════════════════════════════════

function get_raw_title(array $rec): string {
    $vals = $rec['ris_fields']['TI'] ?? ($rec['ris_fields']['T1'] ?? []);
    return $vals[0] ?? '';
}


// ═══════════════════════════════════════════════════════════════════════════
// FORMAT DETECTION  (sniffs first 4 KB of file content)
// ═══════════════════════════════════════════════════════════════════════════

function detect_format(string $content): string {
    $sample = ltrim(substr($content, 0, 4096), "\xef\xbb\xbf\xfe\xff");

    if (preg_match('/^TY\s{1,2}-\s/m', $sample))                return 'ris';
    if (preg_match('/^PMID-\s/m', $sample))                      return 'medline';

    $n_num = preg_match_all('/^\d+:\s+[A-Z]/m', $sample);
    $has_doi = (bool) preg_match('/\bdoi:\s*10\./i', $sample);
    if ($n_num >= 2 && $has_doi)                                  return 'pubmed_summary';

    if (preg_match('/^FN\s+(?:Clarivate|Web of Science|Thomson|ISI)/mi', $sample)) return 'wos';
    if (preg_match('/^PT\s+[A-Z]/m', $sample) &&
        preg_match('/^UT\s+WOS:/m', $sample))                    return 'wos';

    if (preg_match('/^\s*@\w+\s*[\{\(]/m', $sample))             return 'bibtex';

    $first = strtok($sample, "\n");
    if (preg_match('/\bDOI\b/i', $first) &&
        (strpos($first, ',') !== false || strpos($first, "\t") !== false)) return 'csv';

    return 'unknown';
}


// ═══════════════════════════════════════════════════════════════════════════
// UNIFIED RECORD CONSTRUCTOR
// ═══════════════════════════════════════════════════════════════════════════

function make_rec(string $raw_doi, bool $has_abstract, array $ris_fields,
                  string $source_file, string $fmt): array {
    return [
        'source_file'   => $source_file,
        'source_format' => $fmt,
        'raw_doi'       => $raw_doi,
        'norm_doi'      => normalize_doi($raw_doi),
        'has_abstract'  => $has_abstract,
        'ris_fields'    => $ris_fields,
        'uid'           => null,
        // raw_title and norm_title are added in run_deduplication() after parsing
    ];
}


// ═══════════════════════════════════════════════════════════════════════════
// PARSER:MEDLINE / PubMed NBIB  (.txt, .nbib)
// Tagged format: 4-char tag + "- " + value; 6-space continuation lines.
// ═══════════════════════════════════════════════════════════════════════════

function parse_medline(string $content, string $filename): array {
    $records = [];
    $raw     = [];
    $last    = null;

    foreach (explode("\n", $content) as $line) {
        $line = rtrim($line, "\r\n");
        if (trim($line) === '') {
            if ($raw) { $records[] = _medline_to_rec($raw, $filename); $raw = []; $last = null; }
            continue;
        }
        if (strlen($line) >= 6 && substr($line, 4, 2) === '- ') {
            $last = trim(substr($line, 0, 4));
            $raw[$last][] = substr($line, 6);
        } elseif (substr($line, 0, 6) === '      ' && $last !== null) {
            $raw[$last][count($raw[$last]) - 1] .= ' ' . trim($line);
        }
    }
    if ($raw) $records[] = _medline_to_rec($raw, $filename);
    return $records;
}

function _medline_to_rec(array $raw, string $filename): array {
    $raw_doi = '';
    foreach (['LID', 'AID'] as $tag) {
        foreach ($raw[$tag] ?? [] as $v) {
            if (stripos($v, '[doi]') !== false) {
                $raw_doi = trim(preg_replace('/\s*\[doi\].*/i', '', $v));
                break 2;
            }
        }
    }
    $pt  = $raw['PT'][0] ?? 'Journal Article';
    $ris = ['TY' => [_ml_pt_to_ris($pt)]];

    foreach (['TI'=>'TI','AB'=>'AB','JT'=>'JO','TA'=>'J2',
              'VI'=>'VL','IP'=>'IS','DP'=>'PY','PMID'=>'AN','SN'=>'SN'] as $ml => $rs) {
        if (isset($raw[$ml])) $ris[$rs] = $raw[$ml];
    }
    if (isset($raw['PG'])) {
        $pg = preg_split('/\s*-\s*/', $raw['PG'][0], 2);
        $ris['SP'] = [trim($pg[0])];
        if (isset($pg[1])) $ris['EP'] = [trim($pg[1])];
    }
    $ris['AU'] = $raw['FAU'] ?? ($raw['AU'] ?? []);
    if (isset($raw['MH'])) $ris['KW'] = $raw['MH'];
    if ($raw_doi) $ris['DO'] = [$raw_doi];
    if (isset($raw['PMID'])) $ris['UR'] = ['https://pubmed.ncbi.nlm.nih.gov/' . trim($raw['PMID'][0]) . '/'];

    $has_abstract = !empty($raw['AB']) && trim(implode(' ', $raw['AB'])) !== '';
    return make_rec($raw_doi, $has_abstract, $ris, $filename, 'MEDLINE');
}

function _ml_pt_to_ris(string $pt): string {
    $l = strtolower($pt);
    if (str_contains($l, 'review') || str_contains($l, 'journal') || str_contains($l, 'article')) return 'JOUR';
    if (str_contains($l, 'conference') || str_contains($l, 'congress')) return 'CONF';
    if (str_contains($l, 'book') || str_contains($l, 'chapter')) return 'CHAP';
    if (str_contains($l, 'report')) return 'RPRT';
    return 'JOUR';
}



// ═══════════════════════════════════════════════════════════════════════════
// PARSER:RIS (generic)
// Handles Embase/Ovid, Cochrane CENTRAL, CINAHL, Scopus RIS, WoS RIS, etc.
// ═══════════════════════════════════════════════════════════════════════════

function parse_ris(string $content, string $filename): array {
    $records = [];
    $fields  = [];

    foreach (explode("\n", $content) as $line) {
        $line = rtrim(ltrim($line, "\xef\xbb\xbf"), "\r\n");
        if (preg_match('/^ER\s*-?\s*$/', trim($line))) {
            if ($fields) { $records[] = _ris_to_rec($fields, $filename); $fields = []; }
            continue;
        }
        if (preg_match('/^([A-Z][A-Z0-9])\s{1,2}-\s?(.*)/', $line, $m)) {
            $fields[$m[1]][] = $m[2];
        }
    }
    if ($fields) $records[] = _ris_to_rec($fields, $filename);
    return $records;
}

function _ris_to_rec(array $fields, string $filename): array {
    // Primary: DO field
    $raw_doi = '';
    foreach ($fields['DO'] ?? [] as $v) {
        if (trim($v) !== '') { $raw_doi = trim($v); break; }
    }
    // Fallback: L3 or UR if it looks like a doi.org URL or bare DOI
    if ($raw_doi === '') {
        foreach (array_merge($fields['L3'] ?? [], $fields['UR'] ?? []) as $v) {
            if (stripos($v, 'doi.org/') !== false || preg_match('/^\s*10\.\d{4,}\//', $v)) {
                $raw_doi = trim($v); break;
            }
        }
    }
    if (!isset($fields['TY'])) $fields['TY'] = ['JOUR'];
    $ab = implode(' ', array_merge($fields['AB'] ?? [], $fields['N2'] ?? []));
    return make_rec($raw_doi, trim($ab) !== '', $fields, $filename, 'RIS');
}


// ═══════════════════════════════════════════════════════════════════════════
// PARSER:Web of Science ISI-tagged  (.txt, .ciw)
// 2-char tags; 3-space continuation lines; records end with "ER".
// ═══════════════════════════════════════════════════════════════════════════

function parse_wos(string $content, string $filename): array {
    $records = [];
    $raw     = [];
    $last    = null;

    foreach (explode("\n", $content) as $line) {
        $line    = rtrim($line, "\r\n");
        $stripped = trim($line);
        if ($stripped === '' || preg_match('/^(?:FN|VR)\s/', $line)) continue;
        if ($stripped === 'ER') {
            if ($raw) { $records[] = _wos_to_rec($raw, $filename); $raw = []; $last = null; }
            continue;
        }
        if (strlen($line) >= 3 && preg_match('/^[A-Z][A-Z0-9] /', $line)) {
            $last = substr($line, 0, 2);
            $val  = trim(substr($line, 3));
            if ($val !== '') $raw[$last][] = $val;
        } elseif (substr($line, 0, 3) === '   ' && $last !== null && !empty($raw[$last])) {
            $raw[$last][count($raw[$last]) - 1] .= ' ' . $stripped;
        }
    }
    if ($raw) $records[] = _wos_to_rec($raw, $filename);
    return $records;
}

function _wos_to_rec(array $raw, string $filename): array {
    $raw_doi = trim($raw['DI'][0] ?? '');
    $ris     = ['TY' => ['JOUR']];
    foreach (['TI'=>'TI','AB'=>'AB','SO'=>'JO','VL'=>'VL','IS'=>'IS',
              'BP'=>'SP','EP'=>'EP','SN'=>'SN','PY'=>'PY','UT'=>'AN','PU'=>'PB'] as $wt => $rt) {
        if (isset($raw[$wt][0])) $ris[$rt] = [$raw[$wt][0]];
    }
    $ris['AU'] = $raw['AF'] ?? ($raw['AU'] ?? []);
    $kw = [];
    foreach (['DE', 'ID'] as $kt) { if (isset($raw[$kt])) $kw = array_merge($kw, $raw[$kt]); }
    if ($kw) $ris['KW'] = $kw;
    if ($raw_doi) $ris['DO'] = [$raw_doi];
    $has_ab = !empty($raw['AB']) && trim(implode(' ', $raw['AB'])) !== '';
    return make_rec($raw_doi, $has_ab, $ris, $filename, 'WOS');
}


// ═══════════════════════════════════════════════════════════════════════════
// PARSER:BibTeX  (.bib)
// ═══════════════════════════════════════════════════════════════════════════

function parse_bibtex(string $content, string $filename): array {
    static $ty_map = [
        'article'=>'JOUR','inproceedings'=>'CONF','proceedings'=>'CONF',
        'book'=>'BOOK','incollection'=>'CHAP','misc'=>'GEN',
        'techreport'=>'RPRT','thesis'=>'THES','phdthesis'=>'THES',
        'mastersthesis'=>'THES','unpublished'=>'UNPB',
    ];
    $records = [];
    // Each entry stops at the next @ (same as Python's r'@(\w+)\s*[\{\(]([^@]*)')
    preg_match_all('/@(\w+)\s*[\{\(]([^@]*)/', $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $etype = strtolower($m[1]);
        if (in_array($etype, ['string','preamble','comment'], true)) continue;

        $fields = [];
        // field = {value} or field = "value", allowing one level of nested braces
        preg_match_all('/(\w+)\s*=\s*(?:\{((?:[^{}]|\{[^{}]*\})*)\}|"([^"]*)")/s',
                       $m[2], $fm, PREG_SET_ORDER);
        foreach ($fm as $f) {
            $fname = strtolower($f[1]);
            $fields[$fname] = trim($f[2] !== '' ? $f[2] : ($f[3] ?? ''));
        }
        if (!$fields) continue;

        $raw_doi = trim($fields['doi'] ?? '');
        $ris_ty  = $ty_map[$etype] ?? 'GEN';
        $ris     = ['TY' => [$ris_ty]];

        foreach (['title'=>'TI','abstract'=>'AB','journal'=>'JO','booktitle'=>'JO',
                  'volume'=>'VL','number'=>'IS','year'=>'PY','issn'=>'SN','isbn'=>'SN','url'=>'UR'] as $bf => $rt) {
            if (!empty($fields[$bf]) && !isset($ris[$rt])) $ris[$rt] = [$fields[$bf]];
        }
        if (!empty($fields['author'])) {
            $ris['AU'] = array_map('trim', preg_split('/\s+and\s+/i', $fields['author']));
        }
        if (!empty($fields['pages'])) {
            $pg = preg_split('/\s*-+\s*/', $fields['pages'], 2);
            $ris['SP'] = [trim($pg[0])];
            if (isset($pg[1])) $ris['EP'] = [trim($pg[1])];
        }
        if (!empty($fields['keywords'])) {
            $ris['KW'] = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $fields['keywords']))));
        }
        if ($raw_doi) $ris['DO'] = [$raw_doi];
        $has_ab = !empty($fields['abstract']) && trim($fields['abstract']) !== '';
        $records[] = make_rec($raw_doi, $has_ab, $ris, $filename, 'BibTeX');
    }
    return $records;
}


// ═══════════════════════════════════════════════════════════════════════════
// PARSER:CSV / TSV  (.csv, .tsv)
// Handles Scopus CSV, Web of Science CSV, any export with a 'DOI' column.
// ═══════════════════════════════════════════════════════════════════════════

function parse_csv_file(string $content, string $filename): array {
    $content = ltrim($content, "\xef\xbb\xbf");
    $first   = strtok($content, "\n");
    $sep     = (substr_count($first, "\t") > substr_count($first, ',')) ? "\t" : ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $content);
    rewind($fh);

    $headers = null;
    $records = [];
    while (($row = fgetcsv($fh, 0, $sep)) !== false) {
        if ($headers === null) {
            $headers = array_map(fn($h) => strtolower(trim((string)($h ?? ''))), $row);
            continue;
        }
        $col = [];
        foreach ($headers as $i => $h) $col[$h] = isset($row[$i]) ? trim((string)$row[$i]) : '';

        $first_nonempty = fn(array $keys) => (function() use ($col, $keys) {
            foreach ($keys as $k) if (!empty($col[$k])) return $col[$k];
            return '';
        })();

        $raw_doi  = $first_nonempty(['doi','doi link','digital object identifier']);
        $title    = $first_nonempty(['title','article title','document title']);
        $abstract = $first_nonempty(['abstract','author abstract']);
        $au_raw   = $first_nonempty(['authors','author','author full names']);
        $year_raw = $first_nonempty(['year','publication year','pub year']);
        $year     = $year_raw !== '' ? substr($year_raw, 0, 4) : '';

        $authors = array_values(array_filter(array_map('trim', preg_split('/\s*;\s*/', $au_raw))));

        $ris = ['TY' => ['JOUR']];
        if ($title)    $ris['TI'] = [$title];
        if ($abstract) $ris['AB'] = [$abstract];
        if ($authors)  $ris['AU'] = $authors;
        if ($year)     $ris['PY'] = [$year];

        foreach ([
            'JO' => ['source title','source','journal','publication name','journal title','publication title'],
            'VL' => ['volume'],
            'IS' => ['issue','number'],
            'SP' => ['page start','start page','art. no.'],
            'EP' => ['page end','end page'],
            'SN' => ['issn','isbn','eissn'],
            'AN' => ['eid','accession number','pubmed id','ut (unique wos id)','ut'],
        ] as $rt => $keys) {
            $v = $first_nonempty($keys);
            if ($v !== '') $ris[$rt] = [$v];
        }
        if ($raw_doi) $ris['DO'] = [$raw_doi];
        $records[] = make_rec($raw_doi, $abstract !== '', $ris, $filename, 'CSV');
    }
    fclose($fh);
    return $records;
}


// ═══════════════════════════════════════════════════════════════════════════
// FORMAT DISPATCHER
// ═══════════════════════════════════════════════════════════════════════════

function load_content(string $content, string $filename): array {
    $fmt = detect_format($content);
    if ($fmt === 'pubmed_summary') {
        return [[], 'PubMed Summary',
            'PubMed Summary format is not supported. Please export from PubMed using '
            . 'MEDLINE format (.txt or .nbib) or as RIS — both work correctly.'];
    }
    if ($fmt === 'unknown') {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $ext_map = ['txt'=>'medline','nbib'=>'medline','ris'=>'ris','enw'=>'ris',
                    'ciw'=>'wos','bib'=>'bibtex','csv'=>'csv','tsv'=>'csv'];
        $fmt = $ext_map[$ext] ?? 'ris';
    }
    $recs = match ($fmt) {
        'medline' => parse_medline($content, $filename),
        'ris'     => parse_ris($content, $filename),
        'wos'     => parse_wos($content, $filename),
        'bibtex'  => parse_bibtex($content, $filename),
        'csv'     => parse_csv_file($content, $filename),
        default   => parse_ris($content, $filename),
    };
    return [$recs, $fmt, null];
}


// ═══════════════════════════════════════════════════════════════════════════
// RIS WRITER
// ═══════════════════════════════════════════════════════════════════════════

const SINGLE_TAGS = ['TY','TI','T1','AB','N2','DO','VL','IS','SP','EP',
                     'PY','Y1','JO','JF','J2','SN','AN','UR','PB','CY'];
const TAG_ORDER   = ['T1','TI','AU','A1','PY','Y1','AB','N2','JO','JF',
                     'J2','VL','IS','SP','EP','SN','DO','UR','L2','AN',
                     'ID','KW','PT','DB'];

function to_ris(array $rec): string {
    $f    = $rec['ris_fields'];
    $ty   = $f['TY'][0] ?? 'JOUR';
    $lines   = ["TY  - $ty", "DB  - {$rec['source_file']}"];
    $written = ['TY' => true];

    foreach (TAG_ORDER as $tag) {
        if (!isset($f[$tag]) || isset($written[$tag])) continue;
        $vals = $f[$tag];
        if (in_array($tag, SINGLE_TAGS, true)) {
            $lines[] = "$tag  - " . ($vals[0] ?? '');
        } else {
            foreach ($vals as $v) $lines[] = "$tag  - $v";
        }
        $written[$tag] = true;
    }
    foreach ($f as $tag => $vals) {
        if (isset($written[$tag]) || $tag === 'TY' || $tag === 'ER') continue;
        if (in_array($tag, SINGLE_TAGS, true)) {
            $lines[] = "$tag  - " . ($vals[0] ?? '');
        } else {
            foreach ($vals as $v) $lines[] = "$tag  - $v";
        }
    }
    $lines[] = 'ER  -';
    return implode("\n", $lines);
}




// Fills the two (n = XXX) placeholders in a PRISMA template:
//   [1] total references in  →  n_total
//   [2] automatically removed →  n_excluded
function _fill_xxx(string $html, int $n_total, int $n_excluded): string {
    $nbsp = "\u{00A0}";
    $i = 0;
    return preg_replace_callback('/\(n = XXX\)/', function($m) use (&$i, $n_total, $n_excluded, $nbsp) {
        $val = $i++ === 0 ? $n_total : $n_excluded;
        return "(n{$nbsp}={$nbsp}" . number_format($val) . ")";
    }, $html);
}

function generate_simple_flowchart(int $n_total, int $n_excluded): string {
    foreach ([__DIR__, __DIR__ . '/..'] as $base) {
        $p = $base . '/FlowchartPRISMAsimple.drawio.html';
        if (file_exists($p)) return _fill_xxx(file_get_contents($p), $n_total, $n_excluded);
    }
    return '';
}

function generate_complex_flowchart(int $n_total, int $n_excluded): string {
    foreach ([__DIR__, __DIR__ . '/..'] as $base) {
        $p = $base . '/FlowchartPRISMAcomplex.drawio.html';
        if (file_exists($p)) return _fill_xxx(file_get_contents($p), $n_total, $n_excluded);
    }
    return '';
}


// ═══════════════════════════════════════════════════════════════════════════
// OUTPUT WRITERS FOR DEDUPLICATED RECORDS
// ═══════════════════════════════════════════════════════════════════════════

function recs_to_dedup_csv(array $recs): string {
    $cols = ['record_type','title','authors','year','journal','volume','issue',
             'start_page','end_page','doi','issn','abstract','keywords',
             'accession_number','url','source_file','source_format'];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $cols);
    foreach ($recs as $rec) {
        $f = $rec['ris_fields'];
        fputcsv($fh, [
            $f['TY'][0] ?? 'JOUR',
            $f['TI'][0] ?? ($f['T1'][0] ?? ''),
            implode('; ', $f['AU'] ?? ($f['A1'] ?? [])),
            $f['PY'][0] ?? ($f['Y1'][0] ?? ''),
            $f['JO'][0] ?? ($f['JF'][0] ?? ($f['J2'][0] ?? '')),
            $f['VL'][0] ?? '',
            $f['IS'][0] ?? '',
            $f['SP'][0] ?? '',
            $f['EP'][0] ?? '',
            $rec['raw_doi'],
            $f['SN'][0] ?? '',
            $f['AB'][0] ?? ($f['N2'][0] ?? ''),
            implode('; ', $f['KW'] ?? []),
            $f['AN'][0] ?? '',
            $f['UR'][0] ?? ($f['L2'][0] ?? ''),
            $rec['source_file'],
            $rec['source_format'],
        ]);
    }
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return $out;
}

function recs_to_medline_txt(array $recs): string {
    $blocks = [];
    foreach ($recs as $rec) {
        $f = $rec['ris_fields'];
        $b = [];

        $ti = $f['TI'][0] ?? ($f['T1'][0] ?? '');
        if ($ti !== '') $b[] = "TI  - $ti";

        foreach ($f['AU'] ?? ($f['A1'] ?? []) as $au) $b[] = "AU  - $au";

        $ab = $f['AB'][0] ?? ($f['N2'][0] ?? '');
        if ($ab !== '') $b[] = "AB  - $ab";

        $py = $f['PY'][0] ?? ($f['Y1'][0] ?? '');
        if ($py !== '') $b[] = "DP  - $py";

        $jo = $f['JO'][0] ?? ($f['JF'][0] ?? '');
        if ($jo !== '') $b[] = "JT  - $jo";

        $j2 = $f['J2'][0] ?? '';
        if ($j2 !== '') $b[] = "TA  - $j2";

        $vl = $f['VL'][0] ?? '';
        if ($vl !== '') $b[] = "VI  - $vl";

        $is = $f['IS'][0] ?? '';
        if ($is !== '') $b[] = "IP  - $is";

        $sp = $f['SP'][0] ?? '';
        $ep = $f['EP'][0] ?? '';
        if ($sp !== '') $b[] = "PG  - " . ($ep !== '' ? "$sp-$ep" : $sp);

        $sn = $f['SN'][0] ?? '';
        if ($sn !== '') $b[] = "SN  - $sn";

        foreach ($f['KW'] ?? [] as $kw) $b[] = "MH  - $kw";

        if ($rec['raw_doi'] !== '') $b[] = "AID - {$rec['raw_doi']} [doi]";

        $an = $f['AN'][0] ?? '';
        if ($an !== '') $b[] = "PMID- $an";

        $b[] = "SO  - {$rec['source_file']}";

        if ($b) $blocks[] = implode("\n", $b);
    }
    return implode("\n\n", $blocks) . "\n";
}

function recs_to_xml(array $recs): string {
    $doc  = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $root = $doc->createElement('references');
    $doc->appendChild($root);

    foreach ($recs as $rec) {
        $f   = $rec['ris_fields'];
        $ref = $doc->createElement('reference');
        $root->appendChild($ref);

        $add = function(string $tag, string $val) use ($doc, $ref) {
            if ($val === '') return;
            $el = $doc->createElement($tag);
            $el->appendChild($doc->createTextNode($val));
            $ref->appendChild($el);
        };

        $add('type',             $f['TY'][0] ?? 'JOUR');
        $add('title',            $f['TI'][0] ?? ($f['T1'][0] ?? ''));
        foreach ($f['AU'] ?? ($f['A1'] ?? []) as $au) $add('author', $au);
        $add('year',             $f['PY'][0] ?? ($f['Y1'][0] ?? ''));
        $add('journal',          $f['JO'][0] ?? ($f['JF'][0] ?? ($f['J2'][0] ?? '')));
        $add('volume',           $f['VL'][0] ?? '');
        $add('issue',            $f['IS'][0] ?? '');
        $add('start_page',       $f['SP'][0] ?? '');
        $add('end_page',         $f['EP'][0] ?? '');
        $add('doi',              $rec['raw_doi']);
        $add('issn',             $f['SN'][0] ?? '');
        $add('abstract',         $f['AB'][0] ?? ($f['N2'][0] ?? ''));
        foreach ($f['KW'] ?? [] as $kw) $add('keyword', $kw);
        $add('accession_number', $f['AN'][0] ?? '');
        $add('url',              $f['UR'][0] ?? ($f['L2'][0] ?? ''));
        $add('source_file',      $rec['source_file']);
        $add('source_format',    $rec['source_format']);
    }
    return $doc->saveXML() ?: '';
}


// ═══════════════════════════════════════════════════════════════════════════
// MAIN DEDUPLICATION  (v6 compound-key algorithm)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * @param array $uploaded_files  [['name'=>string, 'content'=>string], ...]
 *                               ordered by user upload order = tie-break priority
 * @return array  file_stats[], n_* counts, cross[], ris/csv/medline/xml output strings,
 *                simple and complex PRISMA flowchart HTML strings
 */
function run_deduplication(array $uploaded_files): array {
    // ── Step 1: Load, parse, and compute norm_title ──────────────────────────
    $all_records   = [];
    $file_priority = []; // source_file basename → int (lower = higher priority)
    $file_stats    = [];

    foreach ($uploaded_files as $idx => $file) {
        [$recs, $fmt, $warning] = load_content($file['content'], $file['name']);
        $file_priority[$file['name']] = $idx;

        // Add raw_title and norm_title to each record
        foreach ($recs as &$r) {
            $rt = get_raw_title($r);
            $r['raw_title']  = $rt;
            $r['norm_title'] = normalize_title($rt);
        }
        unset($r);

        $n = count($recs);
        if ($warning === null && $n < 5) {
            $warning = $n === 0
                ? 'No references could be read. The file may not be in a supported format.'
                : "Only {$n} reference(s) could be read. The file may not be in a supported format.";
        }

        $n_doi = count(array_filter($recs, fn($r) => $r['norm_doi'] !== ''));
        $file_stats[] = ['name'=>$file['name'], 'fmt'=>$fmt,
                         'total'=>$n, 'with_doi'=>$n_doi,
                         'no_doi'=>$n - $n_doi, 'warning'=>$warning];
        foreach ($recs as $r) $all_records[] = $r;
    }

    $n_total = count($all_records);
    for ($i = 0; $i < $n_total; $i++) $all_records[$i]['uid'] = $i;

    $n_with_doi = count(array_filter($all_records, fn($r) => $r['norm_doi'] !== ''));
    $n_no_doi   = $n_total - $n_with_doi;

    // ── Step 2: DOI collision detection ──────────────────────────────────────
    // A DOI collision = same norm_doi maps to ≥ 2 distinct norm_titles.
    // These are NOT merged (different titles likely = different papers sharing a DOI).
    $doi_title_index = []; // norm_doi → [norm_title → true]
    foreach ($all_records as $r) {
        if ($r['norm_doi'] !== '' && $r['norm_title'] !== '') {
            $doi_title_index[$r['norm_doi']][$r['norm_title']] = true;
        }
    }
    $collision_dois = []; // norm_dois that are collision DOIs
    foreach ($doi_title_index as $ndoi => $titles) {
        if (count($titles) > 1) $collision_dois[$ndoi] = true;
    }
    $n_collision_records = count(array_filter($all_records,
        fn($r) => $r['norm_doi'] !== '' && isset($collision_dois[$r['norm_doi']])));

    // ── Step 4: Compound-key grouping ────────────────────────────────────────
    // Records with the same (norm_doi, norm_title) are true duplicates.
    // Records with no title or no DOI are left as singletons (not grouped).
    $compound_index = []; // compound_key → [uids]
    $no_doi_uids    = []; // records ineligible for compound grouping

    foreach ($all_records as $r) {
        $uid = $r['uid'];
        if ($r['norm_doi'] === '' || $r['norm_title'] === '') {
            $no_doi_uids[] = $uid;
            continue;
        }
        $key = $r['norm_doi'] . '|||' . $r['norm_title'];
        $compound_index[$key][] = $uid;
    }

    // Separate true duplicates from singletons
    $dup_groups  = [];  // compound_key → [uids] (size ≥ 2)
    $single_uids = $no_doi_uids;
    foreach ($compound_index as $key => $uids) {
        if (count($uids) > 1) {
            $dup_groups[$key] = $uids;
        } else {
            $single_uids[] = $uids[0];
        }
    }

    $size_dist = [];
    foreach ($dup_groups as $uids) {
        $sz = count($uids);
        $size_dist[$sz] = ($size_dist[$sz] ?? 0) + 1;
    }
    ksort($size_dist);

    // ── Step 5: Resolve duplicate clusters ───────────────────────────────────
    // Score (descending): has_abstract, −file_priority, abstract_length
    $score_fn = function(array $rec) use ($file_priority): array {
        $ab  = implode(' ', array_merge($rec['ris_fields']['AB'] ?? [],
                                        $rec['ris_fields']['N2'] ?? []));
        $fp  = $file_priority[$rec['source_file']] ?? 999;
        return [(int)$rec['has_abstract'], -$fp, strlen($ab)];
    };

    $kept_uids     = $single_uids;
    $excluded_rows = [];

    foreach ($dup_groups as $uids) {
        $ranked = array_map(fn($i) => [$i, $all_records[$i]], $uids);
        usort($ranked, function($a, $b) use ($score_fn) {
            $sa = $score_fn($a[1]); $sb = $score_fn($b[1]);
            foreach ($sa as $k => $sv) {
                $diff = $sb[$k] <=> $sv; // descending
                if ($diff !== 0) return $diff;
            }
            return 0;
        });

        [$wi, $wr] = $ranked[0];
        $kept_uids[] = $wi;

        for ($j = 1, $n = count($ranked); $j < $n; $j++) {
            [$li, $lr] = $ranked[$j];
            $excluded_rows[] = [
                'excluded_uid'           => $li,
                'excluded_source_file'   => $lr['source_file'],
                'excluded_source_format' => $lr['source_format'],
                'excluded_raw_doi'       => $lr['raw_doi'],
                'excluded_norm_doi'      => $lr['norm_doi'],
                'excluded_norm_title'    => $lr['norm_title'],
                'excluded_has_abstract'  => $lr['has_abstract'] ? 'True' : 'False',
                'excluded_title'         => $lr['raw_title'],
                'kept_uid'               => $wi,
                'kept_source_file'       => $wr['source_file'],
                'kept_source_format'     => $wr['source_format'],
                'kept_raw_doi'           => $wr['raw_doi'],
                'kept_norm_doi'          => $wr['norm_doi'],
                'kept_norm_title'        => $wr['norm_title'],
                'kept_has_abstract'      => $wr['has_abstract'] ? 'True' : 'False',
                'kept_title'             => $wr['raw_title'],
            ];
        }
    }

    $n_excluded = count($excluded_rows);

    // ── Step 6: Sort kept_uids — DOI-collision records first ─────────────────
    // Collision records (rescued likely-duplicates) go to the top so reviewers
    // can immediately inspect these edge cases; then uid order is preserved.
    usort($kept_uids, function($a, $b) use ($all_records, $collision_dois) {
        $a_col = ($all_records[$a]['norm_doi'] !== '' &&
                  isset($collision_dois[$all_records[$a]['norm_doi']])) ? 0 : 1;
        $b_col = ($all_records[$b]['norm_doi'] !== '' &&
                  isset($collision_dois[$all_records[$b]['norm_doi']])) ? 0 : 1;
        if ($a_col !== $b_col) return $a_col - $b_col;
        return $a - $b;
    });

    // ── Step 7a: Build output record array and generate all formats ───────────
    $recs_out      = array_map(fn($i) => $all_records[$i], $kept_uids);
    $ris_output    = implode("\n\n", array_map('to_ris', $recs_out)) . "\n";
    $dedup_csv     = recs_to_dedup_csv($recs_out);
    $dedup_medline = recs_to_medline_txt($recs_out);
    $dedup_xml     = recs_to_xml($recs_out);

    // ── Step 7b: Generate excluded-duplicates CSV ─────────────────────────────
    $csv_cols = ['excluded_uid','excluded_source_file','excluded_source_format',
                 'excluded_raw_doi','excluded_norm_doi','excluded_norm_title',
                 'excluded_has_abstract','excluded_title',
                 'kept_uid','kept_source_file','kept_source_format',
                 'kept_raw_doi','kept_norm_doi','kept_norm_title',
                 'kept_has_abstract','kept_title'];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $csv_cols);
    foreach ($excluded_rows as $row) {
        $out = [];
        foreach ($csv_cols as $col) $out[] = (string)($row[$col] ?? '');
        fputcsv($fh, $out);
    }
    rewind($fh);
    $csv_output = stream_get_contents($fh);
    fclose($fh);

    // ── Step 7c: Generate DOI-collisions CSV ─────────────────────────────────
    $kept_set       = array_flip($kept_uids);
    $collision_rows = [];
    foreach ($all_records as $r) {
        if ($r['norm_doi'] !== '' && isset($collision_dois[$r['norm_doi']])) {
            $n_titles = count($doi_title_index[$r['norm_doi']] ?? []);
            $collision_rows[] = [
                'norm_doi'               => $r['norm_doi'],
                'norm_title'             => $r['norm_title'],
                'raw_title'              => $r['raw_title'],
                'source_file'            => $r['source_file'],
                'source_format'          => $r['source_format'],
                'n_titles_this_doi'      => $n_titles,
                'in_deduplicated_output' => isset($kept_set[$r['uid']]) ? 'True' : 'False',
            ];
        }
    }
    $col_cols = ['norm_doi','norm_title','raw_title','source_file','source_format',
                 'n_titles_this_doi','in_deduplicated_output'];
    $fh2 = fopen('php://temp', 'r+');
    fputcsv($fh2, $col_cols);
    foreach ($collision_rows as $row) {
        $out = [];
        foreach ($col_cols as $col) $out[] = (string)($row[$col] ?? '');
        fputcsv($fh2, $out);
    }
    rewind($fh2);
    $collisions_csv = stream_get_contents($fh2);
    fclose($fh2);

    // ── Step 7d: Generate flowchart HTML ──────────────────────────────────────
    $simple_flowchart  = generate_simple_flowchart($n_total, $n_excluded);
    $complex_flowchart = generate_complex_flowchart($n_total, $n_excluded);

    // ── Step 7e: Cross-database overlap table ──────────────────────────────────
    $cross = [];
    foreach ($dup_groups as $uids) {
        $srcs = array_unique(array_map(fn($i) => $all_records[$i]['source_file'], $uids));
        sort($srcs);
        if (count($srcs) > 1) {
            for ($a = 0, $na = count($srcs); $a < $na; $a++) {
                for ($b = $a + 1; $b < $na; $b++) {
                    $key = $srcs[$a] . ' ↔ ' . $srcs[$b];
                    $cross[$key] = ($cross[$key] ?? 0) + 1;
                }
            }
        }
    }
    arsort($cross);

    return [
        'file_stats'          => $file_stats,
        'n_total'             => $n_total,
        'n_with_doi'          => $n_with_doi,
        'n_no_doi'            => $n_no_doi,
        'n_collision_records' => $n_collision_records,
        'n_collision_dois'    => count($collision_dois),
        'n_dup_clusters'      => count($dup_groups),
        'size_dist'           => $size_dist,
        'n_excluded'          => $n_excluded,
        'n_kept'              => count($kept_uids),
        'cross'               => $cross,
        'ris_output'          => $ris_output,
        'dedup_csv'           => $dedup_csv,
        'dedup_medline'       => $dedup_medline,
        'dedup_xml'           => $dedup_xml,
        'csv_output'          => $csv_output,
        'collisions_csv'      => $collisions_csv,
        'simple_flowchart'    => $simple_flowchart,
        'complex_flowchart'   => $complex_flowchart,
    ];
}


// ═══════════════════════════════════════════════════════════════════════════
// REQUEST HANDLING
// ═══════════════════════════════════════════════════════════════════════════

$results = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploaded = [];
    if (!empty($_FILES['files']['name'][0])) {
        $names  = $_FILES['files']['name'];
        $tmps   = $_FILES['files']['tmp_name'];
        $errs   = $_FILES['files']['error'];

        for ($i = 0, $n = count($names); $i < $n; $i++) {
            if ($errs[$i] === UPLOAD_ERR_INI_SIZE || $errs[$i] === UPLOAD_ERR_FORM_SIZE) {
                $error = "File \"" . htmlspecialchars($names[$i]) . "\" exceeds the upload size limit. See .user.ini to increase it.";
                break;
            }
            if ($errs[$i] !== UPLOAD_ERR_OK) {
                $error = "Upload error for \"" . htmlspecialchars($names[$i]) . "\" (code {$errs[$i]}).";
                break;
            }
            $content = file_get_contents($tmps[$i]);
            if ($content === false) {
                $error = "Could not read \"" . htmlspecialchars($names[$i]) . "\".";
                break;
            }
            $uploaded[] = ['name' => $names[$i], 'content' => $content];
        }
    }

    if (!$error) {
        if (count($uploaded) === 0) {
            $error = 'Please select at least one file.';
        } else {
            try {
                $results = run_deduplication($uploaded);
                $tok = bin2hex(random_bytes(16));
                $_SESSION['dedup_token']     = $tok;
                $_SESSION['dedup_ris']       = $results['ris_output'];
                $_SESSION['dedup_dedupcsv']  = $results['dedup_csv'];
                $_SESSION['dedup_medline']   = $results['dedup_medline'];
                $_SESSION['dedup_xml']       = $results['dedup_xml'];
                $_SESSION['dedup_csv']       = $results['csv_output'];
                $_SESSION['dedup_collisions'] = $results['collisions_csv'];
                if ($results['simple_flowchart'] !== '') {
                    $_SESSION['dedup_flowchart'] = $results['simple_flowchart'];
                } else {
                    unset($_SESSION['dedup_flowchart']);
                }
                if ($results['complex_flowchart'] !== '') {
                    $_SESSION['dedup_flowchart_complex'] = $results['complex_flowchart'];
                } else {
                    unset($_SESSION['dedup_flowchart_complex']);
                }
                $results['tok'] = $tok;
            } catch (Throwable $e) {
                $error = 'Processing error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Helpers for HTML output
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt(int $n): string  { return number_format($n); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>deduplicate.it | Duplicate Removal and Literature Deduplication for Systematic Reviews</title>
<meta name="description" content="Free online tool for automated deduplication of literature search exports for systematic reviews, scoping reviews, and meta-analyses. Upload files from PubMed, Embase, Cochrane, Scopus, Web of Science and others to automatically remove duplicate references — ready for Rayyan, Covidence, or EndNote.">
<meta name="keywords" content="literature deduplication, duplicate removal, systematic review, scoping review, meta-analysis, remove duplicates, reference deduplication, PubMed, Embase, Cochrane, Scopus, Web of Science, RIS, DOI, Rayyan, Covidence">
<meta name="robots" content="index, follow">
<meta property="og:type" content="website">
<meta property="og:title" content="deduplicate.it | Duplicate Removal for Systematic Reviews">
<meta property="og:description" content="Free, auditable duplicate removal for literature searches in systematic reviews, scoping reviews, and meta-analyses. Upload exports from multiple databases, get a deduplicated RIS file with a full audit trail.">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f4ef;
     color:#1a1a1a;min-height:100vh;line-height:1.5}

.wrap{max-width:780px;margin:0 auto;padding:2.5rem 1.25rem}

header{margin-bottom:2rem}
header h1{font-size:1.55rem;font-weight:700;letter-spacing:-.02em}
header p{color:#555;font-size:.95rem;margin-top:.3rem}

.card{background:#fff;border-radius:14px;padding:1.75rem;
      margin-bottom:1.5rem;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.card h2{font-size:1rem;font-weight:700;margin-bottom:1.1rem}
.card h3{font-size:.9rem;font-weight:600;color:#444;margin-bottom:.75rem}

/* upload area */
.drop{border:2.5px dashed #ccc;border-radius:10px;padding:2.5rem 1.5rem;
      text-align:center;cursor:pointer;transition:.2s;position:relative}
.drop:hover,.drop.over{border-color:#2563eb;background:#eff6ff}
.drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;
                        width:100%;height:100%}
.drop-icon{font-size:2.2rem;margin-bottom:.6rem}
.drop h3{font-size:1rem;font-weight:600;margin-bottom:.25rem;color:#222}
.drop p{color:#888;font-size:.85rem}

#file-list{margin-top:.9rem}
.fi{display:flex;align-items:center;gap:.5rem;padding:.38rem 0;
    border-bottom:1px solid #f0f0ea;font-size:.85rem}
.fi:last-child{border-bottom:none}
.fi-name{flex:1;color:#333}
.fi-size{color:#aaa;font-size:.78rem}
.prio-note{font-size:.78rem;color:#999;margin-top:.6rem}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.4rem;
     border-radius:8px;font-size:.9rem;font-weight:600;cursor:pointer;
     border:none;text-decoration:none;transition:.15s}
.btn-primary{background:#2563eb;color:#fff}.btn-primary:hover{background:#1d4ed8}
.btn-outline{background:#f0f0ea;color:#333}.btn-outline:hover{background:#e4e4dc}
.btn-warn{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.btn-warn:hover{background:#fde68a}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-more{display:inline-flex;align-items:center;gap:.3rem;
          height:2rem;padding:0 .6rem;border-radius:6px;background:#f0f0ea;
          color:#666;font-size:.8rem;cursor:pointer;border:none;
          flex-shrink:0;transition:.15s;line-height:1}
.btn-more .more-lbl{font-size:.72rem;color:#888}
.btn-more:hover{background:#e4e4dc}

/* stats grid */
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
      gap:.9rem;margin:1.1rem 0}
.stat{background:#f8f8f3;border-radius:10px;padding:.9rem;text-align:center}
.stat-v{font-size:1.7rem;font-weight:700}
.stat-l{font-size:.75rem;color:#777;margin-top:.2rem}
.stat.blue{background:#eff6ff}.stat.blue .stat-v{color:#2563eb}
.stat.green{background:#f0fdf4}.stat.green .stat-v{color:#16a34a}
.stat.amber{background:#fffbeb}.stat.amber .stat-v{color:#d97706}

/* table */
table{width:100%;border-collapse:collapse;font-size:.85rem}
th{text-align:left;padding:.45rem .7rem;background:#f8f8f3;
   font-weight:600;color:#555;font-size:.75rem;text-transform:uppercase;letter-spacing:.03em}
td{padding:.45rem .7rem;border-bottom:1px solid #f4f4ef;color:#333}
tr:last-child td{border-bottom:none}

.dl-row{display:flex;gap:.9rem;flex-wrap:wrap;margin-top:1.25rem}

/* error / info */
.err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;
     padding:.9rem 1.1rem;color:#991b1b;font-size:.88rem;margin-bottom:1.25rem}
.info{font-size:.875rem;color:#555;line-height:1.65}

/* spinner */
#spinner{display:none;text-align:center;padding:2.5rem}
.spin{width:2rem;height:2rem;border:3px solid #e0e0e0;border-top-color:#2563eb;
      border-radius:50%;animation:sp .75s linear infinite;margin:0 auto .9rem}
@keyframes sp{to{transform:rotate(360deg)}}

footer{text-align:center;color:#bbb;font-size:.78rem;margin-top:2.5rem;line-height:1.7;max-width:680px;margin-left:auto;margin-right:auto}
a{color:#5b8de0}
.stat-section{font-size:.75rem;font-weight:600;color:#999;text-transform:uppercase;
              letter-spacing:.05em;margin-top:1.25rem;margin-bottom:.35rem}
.caveat{font-size:.8rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;
        border-radius:6px;padding:.65rem .9rem;margin-top:1.1rem;line-height:1.6}
.cite-box{background:#f8f8f3;border-left:3px solid #d1d1c8;border-radius:0 6px 6px 0;
          padding:.75rem 1rem;margin-top:.85rem;font-style:italic;font-size:.875rem;
          color:#444;line-height:1.7}
.collision-note{font-size:.8rem;color:#555;line-height:1.6;margin-top:.5rem}
</style>
</head>
<body>
<div class="wrap">

<header>
  <h1>deduplicate.it</h1>
  <p>Automated deduplication of literature searches for systematic reviews, scoping reviews, and meta-analyses. Upload files from multiple databases and receive a single deduplicated file.</p>
</header>

<?php if ($error): ?>
<div class="err">&#9888; <?= h($error) ?></div>
<?php endif; ?>

<?php if ($results): ?>
<!-- ── RESULTS ─────────────────────────────────────────────────────────── -->
<?php
  $tok             = h($results['tok']);
  $n_files         = count($results['file_stats']);
  $n_total         = $results['n_total'];
  $n_with_doi      = $results['n_with_doi'];
  $n_kept          = $results['n_kept'];
  $n_excl          = $results['n_excluded'];
  $n_coll_records  = $results['n_collision_records'];
  $n_coll_dois     = $results['n_collision_dois'];
  $pct             = $n_total > 0 ? round($n_excl / $n_total * 100, 1) : 0;
  $doi_pct         = $n_total > 0 ? round($n_with_doi / $n_total * 100, 1) : 0;
  $has_flowchart         = !empty($_SESSION['dedup_flowchart']);
  $has_flowchart_complex = !empty($_SESSION['dedup_flowchart_complex']);
?>
<div class="card">
  <h2>Results</h2>

  <p class="stat-section">Input overview</p>
  <div class="grid">
    <div class="stat"><div class="stat-v"><?= fmt($n_files) ?></div><div class="stat-l">Source files</div></div>
    <div class="stat"><div class="stat-v"><?= fmt($n_total) ?></div><div class="stat-l">Source references</div></div>
    <div class="stat"><div class="stat-v"><?= fmt($n_with_doi) ?></div><div class="stat-l">With valid DOI</div></div>
    <div class="stat"><div class="stat-v"><?= $doi_pct ?>%</div><div class="stat-l">Share with DOI</div></div>
  </div>

  <p class="stat-section">Deduplication output</p>
  <div class="grid">
    <div class="stat blue"><div class="stat-v"><?= fmt($n_kept) ?></div><div class="stat-l">Deduplicated output</div></div>
    <div class="stat green"><div class="stat-v"><?= fmt($n_excl) ?></div><div class="stat-l">Duplicates removed</div></div>
    <div class="stat"><div class="stat-v"><?= $pct ?>%</div><div class="stat-l">Reduction rate</div></div>
  </div>

  <p class="stat-section">Download</p>
  <div class="dl-row" style="align-items:center">
    <a href="?download=ris&tok=<?= $tok ?>" class="btn btn-primary">&#8595; Deduplicated output (.ris)</a>
    <button class="btn-more" id="fmt-toggle" onclick="toggleFmt()" title="Other formats" aria-expanded="false">&#9662;<span class="more-lbl">show more</span></button>
  </div>
  <div id="fmt-extra" style="display:none;margin-top:.5rem">
    <div class="dl-row">
      <a href="?download=dedupcsv&tok=<?= $tok ?>" class="btn btn-outline">&#8595; CSV</a>
      <a href="?download=medline&tok=<?= $tok ?>" class="btn btn-outline">&#8595; MEDLINE .txt</a>
      <a href="?download=xml&tok=<?= $tok ?>" class="btn btn-outline">&#8595; XML</a>
    </div>
  </div>

  <p class="stat-section" style="margin-top:1.1rem">Audit files</p>
  <div class="dl-row" style="align-items:center">
    <a href="?download=csv&tok=<?= $tok ?>" class="btn btn-outline">&#8595; Exclusion (.csv)</a>
    <?php if ($has_flowchart): ?>
    <a href="?download=flowchart&tok=<?= $tok ?>" class="btn btn-outline">&#8595; Flowchart (.html)</a>
    <?php if ($n_coll_records > 0 || $has_flowchart_complex): ?>
    <button class="btn-more" id="more-btn" onclick="toggleMore()" title="More downloads" aria-expanded="false">&#9662;<span class="more-lbl">show more</span></button>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($has_flowchart && ($n_coll_records > 0 || $has_flowchart_complex)): ?>
  <div id="more-dl" style="display:none;margin-top:.5rem" class="dl-row">
    <?php if ($n_coll_records > 0): ?>
    <a href="?download=collisions&tok=<?= $tok ?>" class="btn btn-outline">&#8595; DOI Collisions</a>
    <?php endif; ?>
    <?php if ($has_flowchart_complex): ?>
    <a href="?download=flowchart_complex&tok=<?= $tok ?>" class="btn btn-outline">&#8595; Extended Flowchart</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <p class="stat-section" style="margin-top:1.4rem">Methods text</p>
  <p class="caveat" style="margin-bottom:.6rem">&#9888; <strong>Submitted for peer review.</strong> This tool has been submitted for peer review and is not yet formally published. Use at your own discretion &mdash; the source code is transparently available at <a href="https://github.com/dpurkarthofer/deduplicate.it" target="_blank" rel="noopener">github.com/dpurkarthofer/deduplicate.it</a>.</p>
  <div class="cite-box">Automated deduplication of literature search results based on normalised
  digital object identifier (DOI) and title was performed using deduplicate.it
  (Citation to be added, currently submitted for peer review). Subsequently, all remaining references and the deduplication log were reviewed manually.</div>
  <p class="info" style="margin-top:.7rem;font-size:.8rem;color:#888">Deduplication is based on exact DOI and title matching. References without a valid DOI, or without an exactly matching title, pass through unchanged &mdash; this is by design. Manual review of both the deduplicated output and the exclusion file is required.</p>

<?php $has_file_warnings = !empty(array_filter($results['file_stats'], fn($fs) => !empty($fs['warning']))); ?>
<?php if ($has_file_warnings): ?>
  <p class="caveat" style="cursor:pointer;margin-top:.6rem" onclick="document.getElementById('file-details').scrollIntoView({behavior:'smooth'})">&#9888; <strong>File import problems detected.</strong> One or more source files may not have been read correctly and references may be missing. <span style="text-decoration:underline">Click to review &darr;</span></p>
<?php endif; ?>
</div>

<?php if ($has_flowchart): ?>
<div class="card">
  <h2>Flowchart</h2>
  <p class="info" style="margin-bottom:.9rem">
    Editable PRISMA-style flowchart for manuscript<?php if ($has_flowchart_complex): ?> &middot; <a href="?view=flowchart_complex&tok=<?= $tok ?>" target="_blank" rel="noopener">Extended version with other sources</a><?php endif; ?><br>
    To edit click on flowchart and use the edit button on the bottom
  </p>
  <iframe srcdoc="<?= htmlspecialchars($_SESSION['dedup_flowchart'], ENT_QUOTES, 'UTF-8') ?>"
          style="width:100%;aspect-ratio:827/1020;border:none;border-radius:6px"
          loading="lazy" title="PRISMA-style flowchart"></iframe>

</div>
<?php endif; ?>

<div class="card" style="color:#888">
  <h2 style="color:#aaa;font-weight:600">Search details</h2>

  <p class="stat-section" id="file-details">Input files</p>
  <table>
    <thead><tr><th>File</th><th>Format</th><th>References</th><th>With DOI</th></tr></thead>
    <tbody>
    <?php foreach ($results['file_stats'] as $fs): ?>
    <tr<?= !empty($fs['warning']) ? ' style="background:#fef9ec"' : '' ?>>
      <td><?= h($fs['name']) ?></td>
      <td><?= h($fs['fmt']) ?></td>
      <td><?= fmt($fs['total']) ?></td>
      <td><?= fmt($fs['with_doi']) ?></td>
    </tr>
    <?php if (!empty($fs['warning'])): ?>
    <tr style="background:#fef9ec">
      <td colspan="4" style="color:#92400e;font-size:.8rem;padding-top:0;border-top:none">&#9888; <?= h($fs['warning']) ?></td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($results['n_dup_clusters'] > 0): ?>
  <p class="stat-section" style="margin-top:1.25rem">Duplicate clusters</p>
  <table>
    <thead><tr><th>Cluster size</th><th>References</th></tr></thead>
    <tbody>
    <?php foreach ($results['size_dist'] as $sz => $cnt): ?>
    <tr><td><?= (int)$sz ?> references with identical DOI and normalised title</td><td><?= fmt((int)$sz * $cnt) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!empty($results['cross'])): ?>
  <h3 style="margin-top:1.25rem;color:#aaa;font-weight:600;font-size:.85rem">Cross-database overlap</h3>
  <table>
    <thead><tr><th>File pair</th><th>Shared references</th></tr></thead>
    <tbody>
    <?php foreach ($results['cross'] as $pair => $cnt): ?>
    <tr><td><?= h($pair) ?></td><td><?= fmt($cnt) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($n_coll_records > 0): ?>
  <p class="stat-section" style="margin-top:1.25rem">DOI collisions</p>
  <p style="font-size:.8rem;color:#aaa;margin-bottom:.4rem"><?= fmt($n_coll_dois) ?> collision DOI<?= $n_coll_dois !== 1 ? 's' : '' ?>, <?= fmt($n_coll_records) ?> references affected &mdash; sorted to the top of the output for manual review.</p>
  <p class="collision-note">A <strong>DOI collision</strong> occurs when the same DOI maps to references with different normalised titles. The most common cause is conference supplements: multiple abstracts published under a shared journal-issue DOI appear as though distinct papers carry the same identifier. Because these references most likely represent different papers, they are not merged.</p>
  <?php endif; ?>

  <p class="stat-section" style="margin-top:1.25rem">Frequently asked questions</p>

  <details style="margin-top:.6rem">
    <summary style="cursor:pointer;font-size:.875rem;font-weight:600;color:#555;user-select:none">How does it work? &rsaquo;</summary>
    <p class="info" style="margin-top:.85rem">
      References are matched by exact equality of <em>both</em> their <strong>normalised DOI</strong>
      and <strong>normalised title</strong>. DOI normalisation follows the
      <a href="https://www.doi.org/doi_handbook/" target="_blank">DOI Handbook</a>
      (ISO 26324 §3.4&ndash;3.8). Title normalisation applies a reproducible pipeline: HTML
      entity decoding, trademark removal, NFC, extended Latin transliteration
      (ä&rarr;a, æ&rarr;ae, ß&rarr;ss&hellip;), NFD with diacritic stripping, Greek expansion
      (&alpha;&rarr;alpha&hellip;), lowercase, and reduction to <code>[a-z0-9&nbsp;]</code>.<br><br>
      When several references share a DOI and normalised title, the one <strong>with an abstract</strong>
      is kept; otherwise the reference from the <strong>first uploaded file</strong> wins, with
      abstract length as the final tiebreaker.<br><br>
      When the same DOI maps to references with <em>different</em> normalised titles
      (<strong>DOI collision</strong> &mdash; common with conference abstract supplements),
      those references are <em>not merged</em> and appear at the top of the output for manual review.
    </p>
  </details>

  <details style="margin-top:.5rem">
    <summary style="cursor:pointer;font-size:.875rem;font-weight:600;color:#555;user-select:none">How can I edit my flowchart? &rsaquo;</summary>
    <p class="info" style="margin-top:.85rem">
      You can edit the flowchart directly in your browser by clicking on it and then selecting
      the <strong>pen icon</strong> in the context menu that appears at the bottom of the diagram.
      Alternatively, download the flowchart as an HTML file using the download button above and
      open it with the <a href="https://www.draw.io" target="_blank" rel="noopener">draw.io web app</a>
      or the draw.io desktop application.
    </p>
  </details>

  <details style="margin-top:.5rem">
    <summary style="cursor:pointer;font-size:.875rem;font-weight:600;color:#555;user-select:none">Where can I learn more? &rsaquo;</summary>
    <p class="info" style="margin-top:.85rem">
      The algorithm and validation are described in the accompanying methodology paper,
      currently submitted for peer review (citation to be added upon publication).
      The full source code is openly available for inspection and reuse at
      <a href="https://github.com/dpurkarthofer/deduplicate.it" target="_blank" rel="noopener">github.com/dpurkarthofer/deduplicate.it</a>.
    </p>
  </details>
</div>

<div class="card" style="text-align:center">
  <p class="info" style="margin-bottom:1rem">Run another deduplication?</p>
  <a href="?" class="btn btn-outline">&#8592; Upload new files</a>
</div>

<?php else: ?>
<!-- ── UPLOAD FORM ─────────────────────────────────────────────────────── -->
<div class="card" style="padding-top:1.1rem;padding-bottom:1.1rem">
  <h2 style="margin-bottom:.4rem">Upload files to remove duplicates</h2>

  <form method="post" enctype="multipart/form-data" id="frm">
    <div class="drop" id="drop">
      <input type="file" name="files[]" id="fi" multiple
             accept=".txt,.ris,.nbib,.ciw,.bib,.csv,.tsv,.enw">
      <div class="drop-icon">&#128194;</div>
      <h3>Drop files here or click to browse</h3>
      <p style="margin-top:.5rem;font-size:.75rem;color:#aaa">MEDLINE (.txt, .nbib) &middot; RIS (.ris) &middot; WoS (.ciw, .txt) &middot; BibTeX (.bib) &middot; CSV/TSV (.csv, .tsv)</p>
    </div>
    <div id="file-list"></div>
    <div style="margin-top:.7rem">
      <button type="submit" class="btn btn-primary" id="sub" disabled>Deduplicate</button>
    </div>
  </form>
  <div id="spinner"><div class="spin"></div><p>Processing, please wait&hellip;</p></div>
</div>

<div class="card">
  <h2>What you will receive</h2>
  <ul style="margin:.2rem 0 1rem 1.1rem;font-size:.875rem;color:#444;line-height:1.85">
    <li><strong>Deduplicated file</strong> &mdash; downloadable as RIS, CSV, MEDLINE, or XML</li>
    <li><strong>Exclusion log</strong> &mdash; all excluded references paired with the reference they were merged into</li>
    <li><strong>Flowchart</strong> &mdash; editable, pre-filled PRISMA-style flowchart</li>
  </ul>
  <p class="stat-section" style="margin-top:1.25rem">Methods text</p>
  <p class="caveat" style="margin-bottom:.6rem">&#9888; <strong>Submitted for peer review.</strong> This tool has been submitted for peer review and is not yet formally published. Use at your own discretion &mdash; the source code is transparently available at <a href="https://github.com/dpurkarthofer/deduplicate.it" target="_blank" rel="noopener">github.com/dpurkarthofer/deduplicate.it</a>.</p>
  <div class="cite-box">Automated deduplication of literature search results based on normalised
  digital object identifier (DOI) and title was performed using deduplicate.it
  (Citation to be added, currently submitted for peer review). Subsequently, all remaining references and the deduplication log were reviewed manually.</div>
  <p class="info" style="margin-top:.7rem;font-size:.8rem;color:#888">Deduplication is based on exact DOI and title matching. References without a valid DOI, or without an exactly matching title, pass through unchanged &mdash; this is by design. Manual review of both the deduplicated output and the exclusion file is required.</p>

  <p class="stat-section" style="margin-top:1.25rem">Frequently asked questions</p>

  <details style="margin-top:.6rem">
    <summary style="cursor:pointer;font-size:.875rem;font-weight:600;color:#555;user-select:none">How does it work? &rsaquo;</summary>
    <p class="info" style="margin-top:.85rem">
      References are matched by exact equality of <em>both</em> their <strong>normalised DOI</strong>
      and <strong>normalised title</strong>. DOI normalisation follows the
      <a href="https://www.doi.org/doi_handbook/" target="_blank">DOI Handbook</a>
      (ISO 26324 §3.4&ndash;3.8). Title normalisation applies a reproducible pipeline: HTML
      entity decoding, trademark removal, NFC, extended Latin transliteration
      (ä&rarr;a, æ&rarr;ae, ß&rarr;ss&hellip;), NFD with diacritic stripping, Greek expansion
      (&alpha;&rarr;alpha&hellip;), lowercase, and reduction to <code>[a-z0-9&nbsp;]</code>.<br><br>
      When several references share a DOI and normalised title, the one <strong>with an abstract</strong>
      is kept; otherwise the reference from the <strong>first uploaded file</strong> wins, with
      abstract length as the final tiebreaker.<br><br>
      When the same DOI maps to references with <em>different</em> normalised titles
      (<strong>DOI collision</strong> &mdash; common with conference abstract supplements),
      those references are <em>not merged</em> and appear at the top of the output for manual review.
    </p>
  </details>

  <details style="margin-top:.5rem">
    <summary style="cursor:pointer;font-size:.875rem;font-weight:600;color:#555;user-select:none">How can I edit my flowchart? &rsaquo;</summary>
    <p class="info" style="margin-top:.85rem">
      You can edit the flowchart directly in your browser by clicking on it and then selecting
      the <strong>pen icon</strong> in the context menu that appears at the bottom of the diagram.
      Alternatively, download the flowchart as an HTML file using the download button above and
      open it with the <a href="https://www.draw.io" target="_blank" rel="noopener">draw.io web app</a>
      or the draw.io desktop application.
    </p>
  </details>

  <details style="margin-top:.5rem">
    <summary style="cursor:pointer;font-size:.875rem;font-weight:600;color:#555;user-select:none">Where can I learn more? &rsaquo;</summary>
    <p class="info" style="margin-top:.85rem">
      The algorithm and validation are described in the accompanying methodology paper,
      currently submitted for peer review (citation to be added upon publication).
      The full source code is openly available for inspection and reuse at
      <a href="https://github.com/dpurkarthofer/deduplicate.it" target="_blank" rel="noopener">github.com/dpurkarthofer/deduplicate.it</a>.
    </p>
  </details>
</div>
<?php endif; ?>

<footer>
  Output quality is not guaranteed. Researchers are solely responsible for verifying the deduplication results,
  including review of the <a href="https://github.com/dpurkarthofer/deduplicate.it" target="_blank">open-access source code</a>
  and inspection of the Exclusion file, before proceeding to any further step in their research or publication.<br>
  <a href="legal.php" style="color:#d0d0c8">Legal &amp; privacy notice</a>
</footer>
</div>

<script>
const fi  = document.getElementById('fi');
const lst = document.getElementById('file-list');
const sub = document.getElementById('sub');
const drp = document.getElementById('drop');
const frm = document.getElementById('frm');
const spn = document.getElementById('spinner');

function render(files) {
  lst.innerHTML = '';
  if (!files || !files.length) { sub.disabled = true; return; }
  for (const f of files) {
    const sz = f.size < 1024 ? f.size + ' B'
             : f.size < 1048576 ? (f.size/1024).toFixed(1) + ' KB'
             : (f.size/1048576).toFixed(1) + ' MB';
    const d = document.createElement('div');
    d.className = 'fi';
    d.innerHTML = `<span class="fi-name">${f.name}</span><span class="fi-size">${sz}</span>`;
    lst.appendChild(d);
  }
  sub.disabled = false;
}

fi.addEventListener('change', () => render(fi.files));

function toggleMore() {
  const el  = document.getElementById('more-dl');
  const btn = document.getElementById('more-btn');
  if (!el || !btn) return;
  const open = el.style.display === 'none' || el.style.display === '';
  el.style.display = open ? 'flex' : 'none';
  btn.firstChild.nodeValue = open ? '\u25b4' : '\u25be';
  btn.querySelector('.more-lbl').textContent = open ? 'show less' : 'show more';
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

['dragenter','dragover'].forEach(e => drp.addEventListener(e, ev => {
  ev.preventDefault(); drp.classList.add('over');
}));
['dragleave','drop'].forEach(e => drp.addEventListener(e, ev => {
  ev.preventDefault(); drp.classList.remove('over');
}));
drp.addEventListener('drop', ev => {
  const dt = ev.dataTransfer;
  if (dt && dt.files.length) {
    try { const t = new DataTransfer(); for (const f of dt.files) t.items.add(f); fi.files = t.files; } catch(_) {}
    render(dt.files);
  }
});

frm.addEventListener('submit', () => {
  frm.style.display = 'none';
  spn.style.display = 'block';
});

function toggleFmt() {
  const el  = document.getElementById('fmt-extra');
  const btn = document.getElementById('fmt-toggle');
  if (!el || !btn) return;
  const open = el.style.display === 'none' || el.style.display === '';
  el.style.display = open ? 'block' : 'none';
  btn.firstChild.nodeValue = open ? '\u25b4' : '\u25be';
  btn.querySelector('.more-lbl').textContent = open ? 'show less' : 'show more';
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
</script>
</body>
</html>
