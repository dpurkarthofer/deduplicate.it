# Changelog

All notable changes to **deduplicate.it** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Two version numbers appear in the source and should not be confused:

- the **release version** (`1.1.1`), which this file tracks;
- the **algorithm generation** (`v6`), which names the deduplication logic — the compound
  key of (normalised DOI, normalised title) and the title-normalisation pipeline. The
  algorithm generation changes only when deduplication decisions change, and it has not
  changed in this release.

---

## [1.1.1] — 2026-08-25

Archived on Zenodo (doi:10.5281/zenodo.22093548).

Documentation only. No change to deduplication behaviour, output files, or the algorithm
generation (still v6); results are byte-for-byte identical to 1.1.0.

The accompanying methodology paper has been accepted, so every place that carried a
"submitted for peer review" placeholder now carries the published citation:

> Purkarthofer, D., Labenbacher, S., Bornemann-Cimenti, H., & Landoni, G. (2026).
> *deduplicate.it: A simple open-source tool to remove duplicates from literature searches.*
> Campbell Systematic Reviews, 22(3). https://doi.org/10.1177/18911803261484937

### Changed

- **Web app methods text**: the paste-ready methods sentence now cites
  `(Purkarthofer et al., 2026)` and is followed by the full reference. Shown on both the
  landing page and the results page.
- **Web app FAQ** ("Where can I learn more?"): points at the published article instead of
  "citation to be added upon publication".
- **`CITATION.cff`**: `preferred-citation` moves from `status: submitted` to a complete
  journal reference with volume, issue and DOI. The article title also drops a stray
  "online" that was never in the published title.
- **`README.md`**: "Citing the method" gives the full reference.
- **CLI docstring**: gains a Citation block.

### Removed

