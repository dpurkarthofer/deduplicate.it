#!/usr/bin/env python3
"""Thin shim: the implementation now lives in the ``deduplicate_it`` package.

Kept so that the path people have been told to use for years keeps working:

    python3 cli/literature_deduplication.py --source my-exports --outdir results

From a checkout this file adds the repository root to ``sys.path`` and runs
``deduplicate_it/core.py`` in place. If you installed from PyPI
(``pip install deduplicate-it``) you can call the ``deduplicate-it`` command
directly instead — it is the same code.

Note that this single file is no longer self-contained: it needs the
``deduplicate_it`` package beside it (or installed).
"""

import sys
from pathlib import Path

try:
    from deduplicate_it.core import main
except ImportError:                                  # plain checkout, not installed
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
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
