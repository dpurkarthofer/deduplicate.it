# deduplicate.it

Free, open-source tool for automated deduplication of literature search exports for systematic reviews, scoping reviews, and meta-analyses.

**Web interface:** [deduplicate.it](https://deduplicate.it)

> ⚠ Submitted for peer review. Not yet formally published. Use at your own discretion; source code is openly available for independent verification.

## Contents

### `web/` — PHP web application
Deploy to any PHP 8.x server. Place `index.php`, `legal.php`, and both `.drawio.html` flowchart templates in the same directory.

`.user.ini` raises the upload and memory limits the tool needs — PHP's default
`upload_max_filesize` of 2 MB is smaller than a typical database export. On shared hosting
running PHP-FPM, keep it beside `index.php`; on other setups apply the equivalent
directives in `php.ini`.

### `deduplicate_it/` — Python command-line tool
No external dependencies (Python standard library only). Requires Python 3.11 or newer.

**Install:**

```bash
pip install deduplicate-it
```

**Usage:**

```bash
deduplicate-it --source path/to/exports --outdir path/to/results
```

With no arguments it reads `./source` and writes to the current directory. Other options:
`--format` (repeatable: `ris`, `csv`, `medline`, `xml`; default `ris`), `--version`, `--help`.

Files are auto-detected by format; processed in alphabetical order (sets tie-break priority).

**Running from a clone instead of installing:** `python3 cli/literature_deduplication.py` still
works from a checkout — it is a thin wrapper around the same code. It needs the `deduplicate_it/`
package beside it, so download the repository (or a release archive), not that one file on its own.
Before 1.2.0 the script was self-contained; see the note in [CHANGELOG.md](CHANGELOG.md).

## Supported input formats

| Format | Extensions |
|--------|-----------|
| MEDLINE / PubMed NBIB | `.txt`, `.nbib` |
| RIS (Embase, Cochrane databases, CINAHL, Scopus, Web of Science) | `.ris`, `.enw` |
| Web of Science ISI-tagged | `.ciw` |
| BibTeX | `.bib` |
| CSV / TSV | `.csv`, `.tsv` |

Format is inferred from file content, not file extension. ERIC's MEDLINE-style `.nbib`
export is recognised from its own tags, so it is handled correctly whatever the file is
named.

## Output files

| File | Description |
|------|-------------|
| `deduplicated.ris` | Deduplicated references — import into Rayyan, Covidence, or EndNote |
| `deduplicated.csv` | Deduplicated references — tabular |
| `deduplicated.txt` | Deduplicated references — MEDLINE tagged text |
| `deduplicated.xml` | Deduplicated references — XML |
| `excluded_duplicates.csv` | Full audit trail: one row per excluded duplicate paired with the retained record |
| `doi_collisions.csv` | Records with the same DOI but different titles — retained in output, flagged for manual review |
| `deduplicated_prisma_flowchart.html` | Editable PRISMA-style flowchart, pre-filled with record counts |
| `deduplicated_prisma_flowchart_extended.html` | As above, with the per-database breakdown |

The web application offers all four deduplicated-reference formats for download. The
command-line tool writes RIS only by default; pass `--format` once per additional format, for
example `--format csv --format medline`.

## Algorithm

Deduplication uses a compound key of **normalised DOI** and **normalised title**. Records are excluded only when both fields agree after normalisation. Records without a valid DOI, or without a normalised title, pass through unchanged. DOI collisions (same DOI, different titles — common in conference supplement publications) are kept in the output and exported to a separate log for manual review.

Where a database publishes no DOI field but stores the DOI inside a link field — ERIC is the
common case — the DOI is recovered from that link. Candidates must still pass full
structural validation, so record pages, PDFs and other non-DOI URLs are ignored.

See the accompanying manuscript and Supplement A for a full step-by-step technical description.

## Version history

See [CHANGELOG.md](CHANGELOG.md). The **release version** (currently 1.2.0) and the
**algorithm generation** (`v6`) are tracked separately: the algorithm generation changes
only when deduplication decisions change. The web application and the Python package share
the release version, so 1.2.0 means the same algorithm in both.

## License

Copyright 2025 David Purkarthofer and Sebastian Labenbacher.
Apache License 2.0 — see [LICENSE](LICENSE).

## Citation

**Please cite the methods paper.** This is the citation to use when deduplicate.it
contributed to a review:

> Purkarthofer, D., Labenbacher, S., Bornemann-Cimenti, H., & Landoni, G. (2026).
> *deduplicate.it: A simple open-source tool to remove duplicates from literature searches.*
> Campbell Systematic Reviews, 22(3). https://doi.org/10.1177/18911803261484937

A `CITATION.cff` file is included, so GitHub's "Cite this repository" button returns the
reference above.

**Citing the software itself** is only needed when you have to point at a specific version
of the code — for a reproducibility statement, say:

> Purkarthofer D, Labenbacher S. *deduplicate.it: Automated deduplication of literature searches* [Software]. Zenodo. https://doi.org/10.5281/zenodo.18835297

That DOI covers all versions and always resolves to the most recent one. To pin the exact
version you used, take its version-specific DOI from the Zenodo record.
