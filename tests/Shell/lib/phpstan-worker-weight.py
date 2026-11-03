#!/usr/bin/env python3
"""The heavy weight a PHPStan configuration's worker cap calls for.

Usage:
  phpstan-worker-weight.py <config.neon>   print the weight for that configuration
  phpstan-worker-weight.py                 run this reader's own regression cases

Follows `includes` (an including file's parameters override the included ones) to the
effective `parameters.parallel.maximumNumberOfProcesses`. The heavy rule: up to 2 workers is
weight 1, up to 4 is weight 2, more or no cap (PHPStan then uses every core) is a full gate,
weight 3.

This is deliberately not a NEON parser. It accepts one strict layout and raises on anything
else, so an unfamiliar spelling fails the check loudly instead of passing as a lower weight:

- keys are plain words; a quoted or escaped key raises;
- the cap is `maximumNumberOfProcesses: <digits>` directly under `parameters:` > `parallel:`
  (a trailing # comment is fine); the key anywhere else, or in any other form, raises;
- `includes:` is a plain top-level header followed by one plain or quoted path per line;
- an include resolves to a local .neon file, which must exist; a third-party file under a
  vendor/ directory, which must exist, and then no .neon or .php file anywhere in that vendor/
  tree (outside PHPStan's own package) may name the cap; or
  PHPStan's bundled %rootDir% or phar:// configuration; a local PHP include that names includes,
  parallel or the cap raises (one setting other parameters, such as bootstrapFiles, is read
  past), as does any other file type;
- no file is reached twice in the include graph (PHPStan would merge it again, which this
  reader does not model).

The threat is accidental drift between cooperating developers, not deliberately disguised
configuration: a PHP include that assembles the cap key from fragments would get past it.
"""
import re
import sys
import tempfile
from pathlib import Path

CAP_KEY = 'maximumNumberOfProcesses'
PLAIN_KEY = re.compile(r'^(\s*)([A-Za-z_][\w-]*)\s*:(.*)$')
QUOTED_KEY = re.compile(r'''^\s*(?:-\s*)?(["'])(?:\\.|(?!\1).)*\1\s*:''')
VENDOR_SCANNED: set[Path] = set()  # vendor/ trees already searched in this process
CAP_VALUE = re.compile(r'^\s*(\d+)\s*$')
INCLUDE_ENTRY = re.compile(r'^[ \t]+-[ \t]*(["\']?)([^\s"\']+)\1[ \t]*$')


def strip_comment(line: str) -> str:
    """Drop a NEON comment: a # at the start or after whitespace."""
    return re.sub(r'(^|\s)#.*$', '', line)


def read_config(config: Path) -> tuple[int | None, list[str]]:
    """The config's own cap and its include paths, refusing every layout outside the strict one."""
    cap = None
    includes: list[str] = []
    in_includes = False
    path: list[tuple[int, str]] = []  # (indent, key) of the enclosing plain keys
    for number, raw in enumerate(config.read_text().splitlines(), 1):
        line = strip_comment(raw).rstrip()
        if not line.strip():
            continue
        where = f'{config}:{number}'
        if '\\' in line and CAP_KEY.lower() in line.lower().replace('\\', ''):
            raise ValueError(f'{where}: an escaped spelling of {CAP_KEY} is not supported')
        if QUOTED_KEY.match(line):
            raise ValueError(f'{where}: write keys unquoted')
        if in_includes and line[:1] in (' ', '\t'):
            match = INCLUDE_ENTRY.match(line)
            if match is None:
                raise ValueError(f'{where}: cannot read the include {line.strip()!r}')
            includes.append(match.group(2))
            continue
        in_includes = False
        key = PLAIN_KEY.match(line)
        if key is not None:
            indent = len(key.group(1).expandtabs())
            while path and path[-1][0] >= indent:
                path.pop()
            name, value = key.group(2), key.group(3)
            if name == 'includes':
                if indent != 0 or value.strip():
                    raise ValueError(f'{where}: write includes as a plain top-level "includes:" header, one path per line')
                in_includes = True
                continue
            if name == CAP_KEY:
                if [key for _, key in path] != ['parameters', 'parallel']:
                    raise ValueError(f'{where}: {CAP_KEY} is only read directly under parameters: > parallel:')
                match = CAP_VALUE.match(value)
                if match is None:
                    raise ValueError(f'{where}: cannot read {CAP_KEY} from {line.strip()!r}')
                cap = int(match.group(1))  # The last occurrence wins, as in NEON.
                continue
            path.append((indent, name))
        if CAP_KEY.lower() in line.lower() or 'includes' in line:
            raise ValueError(f'{where}: cannot read {line.strip()!r}')
    return cap, includes


