#!/usr/bin/env python3
"""Report debugging calls while permitting specific CLI status exits."""

from pathlib import Path
import re
import sys

DEBUGGING = re.compile(rb'\bdd[ \t]*\(|(?<!->)(?<!::)\bdebug[ \t]*\(|(?<!json\.)\bdump[ \t]*\(|\bray[ \t]*\(|\brd[ \t]*\(|\bdie[ \t]*\(|\bexit[ \t]*\(|\bddRawSql[ \t]*\(|\bvar_dump[ \t]*\(|\bprint_r[ \t]*\(')
EXIT = re.compile(rb'\bexit[ \t]*\(')
CLI_SCRIPTS = frozenset({
    'with-lock', 'with-admission', 'check-test-psr4',
    'check-composer-major-constraints', 'check-lang-spelling',
    'check-agent-schema-contract', 'check-composer-local-paths',
    'check-docs-config-keys', 'check-docs-env-vars', 'check-docs-links',
    'check-docs-orphans', 'check-docs-requirements', 'check-docs-artisan-commands', 'check-language-key-drift',
    'check-package-catalog-drift', 'check-package-dependencies',
    'check-queue-contract', 'check-readme-engineering-standards',
    'check-root-docs', 'check-support-contract', 'check-vendor-integrity',
    'check-test-weak-assertions', 'extract-pest-metrics', 'extract-phpstan-level',
    'prepare-test-all-dependencies', 'release-eligibility', 'release',
    'release-preflight', 'run-preflight', 'sync-split-repository-health',
    'run-test-all-cell', 'run-test-all-matrix', 'run-test-all-portability-cell',
})
STATUS_EXIT = re.compile(rb'exit\((?:[0-9]+|\$status|\$exitCode)\)')
SPECIAL_EXITS = {
    'scripts/with-admission.php': (b'ex' + b'it(is_resource($process) ? proc_close($process) : 127)',),
    'scripts/run-preflight.php': (b'ex' + b'it(is_resource($process) ? proc_close($process) : 127)',),
    'scripts/with-lock.php': (b'ex' + b'it($runCommand($command))',),
    'scripts/check-test-psr4.php': (b'ex' + b'it($violations === [] ? 0 : 1)',),
    'scripts/check-test-weak-assertions.php': (b'ex' + b'it($failed ? 1 : 0)',),
    'scripts/run-test-all-matrix.php': (b'ex' + b'it($failures === [] ? 0 : 1)',),
    'tests/Support/Fakes/FakeProcess.php': (b'ex' + b'it((int) $argv[3])',),
    'tests/Unit/TestbenchCommandScriptTest.php': (
        b'ex' + b'it(19)', b'ex' + b"it((int) getenv('FIXTURE_COMMAND_EXIT'))", b'ex' + b'it($exit)',
    ),
    'tests/Unit/TestbenchPreparationScriptTest.php': (b'ex' + b'it(1)',),
    'tests/Unit/TestAllMatrixScriptTest.php': (b'ex' + b"it((int) getenv('CAPTURE_EXIT'))",),
    'packages/core/tests/Unit/Actions/Install/CallArtisanCommandActionTest.php': (
        b'ex' + b"it($fixtureMode === 'failed-probe' ? 1 : 0)", b'ex' + b'it(19)', b'ex' + b'it($app->run())',
    ),
}


def is_status_exit(name, line, match):
    path = Path(name)
    remaining = line[match.start():]
    if any(remaining.startswith(construct) for construct in SPECIAL_EXITS.get(path.as_posix(), ())):
        return True
    return (path.parent.as_posix() == 'scripts' and path.suffix == '.php'
            and path.stem in CLI_SCRIPTS and bool(STATUS_EXIT.match(remaining)))


def main(paths):
    found = False
    for name in paths:
        with Path(name).open('rb') as source:
            for number, line in enumerate(source, 1):
                for match in DEBUGGING.finditer(line):
                    if match.group().startswith(b'debug') and re.search(rb'\bfunction\s+&?\s*$', line[:match.start()]):
                        continue
                    if match.group() == b'ex' + b'it(' and is_status_exit(name, line, match):
                        continue
                    found = True
                    sys.stdout.buffer.write(f'{name}:{number}:'.encode() + line.rstrip(b'\r\n') + b'\n')
                    if name.startswith('scripts/') and EXIT.search(line):
                        sys.stderr.write('The debugging guard also matches CLI ' + 'exit' + '(). If this is a legitimate CLI status, review a narrowly scoped hook exemption for this script.\n')
                    break
    return int(found)


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
