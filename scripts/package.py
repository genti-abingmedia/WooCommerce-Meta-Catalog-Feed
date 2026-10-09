#!/usr/bin/env python3
"""Build a deterministic WordPress ZIP containing only the installable plugin."""
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo
import re

root = Path(__file__).resolve().parent.parent
plugin = root / "woocommerce-meta-catalog-feed"
version = re.search(r"Version: ([0-9.]+)", (plugin / "woocommerce-meta-catalog-feed.php").read_text()).group(1)
output = root / "dist" / f"woocommerce-meta-catalog-feed-{version}.zip"
output.parent.mkdir(exist_ok=True)
with ZipFile(output, "w", ZIP_DEFLATED) as archive:
    for path in sorted(plugin.rglob("*")):
        if path.is_file():
            entry = ZipInfo(path.relative_to(root).as_posix(), date_time=(2026, 10, 9, 0, 0, 0))
            entry.compress_type = ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, path.read_bytes())
print(output)