def is_third_party(resolved: Path) -> bool:
    return 'vendor' in resolved.parts


def check_third_party(config: Path, seen: set[Path]) -> None:
    """A third-party include must exist, and no installed third-party file may name the cap.

    Rather than follow every NEON and PHP include form a vendor configuration might use, the
    whole vendor/ tree containing it is searched once: if any .neon or .php file outside PHPStan's
    own package names the cap, the reader refuses.
    """
    config = config.resolve()
    if not config.is_file():
        raise ValueError(f'{config}: the third-party include does not exist; install dependencies first')
    vendor = config.parents[len(config.parts) - 1 - list(config.parts).index('vendor') - 1]
    if vendor in seen:
        return
    seen.add(vendor)
    for path in vendor.rglob('*'):
        if path.suffix.lower() not in ('.neon', '.php') or not path.is_file():
            continue
        if path.relative_to(vendor).parts[:2] == ('phpstan', 'phpstan'):
            continue  # PHPStan's own configuration schema names the cap.
        if CAP_KEY.encode().lower() in path.read_bytes().lower():
            raise ValueError(f'{path}: a third-party file names {CAP_KEY}, which this reader does not follow')


def local_includes(config: Path, entries: list[str], reached: set[Path]) -> list[Path]:
    """The local NEON includes to follow; local PHP includes read past are added to `reached`."""
    paths = []
    for entry in entries:
        if entry.startswith(('%rootDir%', 'phar:')):
            continue  # PHPStan's own bundled configuration never sets the cap.
        if '%' in entry:
            raise ValueError(f'{config}: cannot resolve the parameterised include {entry!r}')
        resolved = (config.parent / entry).resolve()
        if is_third_party(resolved):
            check_third_party(resolved, VENDOR_SCANNED)
            continue
        if not resolved.is_file():
            raise ValueError(f'{config}: the include {entry!r} does not exist')
        suffix = resolved.suffix.lower()
        if suffix == '.neon':
            paths.append(resolved)
        elif suffix == '.php':
            text = resolved.read_text().lower()
            if any(word in text for word in (CAP_KEY.lower(), 'parallel', 'includes')):
                raise ValueError(f'{config}: the PHP include {entry!r} may change the configuration, which this reader cannot read')
            reached.add(resolved)
        else:
            raise ValueError(f'{config}: cannot read the include {entry!r}')
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
    for path in local_includes(config, entries, reached):
        value = resolve(path, reached)
        if value is not None:
            found = value  # Later includes override earlier ones.
    # The config's own cap overrides its includes; they are still read so a broken one fails.
    return own if own is not None else found


def weight_for(workers: int | None) -> int:
    return 3 if workers is None or workers > 4 else 2 if workers > 2 else 1


def weight_of(config: Path) -> int:
    return weight_for(resolve(config, set()))


