#!/usr/bin/env python3
"""Print the heavy weight a PHPStan configuration's worker cap calls for.

Usage: phpstan-worker-weight.py <config.neon>

Follows `includes` (an including file's parameters override the included ones) to the
effective `parallel.maximumNumberOfProcesses`. The heavy rule: up to 2 workers is weight 1,
up to 4 is weight 2, more or no cap (PHPStan then uses every core) is a full gate, weight 3.
"""
import re
import sys
from pathlib import Path


def cap(config: Path, seen: set[Path]) -> int | None:
    config = config.resolve()
    if config in seen or not config.is_file():
        return None
    seen.add(config)
    text = config.read_text()
    own = re.search(r'^\s*maximumNumberOfProcesses:\s*(\d+)\s*$', text, re.MULTILINE)
    if own:
        return int(own.group(1))
    found = None
    block = re.search(r'^includes:\s*\n((?:[ \t]+-[^\n]*\n|[ \t]*#[^\n]*\n|[ \t]*\n)+)', text, re.MULTILINE)
    for entry in re.findall(r'^[ \t]+-[ \t]*(\S+)', block.group(1) if block else '', re.MULTILINE):
        if '%' in entry or entry.startswith('vendor/') or '/vendor/' in entry:
            continue  # Third-party extensions never set the worker cap.
        value = cap(config.parent / entry, seen)
        if value is not None:
            found = value  # Later includes override earlier ones.
    return found


workers = cap(Path(sys.argv[1]), set())
print(3 if workers is None or workers > 4 else 2 if workers > 2 else 1)
