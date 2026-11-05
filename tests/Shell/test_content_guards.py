#!/usr/bin/env python3
"""Exercise both content guards through the push and CI entry points."""
import os
import re
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
# var/ is untracked, so a fresh CI checkout does not have it; every test creates its fixtures there.
(ROOT / 'var').mkdir(exist_ok=True)


class ContentGuardTest(unittest.TestCase):
    def test_push_and_ci_reject_each_marker(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'var') as directory:
            scratch = Path(directory)
            fixture = scratch / 'fixture.php'
            git = scratch / 'git'
            real_git = subprocess.check_output(['which', 'git'], text=True).strip()
            git.write_text('#!/bin/sh\nif [ "$1" = diff ] && [ "$2" = --name-only ]; then\n  printf "%s\\0" "$GUARD_FIXTURE"\nelse\n  exec "$REAL_GIT" "$@"\nfi\n')
            git.chmod(0o755)
            env = dict(os.environ, PATH=f'{scratch}{os.pathsep}{os.environ["PATH"]}',
                       GUARD_FIXTURE=str(fixture.relative_to(ROOT)), REAL_GIT=real_git)
            runner = os.environ.get('GUARD_TEST_PRE_COMMIT', 'pre-commit')
            env['PRE_COMMIT'] = runner
            ci = os.environ.get('GUARD_TEST_CI', 'scripts/guards/check-commit-range.sh')
            for content, hook in [('clean text\n', None), ('d' + 'd($value);\n', 'checkfordebugging'),
                                  ('DO NOT ' + 'COMMIT\n', 'donotcommit')]:
                fixture.write_text(content)
                with self.subTest(content=content):
                    for stage in ('pre-push', 'ci'):
                        with self.subTest(stage=stage):
                            if stage == 'pre-push':
                                commands = [[runner, 'run', name, '--hook-stage', 'pre-push', '--files', str(fixture.relative_to(ROOT))]
                                            for name in ('checkfordebugging', 'donotcommit')]
                            else:
                                commands = [[ci, 'HEAD', 'HEAD']]
                            results = [subprocess.run(command, cwd=ROOT, env=env, text=True, capture_output=True) for command in commands]
                            failed = [result for result in results if result.returncode]
                            self.assertEqual(bool(failed), hook is not None, '\n'.join(r.stdout + r.stderr for r in results))
                            output = '\n'.join(r.stdout + r.stderr for r in results)
                            if failed:
                                self.assertEqual([r.returncode for r in failed], [1], output)
                                self.assertIn('hook id: ' + hook, output)
                                self.assertIn(str(fixture.relative_to(ROOT)) + ':1:', output)
                            else:
                                self.assertEqual(output.count('Passed'), 2, output)


