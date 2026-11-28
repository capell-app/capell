#!/usr/bin/env python3
"""List the companion Packages tests that reach the Core classes this branch changes.

A Core change to a shared contract can break a companion package whose own tests
never run on a Core pull request. This traces each changed Core class into the
Packages checkout and lists every Packages test that names it, or names a Packages
class that reaches it through any chain of other Packages classes, or through one
Core class that calls it. Same-namespace references count, because PHP needs no
import for them. A service provider is not followed: it names what it registers,
and following it would list every test that boots the package.

The changed files come from Git: the commits since the merge base with origin/main
(or --base REF / CAPELL_PREMERGE_BASE), the index, the working tree and untracked
files that are not ignored. Pass --stdin to read paths instead, or name them as
arguments. The Packages checkout is --packages PATH, CAPELL_PACKAGES_REPO_PATH, or
capell-packages-4 beside the primary Core checkout. A missing Packages checkout or
merge base exits non-zero: "none" must mean no consumers, never "not checked".
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from collections import deque
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable, Sequence

CAPELL_NAME = re.compile(r'(?<![\w\\])\\{0,2}(Capell(?:\\{1,2}[A-Za-z_]\w*)+)')
NAMESPACE = re.compile(r'^namespace\s+([\w\\]+)\s*;', re.MULTILINE)
DECLARATION = re.compile(r'^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)', re.MULTILINE)
# Imports sit at column 0; an indented `use` inside a class body is a trait, not an import.
IMPORT = re.compile(r'^use\s+([^;{]+?)(?:\{([^}]*)\})?\s*;', re.MULTILINE)
IDENTIFIER = re.compile(r'(?<![\w\\$])([A-Z]\w*)(?![\w\\])')
PACKAGES_ROOTS = ('packages/', 'tests/')
PACKAGES_DIRECTORIES = ('src', 'database', 'routes', 'tests')
CORE_PREFIX = 'core:'
CORE_CALLER_DEPTH = 1
SUITE_COLLAPSE_AT = 10


@dataclass
class ReferenceIndex:
    """What each scanned file declares and which Capell classes it names."""

    declares: dict[str, list[str]] = field(default_factory=dict)
    references: dict[str, set[str]] = field(default_factory=dict)

    def referencing(self) -> dict[str, set[str]]:
        reverse: dict[str, set[str]] = {}
        for path, names in self.references.items():
            for name in names:
                reverse.setdefault(name, set()).add(path)
        return reverse


@dataclass
class Trace:
    consumers: dict[str, set[str]] = field(default_factory=dict)
    tests: dict[str, set[str]] = field(default_factory=dict)
    whole_suites: set[str] = field(default_factory=set)


def normalise(name: str) -> str:
    return re.sub(r'\\+', r'\\', name).strip('\\')


def imports(source: str) -> dict[str, str]:
    """Map each imported short name (or alias) to the class it names."""
    imported: dict[str, str] = {}
    for match in IMPORT.finditer(source):
        head, group = match.group(1).strip(), match.group(2)
        if head.startswith(('function ', 'const ')):
            continue
        entries = [f"{head}{item.strip()}" for item in group.split(',') if item.strip()] if group is not None else [head]
        for entry in entries:
            parts = re.split(r'\s+as\s+', entry, flags=re.IGNORECASE)
            name = normalise(parts[0])
            imported[parts[1].strip() if len(parts) > 1 else name.rsplit('\\', 1)[-1]] = name
    return imported


def references(source: str, known: set[str]) -> set[str]:
    """Capell classes a PHP file names: imported, fully qualified, in strings, or by short name in its own namespace."""
    found = {normalise(match.group(1)) for match in CAPELL_NAME.finditer(source)}
    namespace = NAMESPACE.search(source)
    if namespace:
        imported = imports(source)
        for identifier in set(IDENTIFIER.findall(source)) - imported.keys():
            candidate = f"{namespace.group(1)}\\{identifier}"
            if candidate in known:
                found.add(candidate)
    return found


def declarations(source: str) -> list[str]:
    namespace = NAMESPACE.search(source)
    prefix = f"{namespace.group(1)}\\" if namespace else ''
    return [prefix + name for name in DECLARATION.findall(source)]


def build_index(files: dict[str, str], extra_classes: Iterable[str] = ()) -> ReferenceIndex:
    index = ReferenceIndex()
    for path, source in files.items():
        declared = declarations(source)
        if declared:
            index.declares[path] = declared
    known = set(extra_classes) | {name for names in index.declares.values() for name in names}
    for path, source in files.items():
        index.references[path] = references(source, known) - set(index.declares.get(path, ()))
    return index


def is_core(path: str) -> bool:
    return path.startswith(CORE_PREFIX)


def is_test(path: str) -> bool:
    return path.endswith('Test.php')


def package_of(path: str) -> str:
    parts = path.split('/')
    return parts[1] if parts[0] == 'packages' else 'root tests'


def suite_of(path: str) -> str:
    parts = path.split('/')
    return f"packages/{parts[1]}/tests" if parts[0] == 'packages' else 'tests'


def registers_only(declared: Sequence[str]) -> bool:
    return any(name.endswith('ServiceProvider') for name in declared)


def trace(changed_classes: Sequence[str], index: ReferenceIndex, core_caller_depth: int = CORE_CALLER_DEPTH) -> Trace:
    """Follow each changed Core class through its callers to the Packages tests that reach it."""
    referencing = index.referencing()
    result = Trace()
    for changed in changed_classes:
        seen = {changed}
        queue = deque([(changed, 0)])
        while queue:
            name, core_hops = queue.popleft()
            for path in referencing.get(name, ()):
                core = is_core(path)
                if not core:
                    result.consumers.setdefault(changed, set()).add(package_of(path))
                    if is_test(path):
                        result.tests.setdefault(suite_of(path), set()).add(path)
                        continue
                declared = index.declares.get(path)
                if not declared:
                    # A file of helpers (Pest.php, a bootstrap) is loaded by every test in its suite.
                    if not core and '/tests/' in f"/{path}":
                        result.whole_suites.add(suite_of(path))
                    continue
                hops = core_hops + (1 if core else 0)
                if registers_only(declared) or hops > core_caller_depth:
                    continue
                for caller in declared:
                    if caller not in seen:
                        seen.add(caller)
                        queue.append((caller, hops))
    return result


def core_autoload(core: Path) -> list[tuple[str, str]]:
    composer = json.loads((core / 'composer.json').read_text())
    mapping = composer.get('autoload', {}).get('psr-4', {})
    pairs = [(prefix, directory.rstrip('/') + '/') for prefix, directories in mapping.items()
             for directory in ([directories] if isinstance(directories, str) else directories)]
    return sorted(pairs, key=lambda pair: len(pair[1]), reverse=True)


def class_for(path: str, autoload: Sequence[tuple[str, str]]) -> str | None:
    if not path.endswith('.php'):
        return None
    for prefix, directory in autoload:
        if path.startswith(directory):
            return normalise(prefix + path[len(directory):-4].replace('/', '\\'))
    return None


def git(checkout: Path, *arguments: str) -> str:
    return subprocess.run(('git', '-C', str(checkout), *arguments), check=True, capture_output=True, text=True).stdout


def changed_files(core: Path, base: str) -> tuple[str, list[str]]:
    merge_base = git(core, 'merge-base', base, 'HEAD').strip()
    # No rename detection: a moved class is a deleted name its consumers still use.
    paths = set(git(core, 'diff', '--name-only', '--no-renames', merge_base).splitlines())
    paths |= set(git(core, 'ls-files', '--others', '--exclude-standard').splitlines())
    return merge_base, sorted(path for path in paths if path)


def default_packages_checkout(core: Path) -> Path:
    configured = os.environ.get('CAPELL_PACKAGES_REPO_PATH', '')
    if configured:
        return Path(configured)
    common = Path(git(core, 'rev-parse', '--path-format=absolute', '--git-common-dir').strip())
    return common.parent.parent / 'capell-packages-4'


def read_tracked(checkout: Path, paths: Iterable[str], key_prefix: str = '') -> dict[str, str]:
    files: dict[str, str] = {}
    for path in paths:
        try:
            files[key_prefix + path] = (checkout / path).read_text(errors='replace')
        except (FileNotFoundError, IsADirectoryError):
            continue
    return files


def is_packages_source(path: str) -> bool:
    if not path.endswith('.php'):
        return False
    if path.startswith('tests/'):
        return True
    parts = path.split('/')
    return len(parts) > 3 and parts[0] == 'packages' and parts[2] in PACKAGES_DIRECTORIES


def scanned_files(core: Path, packages: Path, autoload: Sequence[tuple[str, str]]) -> dict[str, str]:
    packages_paths = [path for path in git(packages, 'ls-files', '--', *PACKAGES_ROOTS).splitlines() if is_packages_source(path)]
    core_paths = [path for path in git(core, 'ls-files', '--', '*.php').splitlines() if class_for(path, autoload)]
    return read_tracked(packages, packages_paths) | read_tracked(core, core_paths, CORE_PREFIX)


def run_targets(result: Trace) -> list[str]:
    targets: list[str] = []
    for suite in sorted(set(result.tests) | result.whole_suites):
        paths = result.tests.get(suite, set())
        if suite in result.whole_suites or len(paths) > SUITE_COLLAPSE_AT:
            targets.append(suite)
        else:
            targets += sorted(paths)
    return targets


def render(result: Trace, changed_classes: Sequence[str], untraced: Sequence[str]) -> list[str]:
    lines: list[str] = []
    used = [name for name in changed_classes if name in result.consumers]
    if used:
        lines.append('Changed Core classes that companion Packages reach:')
        lines += [f"  {name} -> {', '.join(sorted(result.consumers[name]))}" for name in used]
    if len(used) < len(changed_classes):
        lines.append(f"Changed Core classes no Packages file reaches: {len(changed_classes) - len(used)}")
    targets = run_targets(result)
    if targets:
        lines.append(f"Packages tests to run ({len(targets)} target(s)), from a Packages checkout whose vendor/capell-app links to this Core checkout:")
        lines.append('  vendor/bin/pest ' + ' '.join(targets) + ' --configuration=phpunit.xml')
    else:
        lines.append('Packages tests to run: none reach a changed Core class.')
    if untraced:
        lines.append(f"Not traced, as they are not Core classes (check their Packages consumers by hand): {', '.join(untraced)}")
    return lines


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=(__doc__ or '').split('\n\n')[0])
    parser.add_argument('paths', nargs='*', help='changed paths instead of reading them from Git (- reads standard input)')
    parser.add_argument('--stdin', action='store_true', help='read changed paths from standard input')
    parser.add_argument('--base', default=os.environ.get('CAPELL_PREMERGE_BASE', 'origin/main'))
    parser.add_argument('--packages', type=Path, help='the Packages checkout (default: CAPELL_PACKAGES_REPO_PATH or the sibling capell-packages-4)')
    parser.add_argument('--core', type=Path, default=Path(__file__).resolve().parents[1], help=argparse.SUPPRESS)
    args = parser.parse_args(argv)

    core = args.core
    if args.stdin or args.paths == ['-']:
        source, paths = 'standard input', sorted({line.strip() for line in sys.stdin if line.strip()})
    elif args.paths:
        source, paths = 'arguments', sorted(set(args.paths))
    else:
        try:
            merge_base, paths = changed_files(core, args.base)
        except subprocess.CalledProcessError as error:
            print(f"Core premerge hint: cannot find the merge base with {args.base}: {error.stderr.strip()}", file=sys.stderr)
            return 2
        source = f"{args.base} (merge base {merge_base[:12]})"

    try:
        packages = args.packages or default_packages_checkout(core)
        packages_head = git(packages, 'rev-parse', '--short=12', 'HEAD').strip()
    except (subprocess.CalledProcessError, OSError):
        print(f"Core premerge hint: no Packages checkout at {args.packages or 'the default location'}; set CAPELL_PACKAGES_REPO_PATH "
              'or pass --packages. Packages consumers were not checked.', file=sys.stderr)
        return 2

    autoload = core_autoload(core)
    changed_classes = [name for name in (class_for(path, autoload) for path in paths) if name]
    untraced = [path for path in paths if path.startswith('packages/') and class_for(path, autoload) is None]
    print(f"Core premerge hint: {len(paths)} changed file(s) from {source}; Packages checkout {packages} at {packages_head}.")
    result = trace(changed_classes, build_index(scanned_files(core, packages, autoload), changed_classes)) if changed_classes else Trace()
    print('\n'.join(render(result, changed_classes, untraced)))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
