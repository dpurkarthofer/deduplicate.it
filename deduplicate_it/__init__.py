"""deduplicate.it — remove duplicate records from literature search exports.

Release 1.2.0 · algorithm generation v6.
See CHANGELOG.md for the release history.
"""

__version__ = "1.2.0"

from .core import main  # noqa: E402,F401

__all__ = ["main", "__version__"]
