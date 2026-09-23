#!/usr/bin/env python3
"""Thin shim: the implementation now lives in the ``deduplicate_it`` package.

Kept so that the path people have been told to use for years keeps working:

    python3 cli/literature_deduplication.py --source my-exports --outdir results

From a checkout this runs the checkout's own ``deduplicate_it/core.py`` — it puts
the repository root ahead of everything else on ``sys.path``, so a copy installed
from PyPI cannot shadow the code you are reading. That matters: verifying the tool
against the paper means running the source in front of you.

If there is no ``deduplicate_it/`` beside this file, an installed copy is used
instead (``pip install deduplicate-it`` provides the same code as the
``deduplicate-it`` command).

Note that this single file is not self-contained: before 1.2.0 it could be
downloaded on its own, and it no longer can. See CHANGELOG.md.
"""

import sys
from pathlib import Path

_ROOT = Path(__file__).resolve().parent.parent
if (_ROOT / 'deduplicate_it' / '__init__.py').is_file():
    sys.path.insert(0, str(_ROOT))

try:
    from deduplicate_it.core import main
except ImportError:
    sys.exit(
        'ERROR: the deduplicate_it package was not found.\n'
        '       Either run this script from a full checkout of the repository\n'
        '       (it expects deduplicate_it/ one level up from cli/), or install\n'
        '       the tool with:  pip install deduplicate-it'
    )

if __name__ == '__main__':
    main()