CAP4 = 'parameters:\n    parallel:\n        maximumNumberOfProcesses: 4\n'
CAP8 = 'parameters:\n    parallel:\n        maximumNumberOfProcesses: 8\n'
CASE_FILES = {
    'common.neon': CAP4,
    'eight.neon': CAP8,
    'commented.neon': 'includes:\n    - common.neon\nparameters:\n    parallel:\n        maximumNumberOfProcesses: 8 # raised\n',
    'inherited.neon': 'includes:\n    - common.neon # shared\n',
    'uncapped.neon': 'parameters:\n    level: 9\n',
    'quoted-include.neon': "includes:\n    - common.neon\n    - 'eight.neon'\n",
    'no-final-newline.neon': 'includes:\n    - common.neon\n    - eight.neon',
    'lowered.neon': 'includes:\n    - eight.neon\n' + CAP4,
    'vendor/ext.neon': 'parameters:\n    level: 9\n',
    'with-vendor.neon': 'includes:\n    - vendor/ext.neon\n    - common.neon\n',
    'bootstrap.php': '<?php return [];\n',
    'with-php.neon': 'includes:\n    - common.neon\n    - bootstrap.php\n',
    'high.php': "<?php return ['parameters' => ['parallel' => ['maximumNumberOfProcesses' => 8]]];\n",
    'high.NEON': CAP8,
    'capped/vendor/high-ext.neon': 'includes:\n    - nested.neon # commented\n',
    'capped/vendor/nested.php': "<?php return ['parameters' => ['parallel' => ['maximumNumberOfProcesses' => 8]]];\n",
    'php-includes.php': "<?php return ['includes' => ['eight.neon']];\n",
    'php-bootstrap.php': "<?php return ['parameters' => ['bootstrapFiles' => [__FILE__]]];\n",
    'unreadable.neon': 'includes:\n    - common.neon\nparameters:\n    parallel:\n        maximumNumberOfProcesses: %env.WORKERS%\n',
    'inline.neon': 'includes:\n    - common.neon\nparameters:\n    parallel: {maximumNumberOfProcesses: 8}\n',
    'quoted.neon': "includes:\n    - common.neon\nparameters:\n    parallel:\n        'maximumNumberOfProcesses': 8\n",
    'escaped.neon': 'includes:\n    - common.neon\nparameters:\n    parallel:\n        "maximumNumberOf\\u0050rocesses": 8\n',
    'nested-elsewhere.neon': 'includes:\n    - eight.neon\nparameters:\n    custom:\n        maximumNumberOfProcesses: 4\n',
    'inline-includes.neon': 'includes: [common.neon]\n',
    'quoted-header.neon': "'includes':\n    - eight.neon\n",
    'missing-include.neon': 'includes:\n    - common.neon\n    - missing.neon\n',
    'bad-include.neon': 'includes:\n    - common.neon\n    - "unbalanced.neon\n',
    'own-cap-missing-include.neon': 'includes:\n    - missing.neon\n' + CAP4,
    'parameterised-include.neon': 'includes:\n    - %currentWorkingDirectory%/eight.neon\n',
    'diamond.neon': 'includes:\n    - lowered.neon\n    - eight.neon\n',
    'php-cap.neon': 'includes:\n    - common.neon\n    - high.php\n',
    'upper-extension.neon': 'includes:\n    - common.neon\n    - high.NEON\n',
    'vendor-traversal.neon': 'includes:\n    - common.neon\n    - vendor/../eight.neon\n',
    'vendor-cap.neon': 'includes:\n    - common.neon\n    - capped/vendor/high-ext.neon\n',
    'php-includes.neon': 'includes:\n    - common.neon\n    - php-includes.php\n',
    'missing-vendor.neon': 'includes:\n    - common.neon\n    - vendor/absent.neon\n',
    'with-php-bootstrap.neon': 'includes:\n    - common.neon\n    - php-bootstrap.php\n',
}
EXPECTED_WEIGHTS = {
    'commented': 3, 'inherited': 2, 'uncapped': 3, 'quoted-include': 3, 'no-final-newline': 3,
    'with-vendor': 2, 'with-php': 2, 'vendor-traversal': 3, 'upper-extension': 3,
    'with-php-bootstrap': 2,
}
MUST_RAISE = (
    'unreadable', 'inline', 'quoted', 'escaped', 'nested-elsewhere', 'inline-includes', 'quoted-header',
    'missing-include', 'bad-include', 'own-cap-missing-include', 'parameterised-include', 'diamond',
    'php-cap', 'vendor-cap', 'php-includes', 'missing-vendor',
)


def self_test() -> None:
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        for name, text in CASE_FILES.items():
            (root / name).parent.mkdir(parents=True, exist_ok=True)
            (root / name).write_text(text)
        for name, weight in EXPECTED_WEIGHTS.items():
            actual = weight_of(root / f'{name}.neon')
            assert actual == weight, f'{name}.neon gave weight {actual}, not {weight}'
        for name in MUST_RAISE:
            try:
                weight_of(root / f'{name}.neon')
            except ValueError:
                continue
            raise AssertionError(f'{name}.neon did not fail closed')
    print(f'PASS: the PHPStan worker-cap reader: {len(EXPECTED_WEIGHTS)} weights, {len(MUST_RAISE)} refusals')


if __name__ == '__main__':
    if len(sys.argv) == 1:
        self_test()
    else:
        print(weight_of(Path(sys.argv[1])))