class CliDebuggingGuardTest(unittest.TestCase):
    def test_cli_script_is_checked_and_status_exits_are_allowed(self):
        path = 'scripts/with-admission.php'
        runner = os.environ.get('GUARD_TEST_PRE_COMMIT', 'pre-commit')
        result = subprocess.run([runner, 'run', 'checkfordebugging', '--hook-stage', 'manual', '--files', path], cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('Passed', result.stdout)

    def test_debug_calls_are_rejected_in_cli_script(self):
        path = 'scripts/with-admission.php'
        with tempfile.TemporaryDirectory(dir=ROOT / 'var') as directory:
            scratch = Path(directory)
            (scratch / 'scripts').mkdir()
            fixture = scratch / path
            original = (ROOT / path).read_bytes()
            for call in (b'd' + b'd($value);', b'du' + b'mp($value);', b'ra' + b'y($value);', b'var_' + b'du' + b'mp($value);', b'print_' + b'r($value);', b'd' + b'd ($value);', b'var_' + b'du' + b'mp ($value);'):
                fixture.write_bytes(original + b'\n' + call + b'\n')
                result = subprocess.run(['python3', os.environ.get('GUARD_TEST_DEBUGGING', str(ROOT / 'scripts/check-debugging.py')), path], cwd=scratch, capture_output=True)
                self.assertEqual(result.returncode, 1, call.decode())
                self.assertIn(call, result.stdout)
    def test_debugging_calls_are_case_insensitive(self):
        guard = os.environ.get('GUARD_TEST_DEBUGGING', str(ROOT / 'scripts/check-debugging.py'))
        with tempfile.TemporaryDirectory(dir=ROOT / 'var') as directory:
            fixture = Path(directory) / 'fixture.php'
            for call in (b'D' + b'D($value);', b'Var_' + b'Du' + b'mp($value);'):
                with self.subTest(call=call):
                    fixture.write_bytes(call)
                    result = subprocess.run(['python3', guard, str(fixture)], cwd=ROOT, capture_output=True)
                    self.assertEqual(result.returncode, 1, call.decode())
                    self.assertIn(call, result.stdout)

    def test_only_approved_cli_status_exits_pass(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'var') as directory:
            scratch = Path(directory)
            (scratch / 'scripts').mkdir()
            path = 'scripts/with-admission.php'
            fixture = scratch / path
            original = (ROOT / path).read_bytes()
            for extra, expected in ((b'', 0), (b'ex' + b'it($message);', 1),
                                    (b'ex' + b'it("oops");', 1),
                                    (b'ex' + b'it(1); d' + b'd($value);', 1)):
                fixture.write_bytes(original + b'\n' + extra + b'\n')
                result = subprocess.run(['python3', os.environ.get('GUARD_TEST_DEBUGGING', str(ROOT / 'scripts/check-debugging.py')), path], cwd=scratch, capture_output=True)
                self.assertEqual(result.returncode, expected, result.stdout.decode())
            unapproved = scratch / 'scripts/unapproved.php'
            unapproved.write_bytes(b'ex' + b'it(1);')
            result = subprocess.run(['python3', os.environ.get('GUARD_TEST_DEBUGGING', str(ROOT / 'scripts/check-debugging.py')), str(unapproved.relative_to(scratch))], cwd=scratch, capture_output=True)
            self.assertEqual(result.returncode, 1)

    def test_php_fixtures_and_logger_are_checked(self):
        files = [
            'packages/frontend/src/Support/Logging/FrontendLogger.php',
            'tests/Support/Fakes/FakeProcess.php',
            'tests/Unit/TestbenchCommandScriptTest.php',
            'tests/Unit/TestbenchPreparationScriptTest.php',
            'tests/Unit/TestAllMatrixScriptTest.php',
            'packages/core/tests/Unit/Actions/Install/CallArtisanCommandActionTest.php',
        ]
        runner = os.environ.get('GUARD_TEST_PRE_COMMIT', 'pre-commit')
        with tempfile.TemporaryDirectory(dir=ROOT / 'var') as directory:
            scratch = Path(directory)
            for name in files:
                with self.subTest(path=name):
                    result = subprocess.run([runner, 'run', 'checkfordebugging', '--hook-stage', 'manual', '--files', name], cwd=ROOT, capture_output=True, text=True)
                    self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                    self.assertIn('Passed', result.stdout)
                    fixture = scratch / name
                    fixture.parent.mkdir(parents=True, exist_ok=True)
                    fixture.write_bytes((ROOT / name).read_bytes() + b'\n' + b'd' + b'd($value);\n')
                    result = subprocess.run(['python3', str(ROOT / 'scripts/check-debugging.py'), name], cwd=scratch, capture_output=True)
                    self.assertEqual(result.returncode, 1)
                    self.assertIn(b'd' + b'd($value);', result.stdout)

    def test_tracked_text_files_pass_configured_hooks(self):
        runner = os.environ.get('GUARD_TEST_PRE_COMMIT', 'pre-commit')
        # pre-commit supplies text type detection and both global and hook excludes.
        for hook in ('checkfordebugging', 'donotcommit'):
            result = subprocess.run([runner, 'run', hook, '--hook-stage', 'manual', '--all-files'], cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('Passed', result.stdout)

    def test_special_exits_are_exact_and_case_sensitive(self):
        guard = os.environ.get('GUARD_TEST_DEBUGGING', str(ROOT / 'scripts/check-debugging.py'))
        cases = {
            '.claude/hooks/session-start.sh': b'ex' + b'it(PHP_VERSION_ID >= 80400 ? 0 : 1)',
            'scripts/verify-container-quickstart.sh': b'ex' + b'it(1)',
        }
        with tempfile.TemporaryDirectory(dir=ROOT / 'var') as directory:
            scratch = Path(directory)
            for name, allowed in cases.items():
                fixture = scratch / name
                fixture.parent.mkdir(parents=True, exist_ok=True)
                for content, expected in ((allowed, 0), (allowed.upper(), 1), (allowed + b'; D' + b'D($x)', 1), (b'ex' + b'it(2)', 1)):
                    fixture.write_bytes(content + b'\n')
                    result = subprocess.run(['python3', guard, name], cwd=scratch, capture_output=True)
                    self.assertEqual(result.returncode, expected, result.stdout.decode())

    def test_generated_javascript_exact_calls_do_not_hide_debugging(self):
        name = 'packages/admin/publishes/build/js/filament/rich-content-plugins/highlight.js'
        guard = os.environ.get('GUARD_TEST_DEBUGGING', str(ROOT / 'scripts/check-debugging.py'))
        with tempfile.TemporaryDirectory(dir=ROOT / 'var') as directory:
            scratch = Path(directory)
            fixture = scratch / name
            fixture.parent.mkdir(parents=True)
            for content, expected in ((b'R' + b'd(n); R' + b'd(x);', 0), (b'R' + b'd(n); r' + b'd($value);', 1), (b'R' + b'd(other);', 1)):
                fixture.write_bytes(content)
                result = subprocess.run(['python3', guard, name], cwd=scratch, capture_output=True)
                self.assertEqual(result.returncode, expected, result.stdout.decode())

    def test_head_must_be_checked_out(self):
        script = os.environ.get('GUARD_TEST_RANGE_SCRIPT', 'scripts/guards/check-commit-range.sh')
        result = subprocess.run([script, 'HEAD~1', 'HEAD~1'], cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn('checked-out HEAD', result.stderr)

    def test_committed_range_uses_real_history(self):
        # Use existing history without writing Git objects, refs or an index.
        commits = subprocess.check_output(['git', 'log', '--diff-filter=R', '--format=%H', 'HEAD'], cwd=ROOT, text=True).splitlines()
        rename = None
        for commit in commits:
            base = commit + '^'
            changes = subprocess.check_output(['git', 'diff', '--name-status', '-M', base, 'HEAD'], cwd=ROOT, text=True).splitlines()
            renames = [line.split('\t') for line in changes if re.match(r'^R[0-9]+\t', line)]
            deleted = [line.split('\t')[1] for line in changes if line.startswith('D\t')]
            rename = next((entry[2] for entry in renames if (ROOT / entry[2]).is_file()), None)
            if rename and deleted:
                break
        self.assertTrue(rename and deleted, 'No surviving rename and deletion found in history')
        git_dir = subprocess.check_output(['git', 'rev-parse', '--absolute-git-dir'], cwd=ROOT, text=True).strip()
        with tempfile.TemporaryDirectory(dir=ROOT / 'var') as directory:
            scratch = Path(directory)
            for name in ('.pre-commit-config.yaml', 'scripts/check-debugging.py', 'scripts/guards/check-commit-range.sh', rename):
                target = scratch / name
                target.parent.mkdir(parents=True, exist_ok=True)
                source = Path(os.environ.get('GUARD_TEST_RANGE_SCRIPT', ROOT / name)) if name == 'scripts/guards/check-commit-range.sh' else ROOT / name
                target.write_bytes(source.read_bytes())
                target.chmod(source.stat().st_mode)
            env = dict(os.environ, GIT_DIR=git_dir, GIT_WORK_TREE=str(scratch))
            fixture = scratch / rename
            for marker, expected in ((b'clean content', 0), (b'd' + b'd($value);', 1), (b'DO NOT ' + b'COMMIT', 1)):
                fixture.write_bytes(marker + b'\n')
                result = subprocess.run(['scripts/guards/check-commit-range.sh', base, 'HEAD'], cwd=scratch, env=env, capture_output=True, text=True)
                self.assertEqual(result.returncode, expected, result.stdout + result.stderr)
                if expected:
                    self.assertIn(rename, result.stdout + result.stderr)
                else:
                    self.assertEqual(result.stdout.count('Passed'), 2, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