- The pre-publication notice ("This tool has been submitted for peer review and is not yet
  formally published") from both pages of the web app.

---

## [1.1.0] — 2026-08-07

Archived on Zenodo (doi:10.5281/zenodo.21906819).

Adds native support for ERIC exports, recovers DOIs that databases publish only inside link
fields, and reconciles the command-line and web editions, which until now normalised titles
differently. The ERIC and DOI-recovery work changes no deduplication decision for any
previously supported format; the parity work can change results, as noted below.

### Added

- **`extract_doi_from_text()`** (Python and PHP): recovers a DOI embedded anywhere in a
  link or free-text field. Values are percent-decoded first, so both plain
  `http://dx.doi.org/10.1000/182` and a percent-encoded redirect target such as
  `?redir=http%3a%2f%2fdx.doi.org%2f10.1000%2f182` are found. Candidates must still pass
  full DOI structural validation, so non-DOI links — record pages, PDFs, publisher
  landing pages — yield nothing.
- **DOI recovery from link fields in every parser** where no DOI field is present:
  - RIS — `L3`, `UR`, `LK`, `M3`
  - MEDLINE — `AID`, `LID`, `UR`, `SO`
  - CSV/TSV — `url`, `link`, `links`, `doi url`, `fulltext url`, `article url`,
    `permalink`, `source url`
  - BibTeX — `url`, `howpublished`, `note`
  - Web of Science — `D2`, `OI`, `UR`
- **ERIC format detection.** ERIC exports MEDLINE-style `.nbib` but carries no `PMID`, so
  detection previously returned `unknown` and the file was parsed only by virtue of its
  file extension. `OWN - ERIC` and `OID - EJ…`/`OID - ED…` are now recognised, so ERIC
  files are identified from content regardless of how they are named.
- **ERIC field mapping**: `OT` → keywords, `OID` → accession number, `ISSN` → `SN`
  (with the `ISSN-`/`EISSN-` prefix stripped).
- Release version is now recorded in the source: `__version__` in the Python CLI,
  `DEDUP_VERSION` in the web application, and displayed in the web footer.

### Fixed — title normalisation parity between the two editions

Until this release the command-line edition and the web application normalised titles
differently, so the same input could yield slightly different results depending on which
was used. Both now produce **identical** normalised keys.

Five separate causes, three in the web application and two in the CLI:

- *(web)* **Non-breaking spaces survived normalisation.** Step 6 collapsed whitespace with
  `/\s+/`, which without the `u` modifier matches ASCII whitespace only. A title containing
  U+00A0 — common in Embase and CINAHL exports — therefore never matched the same title
  written with an ordinary space.
- *(web)* **Written-out trademark markers were not removed.** The symbol `™` was stripped
  but the literal forms `(TM)` and a trailing `TM` were not, so a product name marked
  `&trade;` in one database and `TM` in another did not match.
- *(web)* **Transliteration table was missing characters** that Unicode decomposition
  cannot reduce to an ASCII base: dotless `ı`, stroked `đ`/`Đ`, and subscript digits.
  Each was replaced with a space instead of its ASCII equivalent, so the letter was lost.
- *(CLI)* **Punctuation was deleted rather than replaced with a space**, contrary to step 5
  as documented in Supplement A: a hyphenated compound collapsed into a single word in the
  CLI but became two words in the web application.
- *(CLI)* **The line-break hyphen rule was too broad**, rejoining across any hyphen followed
  by whitespace rather than only across a real line break.

**Effect on results.** These fixes can detect additional genuine duplicates — pairs sharing
a DOI whose titles differed only by a non-breaking space or a trademark marker — so a set
deduplicated with an earlier version may yield a slightly smaller result when re-run. No
false merges are introduced: the compound key still requires the DOI to match, and these
fixes affect only the title side of pairs that already share one.

### Fixed

- **ERIC DOIs were discarded entirely.** ERIC writes an unmarked DOI URL into `AID`, but
  `_medline_to_rec` required the standard `[doi]` marker, and separately checked `LID`,
  which in ERIC records holds an unrelated `eric.ed.gov` record URL. Every ERIC DOI was
  therefore lost. `AID` is now tried first.
- **MEDLINE tags longer than two characters were skipped.** The parser tested for `'- '` at
  a fixed offset, which standard MEDLINE satisfies (`TI  - `, `PMID- `) but ERIC's
  `ISSN - ` does not, so ISSNs were silently dropped. Tags are now matched by pattern.
- **DOIs embedded mid-URL were missed.** The RIS fallback required the value to begin with
  `10.` or contain `doi.org/`; a full-text link of the form
  `example.org/doi/pdf/10.1000/182` satisfies neither. Such links are now searched, which
  mainly recovers conference-abstract DOIs.
- **ERIC publication years were malformed.** ERIC's `DP` can mix an article number into the
  date; the year is now extracted from it.
- CSV column aliases added for ERIC's own naming: `description` → abstract,
  `publicationdateyear` → year.

### Verification

The two editions were run over a common corpus of database exports and produce identical
normalised titles and identical output. `extract_doi_from_text()` passes the same unit
cases in both implementations, including negative cases that must not yield a DOI. Tested
on PHP 8.2.33 and 8.5.9.

### Note for maintainers

`index.php` uses **CRLF** line endings on the production server. Edit it with a tool that
preserves them, or the entire file appears changed in a diff. The bundled `.gitattributes`
pins this.

### Operational note — diagnostics

The web application contains an optional diagnostic mode, disabled by default and inert
unless a request carries an exact secret token (`DIAG_TOKEN`). **The token ships empty.**
An operator debugging a live installation sets it on the server, reproduces the problem,
reads the log at `?diag=<token>&showlog=1`, then clears the token and deletes the generated
log. The log is written to the system temporary directory, never inside the document root,
so it cannot be fetched as a static file; uploaded file names are stripped of line breaks
before being logged; and the token is reduced to `[A-Za-z0-9_-]` when forming the path.

Never commit a live token to a public repository: anyone reading the source could then
enable logging on the running site and read the log, which records uploaded file names and
sizes. Only file names, sizes and record counts are ever logged — no titles, DOIs or
abstracts.

### Known issues

- PubMed *Summary (text)* exports parse to zero records. This is correct behaviour — the
  format is an unstructured citation list carrying no field tags — but it is easy to
  select by mistake. Re-export in PubMed (MEDLINE) format.

---

## [1.0.0] — 2026-03-02

First public release, archived on Zenodo (doi:10.5281/zenodo.18835298).

### Added

- Deduplication on a compound key of (normalised DOI, normalised title).
- DOI normalisation per DOI Handbook §3.4–3.8 / ISO 26324: percent-decoding, prefix
  removal, trailing-punctuation trimming, Basic Latin case folding only, structural
  validation.
- Title normalisation pipeline v6: HTML entity decoding, trademark/copyright symbol
  removal, line-break hyphen rejoining, Unicode NFC, extended-Latin transliteration, NFD
  decomposition with combining-mark removal, Greek letter expansion, Unicode-aware
  lowercasing, punctuation replacement, whitespace collapsing.
- DOI collision detection: a DOI mapping to two or more distinct normalised titles is never
  merged; affected records are retained, logged to `doi_collisions.csv`, and sorted to the
  top of the output.
- Format auto-detection from file content for MEDLINE/NBIB, RIS, Web of Science tagged,
  BibTeX and CSV/TSV.
- Deterministic retention rule within a duplicate cluster: abstract present, then source
  file upload order, then abstract length.
- Outputs: deduplicated records (RIS, CSV, MEDLINE, XML), `excluded_duplicates.csv`,
  `doi_collisions.csv`, and prefilled PRISMA-style flowcharts (simple and extended).
- Command-line edition (Python, standard library only) and web application (PHP).
