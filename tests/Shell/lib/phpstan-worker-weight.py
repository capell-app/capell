#!/usr/bin/env python3
"""Print the heavy weight a PHPStan configuration's worker cap calls for.

Usage: phpstan-worker-weight.py <config.neon>

Follows `includes` (an including file's parameters override the included ones) to the
effective `parallel.maximumNumberOfProcesses`. The heavy rule: up to 2 workers is weight 1,
up to 4 is weight 2, more or no cap (PHPStan then uses every core) is a full gate, weight 3.

This is deliberately not a NEON parser. It accepts one strict layout and raises on anything
else, so an unfamiliar spelling fails the check loudly instead of passing as a lower weight:

- the cap is `maximumNumberOfProcesses: <digits>` on its own line (a trailing # comment is fine);
- `includes:` is a plain header at the start of a line, followed by one path per line, plain or
  quoted;
- an include path is local (relative to the including file), or third-party under vendor/ or
  %rootDir%, which never sets the worker cap;
- every local include exists, and no file is reached twice in the include graph (PHPStan would
  merge it again, which this check does not model).
"""
import re
import sys
from pathlib import Path

CAP_LINE = re.compile(r'^\s*maximumNumberOfProcesses\s*:\s*(\d+)\s*$')
INCLUDES_HEADER = re.compile(r'^includes:\s*$')
INCLUDE_ENTRY = re.compile(r'^[ \t]+-[ \t]*(["\']?)([^\s"\']+)\1[ \t]*$')


def strip_comment(line: str) -> str:
    """Drop a NEON comment: a # at the start or after whitespace (values here hold no quotes)."""
    return re.sub(r'(^|\s)#.*$', '', line)


def read_config(config: Path) -> tuple[int | None, list[str]]:
    """The config's own cap and its include paths, refusing every layout outside the strict one."""
    cap = None
    includes: list[str] = []
    in_includes = False
    for number, raw in enumerate(config.read_text().splitlines(), 1):
        line = strip_comment(raw).rstrip()
        if not line.strip():
            continue
        where = f'{config}:{number}'
        if in_includes and line[:1] in (' ', '\t'):
            match = INCLUDE_ENTRY.match(line)
            if match is None:
                raise ValueError(f'{where}: cannot read the include {line.strip()!r}')
            includes.append(match.group(2))
            continue
        in_includes = False
        if 'includes' in line:
            if not INCLUDES_HEADER.match(line):
                raise ValueError(f'{where}: write includes as a plain "includes:" header, one path per line')
            in_includes = True
            continue
        if 'maximumNumberOfProcesses' in line:
            match = CAP_LINE.match(line)
            if match is None:
                raise ValueError(f'{where}: cannot read maximumNumberOfProcesses from {line.strip()!r}')
            cap = int(match.group(1))  # The last occurrence wins, as in NEON.
    return cap, includes


def third_party(entry: str) -> bool:
    return entry.startswith(('vendor/', '%rootDir%')) or '/vendor/' in entry


def local_includes(config: Path, entries: list[str]) -> list[Path]:
    paths = []
    for entry in entries:
        if third_party(entry):
            continue  # Third-party extensions never set the worker cap.
        if '%' in entry:
            raise ValueError(f'{config}: cannot resolve the parameterised include {entry!r}')
        path = (config.parent / entry).resolve()
        if not path.is_file():
            raise ValueError(f'{config}: the include {entry!r} does not exist')
        if path.suffix == '.neon':
            paths.append(path)  # An included PHP bootstrap file cannot set the cap.
    return paths


def resolve(config: Path, reached: set[Path]) -> int | None:
    """The effective cap of a configuration; `reached` collects every file in the include graph."""
    config = config.resolve()
    if config in reached:
        raise ValueError(f'{config} is reached twice in the include graph; include it once')
    if not config.is_file():
        raise ValueError(f'{config} does not exist')
    reached.add(config)
    own, entries = read_config(config)
    found = None
    for path in local_includes(config, entries):
        value = resolve(path, reached)
        if value is not None:
            found = value  # Later includes override earlier ones.
    # The config's own cap overrides its includes; they are still read so a broken one fails.
    return own if own is not None else found


def weight_for(workers: int | None) -> int:
    return 3 if workers is None or workers > 4 else 2 if workers > 2 else 1


if __name__ == '__main__':
    print(weight_for(resolve(Path(sys.argv[1]), set())))
