# deduplicate.it

Free, open-source tool for automated deduplication of literature search exports for systematic reviews, scoping reviews, and meta-analyses.

**Web interface:** [deduplicate.it](https://deduplicate.it)

> ⚠ Submitted for peer review. Not yet formally published. Use at your own discretion; source code is openly available for independent verification.

## Contents

### `web/` — PHP web application
Deploy to any PHP 8.x server. Place `index.php`, `legal.php`, and both `.drawio.html` flowchart templates in the same directory.

### `cli/` — Python command-line script
No external dependencies (Python standard library only).

**Usage:**
1. Place all export files in a folder called `source/` next to `literature_deduplication.py`
2. Run: `python3 literature_deduplication.py`

Files are auto-detected by format; processed in alphabetical order (sets tie-break priority).

## Supported input formats

| Format | Extensions |
|--------|-----------|
| MEDLINE / PubMed NBIB | `.txt`, `.nbib` |
| RIS (Embase, Cochrane databases, CINAHL, Scopus, Web of Science) | `.ris`, `.enw` |
| Web of Science ISI-tagged | `.ciw` |
| BibTeX | `.bib` |
| CSV / TSV | `.csv`, `.tsv` |

Format is inferred from file content, not file extension.

## Output files

| File | Description |
|------|-------------|
| `deduplicated.ris` | Deduplicated references — import into Rayyan, Covidence, or EndNote |
| `deduplicated.csv` | Deduplicated references — tabular |
| `deduplicated.txt` | Deduplicated references — MEDLINE tagged text |
| `deduplicated.xml` | Deduplicated references — XML |
| `excluded_duplicates.csv` | Full audit trail: one row per excluded duplicate paired with the retained record |
| `doi_collisions.csv` | Records with the same DOI but different titles — retained in output, flagged for manual review |
| `*_prisma_flowchart.html` | Editable PRISMA-style flowchart, pre-filled with record counts |

## Algorithm

Deduplication uses a compound key of **normalised DOI** and **normalised title**. Records are excluded only when both fields agree after normalisation. Records without a valid DOI, or without a normalised title, pass through unchanged. DOI collisions (same DOI, different titles — common in conference supplement publications) are kept in the output and exported to a separate log for manual review.

See the accompanying manuscript and Supplement A for a full step-by-step technical description.

## License

Copyright 2025 David Purkarthofer and Sebastian Labenbacher.
Apache License 2.0 — see [LICENSE](LICENSE).

## Citation

**Citing the software** (this repository / Zenodo archive):

> Purkarthofer D, Labenbacher S. *deduplicate.it: Automated deduplication of literature searches* [Software]. Zenodo. https://doi.org/10.5281/zenodo.18835298

A `CITATION.cff` file is included for automatic citation support.

**Citing the method** (accompanying manuscript):

> (Citation to be added — currently submitted for peer review.)
