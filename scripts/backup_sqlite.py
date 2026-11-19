"""Create and integrity-check a consistent SQLite backup using the online backup API."""
import argparse
from pathlib import Path
import sqlite3

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("database", type=Path)
parser.add_argument("destination", type=Path)
args = parser.parse_args()
source = args.database.resolve(strict=True)
destination = args.destination.resolve()
if destination.exists() or source == destination:
    parser.error("Choose a new destination; existing files will not be overwritten.")
destination.parent.mkdir(parents=True, exist_ok=True)
with sqlite3.connect(source.as_uri() + "?mode=ro", uri=True) as current:
    with sqlite3.connect(destination) as backup:
        current.backup(backup)
        result = backup.execute("PRAGMA integrity_check").fetchone()[0]
        if result != "ok":
            raise RuntimeError("Backup integrity check failed: " + result)
print("Verified backup:", destination)
