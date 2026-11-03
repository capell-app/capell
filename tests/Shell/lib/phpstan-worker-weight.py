#!/usr/bin/env python3
"""Print the heavy weight a PHPStan configuration's worker cap calls for.

Usage: phpstan-worker-weight.py <config.neon>

Follows `includes` (an including file's parameters override the included ones) to the
effective `parallel.maximumNumberOfProcesses`. The heavy rule: up to 2 workers is weight 1,
up to 4 is weight 2, more or no cap (PHPStan then uses every core) is a full gate, weight 3.

A NEON comment after the value is ignored. A cap line this script cannot read raises (a non-zero exit)
rather than falling back to an inherited cap, so an unreadable override can never pass as a
lower weight.
"""
import re
import sys
from pathlib import Path

# Any other spelling (inline mapping, quoted key, parameter) is refused, never guessed.
INLINE_INCLUDES = re.compile(r'^\s*includes\s*:\s*\S')
CAP_LINE = re.compile(r'^\s*maximumNumberOfProcesses\s*:\s*(\d+)\s*$')


def strip_comment(line: str) -> str:
    """Drop a NEON comment: a # at the start or after whitespace (values here hold no quotes)."""
    return re.sub(r'(^|\s)#.*$', '', line)


def own_cap(config: Path, text: str) -> int | None:
    found = None
    for number, line in enumerate(text.splitlines(), 1):
        line = strip_comment(line)
        if 'maximumNumberOfProcesses' not in line:
            if INLINE_INCLUDES.match(line):
                raise ValueError(f'{config}:{number}: inline includes are not supported here; list them one per line')
            continue
        match = CAP_LINE.match(line)
        if match is None:
            raise ValueError(f'{config}:{number}: cannot read maximumNumberOfProcesses from {line.strip()!r}')
        found = int(match.group(1))  # The last occurrence wins, as in NEON.
    return found


def cap(config: Path, seen: set[Path]) -> int | None:
    config = config.resolve()
    if config in seen or not config.is_file():
        return None
    seen.add(config)
    text = config.read_text()
    own = own_cap(config, text)
    if own is not None:
        return own
    found = None
    block = re.search(r'^includes:\s*\n((?:[ \t]+-[^\n]*\n|[ \t]*#[^\n]*\n|[ \t]*\n)+)', text, re.MULTILINE)
    for entry in re.findall(r'^[ \t]+-[ \t]*(\S+)', block.group(1) if block else '', re.MULTILINE):
        if '%' in entry or entry.startswith('vendor/') or '/vendor/' in entry:
            continue  # Third-party extensions never set the worker cap.
        value = cap(config.parent / entry, seen)
        if value is not None:
            found = value  # Later includes override earlier ones.
    return found


if __name__ == '__main__':
    workers = cap(Path(sys.argv[1]), set())
    print(3 if workers is None or workers > 4 else 2 if workers > 2 else 1)
