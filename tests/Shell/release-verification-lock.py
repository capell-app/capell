#!/usr/bin/env python3
"""Exercise native Composer and Docker dispatch against the host release gate."""

import fcntl
import json
import os
from pathlib import Path
import re
import selectors
import shutil
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ReleaseVerificationLockTest(unittest.TestCase):
    def test_cli_hook_keeps_debug_and_exit_checks_precise(self):
        config = (ROOT / '.pre-commit-config.yaml').read_text()
        block = config.split('- id: checkfordebugging', 1)[1].split('- id:', 1)[0]
        entry = re.search(r"entry: '([^']+)'", block).group(1)
        excluded = re.search(r'exclude: (.+)', block).group(1)
        self.assertIsNone(re.search(entry, 'json.dump(record, lock)'))
        for statement in ['dump(value)', 'dd(value)', 'exit(1)', 'sys.exit(1)']:
            self.assertIsNotNone(re.search(entry, statement), statement)
        for path in ['scripts/testing/with-release-lock.py', 'tests/Shell/release-verification-lock.py']:
            self.assertIsNotNone(re.fullmatch(excluded, path), path)
        for path in ['app/Actions/Unexpected.php', 'scripts/testing/unapproved.py', 'tests/Shell/unapproved.py']:
            self.assertIsNone(re.fullmatch(excluded, path), path)

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='capell-release-dispatch-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.path = self.root / 'release.lock'
        self.environment = dict(os.environ, CAPELL_RELEASE_VERIFICATION_LOCK_PATH=str(self.path))
        for name in ['CAPELL_RELEASE_VERIFICATION_OWNER', 'CAPELL_NO_RELEASE_LOCK', 'COMPOSER', 'COMPOSER_VENDOR_DIR']:
            self.environment.pop(name, None)
        self.php = shutil.which('php')
        self.assertIsNotNone(self.php)

    def start(self, command, cwd=None, **environment):
        child = subprocess.Popen(command, cwd=cwd or ROOT, env={**self.environment, **environment},
                                 text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.addCleanup(self.stop, child)
        return child

    @staticmethod
    def stop(child):
        if child.poll() is None:
            child.terminate()
        child.communicate(timeout=10)

    def waits(self, child):
        diagnostic = ''
        deadline = time.monotonic() + 5
        with selectors.DefaultSelector() as selector:
            selector.register(child.stderr, selectors.EVENT_READ)
            while 'release verification' not in diagnostic:
                self.assertTrue(selector.select(timeout=max(0, deadline - time.monotonic())), diagnostic)
                line = child.stderr.readline()
                self.assertTrue(line, diagnostic)
                diagnostic += line
                self.assertLess(time.monotonic(), deadline)
        self.assertIsNone(child.poll())

    def helper(self, **environment):
        return self.start([self.php, str(ROOT / 'scripts/with-lock.php'), 'capell-release-verification', '--',
                           self.php, '-r', 'echo "ran\\n";'], **environment)

    def test_native_helper_waits_and_preserves_wrong_owner_record(self):
        with self.path.open('w+') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            record = {'schema': 1, 'owner': 'a' * 32, 'pid': os.getpid()}
            json.dump(record, lock)
            lock.flush()
            child = self.helper(CAPELL_RELEASE_VERIFICATION_OWNER='b' * 32, CAPELL_NO_LOCK='1')
            self.waits(child)
            self.assertEqual(json.loads(self.path.read_text()), record)
        output, error = child.communicate(timeout=10)
        self.assertEqual(child.returncode, 0, error)
        self.assertEqual(output, 'ran\n')

    def test_native_helper_accepts_only_live_owner_or_explicit_opt_out(self):
        for environment in [{'CAPELL_RELEASE_VERIFICATION_OWNER': 'a' * 32}, {'CAPELL_NO_RELEASE_LOCK': '1'}]:
            with self.path.open('w+') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX)
                json.dump({'schema': 1, 'owner': 'a' * 32, 'pid': os.getpid()}, lock)
                lock.flush()
                child = self.helper(**environment)
                output, error = child.communicate(timeout=10)
            self.assertEqual(child.returncode, 0, error)
            self.assertEqual(output, 'ran\n')

    def test_canonical_native_composer_preflight_waits_then_runs(self):
        scripts = self.root / 'scripts'
        scripts.mkdir()
        shutil.copyfile(ROOT / 'scripts/with-lock.php', scripts / 'with-lock.php')
        (scripts / 'run-preflight.php').write_text('<?php echo "preflight-ran\\n";')
        canonical = json.loads((ROOT / 'composer.json').read_text())['scripts']
        definition = {'name': 'fixture/release-dispatch', 'config': {'process-timeout': 0}, 'scripts': {
            name: canonical['preflight'] if name == 'preflight' else '@php -r \'echo "preflight-ran\\n";\''
            for name in canonical}}
        (self.root / 'composer.json').write_text(json.dumps(definition))
        composer = shutil.which('composer')
        self.assertIsNotNone(composer)
        with self.path.open('a+') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            child = self.start([self.php, composer, 'run', '--no-interaction', 'preflight'], cwd=self.root)
            self.waits(child)
            focused = self.start([self.php, '-r', 'echo "focused-ran\\n";'])
            output, error = focused.communicate(timeout=10)
            self.assertEqual(focused.returncode, 0, error)
            self.assertEqual(output, 'focused-ran\n')
            self.assertIsNone(child.poll())
        output, error = child.communicate(timeout=10)
        self.assertEqual(child.returncode, 0, error)
        self.assertTrue(output)
        self.assertTrue(all(line == 'preflight-ran' for line in output.splitlines()), output)

    def test_actual_docker_wrapper_waits_but_single_file_dispatch_is_unaffected(self):
        binary = self.root / 'bin'
        binary.mkdir()
        docker = binary / 'docker'
        docker.write_text('#!/bin/sh\nif [ "$1 $2 $3" = "compose version " ] || [ "$1 $2" = "compose version" ]; then exit 0; fi\nprintf "%s\\n" "$*"\n')
        docker.chmod(0o700)
        environment = {'PATH': str(binary) + os.pathsep + self.environment['PATH']}
        with self.path.open('a+') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            child = self.start(['./capell', 'preflight'], **environment)
            self.waits(child)
            focused = self.start(['./capell', 'pest', 'packages/example/tests/ExampleTest.php'], **environment)
            output, error = focused.communicate(timeout=10)
            self.assertEqual(focused.returncode, 0, error)
            self.assertIn('vendor/bin/pest packages/example/tests/ExampleTest.php', output)
            self.assertNotIn('CAPELL_NO_RELEASE_LOCK=1', output)
            self.assertIsNone(child.poll())
        output, error = child.communicate(timeout=10)
        self.assertEqual(child.returncode, 0, error)
        self.assertIn('env CAPELL_NO_RELEASE_LOCK=1 composer preflight', output)

    def test_composer_test_sequence_preserves_forwarded_options(self):
        scripts = self.root / 'scripts'
        scripts.mkdir()
        shutil.copyfile(ROOT / 'scripts/with-lock.php', scripts / 'with-lock.php')
        canonical = json.loads((ROOT / 'composer.json').read_text())['scripts']
        marker = '@php -r \'echo json_encode(array_slice($argv, 1)), "\\n";\' --'
        definition = {'name': 'fixture/release-dispatch', 'config': {'process-timeout': 0}, 'scripts': {
            name: canonical['test'] if name == 'test' else marker for name in canonical}}
        (self.root / 'composer.json').write_text(json.dumps(definition))
        composer = shutil.which('composer')
        self.assertIsNotNone(composer)
        arguments = ['--filter=ForwardedSelection', 'tests/ForwardedTest.php']
        child = self.start([self.php, composer, 'run', '--no-interaction', 'test', '--', *arguments], cwd=self.root)
        output, error = child.communicate(timeout=10)
        self.assertEqual(child.returncode, 0, error)
        self.assertTrue(output)
        self.assertTrue(all(json.loads(line) == arguments for line in output.splitlines()), output)

    def test_generic_docker_commands_share_the_same_gate(self):
        binary = self.root / 'bin'
        binary.mkdir()
        docker = binary / 'docker'
        docker.write_text('#!/bin/sh\nif [ "$1 $2" = "compose version" ]; then exit 0; fi\nprintf "%s\\n" "$*"\n')
        docker.chmod(0o700)
        environment = {'PATH': str(binary) + os.pathsep + self.environment['PATH']}
        for arguments in [['exec', 'composer', 'preflight'], ['run', 'composer', 'coverage'], ['exec', 'vendor/bin/pest']]:
            with self.path.open('a+') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX)
                child = self.start(['./capell', *arguments], **environment)
                self.waits(child)
                focused = self.start(['./capell', 'exec', 'vendor/bin/pest', 'packages/example/tests/ExampleTest.php'], **environment)
                output, error = focused.communicate(timeout=10)
                self.assertEqual(focused.returncode, 0, error)
                self.assertNotIn('CAPELL_NO_RELEASE_LOCK=1', output)
            output, error = child.communicate(timeout=10)
            self.assertEqual(child.returncode, 0, error)
            self.assertIn('env CAPELL_NO_RELEASE_LOCK=1', output)

    def test_only_test_file_arguments_select_the_focused_exception(self):
        for arguments, expected in [([], 'full'), (['tests/Unit'], 'full'),
                                    (['--configuration=/tmp/tests/phpunit.php'], 'full'),
                                    (['--configuration', '/tmp/tests/phpunit.php'], 'full'),
                                    (['tests/ExampleTest.php', 'tests/OtherTest.php'], 'full'),
                                    (['tests/ExampleTest.php', 'tests/Unit'], 'full'),
                                    (['--filter=ExampleTest.php'], 'full'),
                                    (['tests/ExampleTest.php'], 'focused'),
                                    (['/tmp/tests/ExampleTest.php'], 'focused'),
                                    (['--path=tests/ExampleTest.php'], 'focused'),
                                    (['--path=/tmp/tests/ExampleTest.php'], 'focused')]:
            child = self.start(['bash', '-c', 'source "$1"; shift; capell_test_verification_scope "$@"',
                                'scope', str(ROOT / 'scripts/testing/release-lock-bridge.sh'), *arguments])
            output, error = child.communicate(timeout=10)
            self.assertEqual(child.returncode, 0, error)
            self.assertEqual(output, expected + '\n', arguments)


if __name__ == '__main__':
    unittest.main()
