#!/usr/bin/env python3
"""Exercise the installed hook with real Git and a concurrent worktree writer."""
import os
import json
import hashlib
import importlib.util
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest

SOURCE = Path(__file__).resolve().parents[2]
# Running under an outer git hook leaks GIT_INDEX_FILE and friends into inner repos.
BASE_ENV = {k: v for k, v in os.environ.items() if not k.startswith('GIT_')}


class SafePreCommitTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='safe-hook-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'repo'
        self.root.mkdir()
        self.events = Path(self.temp.name) / 'events'
        self.events.mkdir()
        self.env = {**BASE_ENV, 'HOOK_TEST_EVENTS': str(self.events), 'PRE_COMMIT_HOME': str(self.events / 'precommit-cache')}
        self.git('init', '-q')
        self.git('config', 'user.name', 'Hook test')
        self.git('config', 'user.email', 'hook-test@example.invalid')
        (self.root / 'scripts/hooks').mkdir(parents=True)
        for name in ('pre-commit.py', 'install-pre-commit.py', 'pre-push.py'):
            shutil.copy2(SOURCE / 'scripts/hooks' / name, self.root / 'scripts/hooks' / name)
        (self.root / 'hook.py').write_text('''import os, pathlib, sys, time
p = pathlib.Path(os.environ['HOOK_TEST_EVENTS'])
(p / 'cwd').write_text(str(pathlib.Path.cwd()))
(p / 'arguments').write_text('\\n'.join(sys.argv[1:]))
(p / 'ready').write_text('ready')
while not (p / 'continue').exists():
    time.sleep(0.02)
if os.environ.get('HOOK_TEST_MODIFY'):
    pathlib.Path(sys.argv[1]).write_text('formatter rewrite\\n')
''')
        (self.root / 'push_hook.py').write_text('''import os, pathlib, time
p = pathlib.Path(os.environ['HOOK_TEST_EVENTS'])
(p / 'push-cwd').write_text(str(pathlib.Path.cwd()))
(p / 'push-file').write_text(pathlib.Path('file.txt').read_text())
(p / 'push-seen').open('a').write(pathlib.Path('file.txt').read_text().strip() + ' ' + os.environ.get('PRE_COMMIT_TO_REF', '') + '\\n')
(p / 'push-from').write_text(os.environ.get('PRE_COMMIT_FROM_REF', ''))
(p / 'push-to').write_text(os.environ.get('PRE_COMMIT_TO_REF', ''))
(p / 'push-ready').write_text('ready')
while not (p / 'continue').exists():
    time.sleep(0.02)
''')
        (self.root / '.pre-commit-config.yaml').write_text('''repos:
- repo: local
  hooks:
  - id: fixture
    name: fixture
    entry: python3 hook.py
    language: system
    types: [file]
    stages: [pre-commit]
  - id: push-fixture
    name: push fixture
    entry: python3 push_hook.py
    language: system
    pass_filenames: false
    always_run: true
    stages: [pre-push]
''')
        (self.root / '.gitattributes').write_text('* text=auto eol=lf\n')
        (self.root / 'file.txt').write_text('base\n')
        (self.root / 'other.txt').write_text('other base\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'baseline')

    def git(self, *args, check=True):
        return subprocess.run(['git', *args], cwd=self.root, text=True, capture_output=True, check=check, env=BASE_ENV)

    def install(self):
        if os.environ.get('HOOK_TEST_BASELINE') == '1':
            subprocess.run(['pre-commit', 'install'], cwd=self.root, text=True, capture_output=True, env=self.env, check=True)
            return subprocess.run(['pre-commit', 'install', '--hook-type', 'pre-push'], cwd=self.root, text=True, capture_output=True, env=self.env)
        return subprocess.run(['python3', 'scripts/hooks/install-pre-commit.py'], cwd=self.root, text=True, capture_output=True, env=self.env)

    def mark_fixture_hooks_known(self):
        spec = importlib.util.spec_from_file_location('fixture_safe_commit', SOURCE / 'scripts/hooks/pre-commit.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        config_hash = hashlib.sha256((self.root / '.pre-commit-config.yaml').read_bytes()).hexdigest()
        implementation_hash = module.hook_implementation_hash(self.root)
        script = self.root / 'scripts/hooks/pre-commit.py'
        contents = script.read_text().replace(module.KNOWN_HOOK_CONFIG_SHA256, config_hash)
        contents = contents.replace(module.KNOWN_HOOK_IMPLEMENTATION_SHA256, implementation_hash)
        script.write_text(contents)

    def pint_hook(self):
        if os.environ.get('HOOK_TEST_BASELINE') != '1':
            return SOURCE / '.pre-commit/pint.sh'
        baseline = subprocess.run(['git', 'show', 'HEAD:.pre-commit/pint.sh'], cwd=SOURCE, capture_output=True, check=True).stdout
        path = self.root / 'baseline-pint.sh'
        path.write_bytes(baseline)
        return path

    def wait_ready(self, proc):
        until = time.monotonic() + 30
        while time.monotonic() < until:
            if (self.events / 'ready').exists():
                return
            if proc.poll() is not None:
                self.fail('hook exited early: ' + proc.communicate()[1])
            time.sleep(0.02)
        proc.kill()
        self.fail('hook did not reach fixture within 30 seconds')

    def test_pre_push_checks_committed_range_without_overwriting_concurrent_edits(self):
        (self.root / 'link.txt').symlink_to('file.txt')
        self.git('add', 'link.txt')
        self.git('commit', '-qm', 'safe link')
        base = self.git('rev-parse', 'HEAD').stdout.strip()
        (self.root / 'file.txt').write_text('committed\n')
        self.git('add', 'file.txt')
        self.git('commit', '-qm', 'ahead')
        head = self.git('rev-parse', 'HEAD').stdout.strip()
        remote = str(Path(self.temp.name) / 'remote.git')
        self.git('remote', 'add', 'origin', remote)
        self.git('update-ref', 'refs/remotes/origin/main', base)
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('first unstaged\n')
        hook = self.root / '.git/hooks/pre-push'
        proc = subprocess.Popen([str(hook), 'origin', remote], cwd=self.root, stdin=subprocess.PIPE,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=self.env)
        self.addCleanup(lambda: proc.kill() if proc.poll() is None else None)
        proc.stdin.write(f'refs/heads/main {head} refs/heads/main {base}\n')
        proc.stdin.close()
        proc.stdin = None
        until = time.monotonic() + 30
        while time.monotonic() < until and not (self.events / 'push-ready').exists():
            if proc.poll() is not None:
                self.fail('push hook exited early: ' + proc.communicate()[1])
            time.sleep(0.02)
        self.assertTrue((self.events / 'push-ready').exists(), 'push hook did not start')
        (self.root / 'file.txt').write_text('writer continued\n')
        (self.events / 'continue').write_text('go')
        out, err = proc.communicate(timeout=30)
        self.assertEqual((self.root / 'file.txt').read_text(), 'writer continued\n', out + err)
        self.assertEqual(proc.returncode, 0, out + err)
        self.assertEqual((self.events / 'push-file').read_text(), 'committed\n')
        self.assertEqual((self.events / 'push-from').read_text(), base)
        self.assertEqual((self.events / 'push-to').read_text(), head)
        self.assertNotEqual(Path((self.events / 'push-cwd').read_text()), self.root)

    def test_pre_push_refuses_dirty_changed_php_before_analysis(self):
        (self.root / 'scripts/guards').mkdir(parents=True)
        shutil.copy2(SOURCE / 'scripts/guards/pre-push-analysis.sh', self.root / 'scripts/guards/pre-push-analysis.sh')
        (self.root / 'packages').mkdir()
        (self.root / 'packages/example.php').write_text('<?php // base\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'guard baseline')
        base = self.git('rev-parse', 'HEAD').stdout.strip()
        (self.root / 'packages/example.php').write_text('<?php // committed\n')
        self.git('add', 'packages/example.php')
        self.git('commit', '-qm', 'changed PHP')
        head = self.git('rev-parse', 'HEAD').stdout.strip()
        remote = str(Path(self.temp.name) / 'remote.git')
        self.git('remote', 'add', 'origin', remote)
        self.git('update-ref', 'refs/remotes/origin/main', base)
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'packages/example.php').write_text('<?php // unsaved\n')
        result = subprocess.run([str(self.root / '.git/hooks/pre-push'), 'origin', remote], cwd=self.root,
                                input=f'refs/heads/main {head} refs/heads/main {base}\n',
                                text=True, capture_output=True, env=self.env)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('uncommitted edits', result.stderr)
        self.assertEqual((self.root / 'packages/example.php').read_text(), '<?php // unsaved\n')

    def test_pre_push_chains_legacy_hook_and_stops_on_failure(self):
        head = self.git('rev-parse', 'HEAD').stdout.strip()
        hook = self.root / '.git/hooks/pre-push'
        hook.write_text('#!/bin/sh\ncat > "$HOOK_TEST_EVENTS/legacy-stdin"\nexit 7\n')
        hook.chmod(0o755)
        self.assertEqual(self.install().returncode, 0)
        update = f'refs/heads/main {head} refs/heads/main {head}\n'
        result = subprocess.run([str(hook), 'origin', 'remote'], cwd=self.root, input=update,
                                text=True, capture_output=True, env=self.env)
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue((self.events / 'legacy-stdin').exists(), result.stdout + result.stderr)
        self.assertEqual((self.events / 'legacy-stdin').read_text(), update)
        self.assertFalse((self.events / 'push-ready').exists())

    def test_pre_push_refuses_symlink_outside_snapshot(self):
        (self.root / 'escape').symlink_to('/etc/passwd')
        self.git('add', 'escape')
        self.git('commit', '-qm', 'outside link')
        head = self.git('rev-parse', 'HEAD').stdout.strip()
        self.assertEqual(self.install().returncode, 0)
        result = subprocess.run([str(self.root / '.git/hooks/pre-push'), 'origin', 'remote'], cwd=self.root,
                                input=f'refs/heads/main {head} refs/heads/main {head}\n',
                                text=True, capture_output=True, env=self.env)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('leaves the isolated snapshot', result.stderr)
        self.assertFalse((self.events / 'push-ready').exists())

    def test_pre_push_checks_non_head_external_and_tag_refs_at_target(self):
        (self.root / 'scripts/guards').mkdir(parents=True)
        shutil.copy2(SOURCE / 'scripts/guards/pre-push-analysis.sh', self.root / 'scripts/guards/pre-push-analysis.sh')
        self.git('add', '.')
        self.git('commit', '-qm', 'guard')
        old = self.git('rev-parse', 'HEAD').stdout.strip()
        (self.root / 'file.txt').write_text('current head\n')
        self.git('add', 'file.txt')
        self.git('commit', '-qm', 'new head')
        self.git('remote', 'add', 'origin', 'origin-url')
        self.assertEqual(self.install().returncode, 0)
        (self.events / 'continue').write_text('go')
        hook = str(self.root / '.git/hooks/pre-push')
        for url, branch in [('external-url', 'refs/heads/main'), ('origin-url', 'refs/tags/v1')]:
            update = f'refs/heads/main {old} {branch} {old}\n'
            result = subprocess.run([hook, 'origin', url], cwd=self.root, input=update,
                                    text=True, capture_output=True, env=self.env)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual((self.events / 'push-file').read_text(), 'base\n')
        update = f'refs/heads/main {old} refs/heads/main {old}\n'
        denied = subprocess.run([hook, 'origin', 'origin-url'], cwd=self.root, input=update,
                                text=True, capture_output=True, env=self.env)
        self.assertNotEqual(denied.returncode, 0)
        self.assertIn('not checked out', denied.stderr)

    def test_pre_push_checks_each_target_in_a_separate_snapshot(self):
        base = self.git('rev-parse', 'HEAD').stdout.strip()
        (self.root / 'file.txt').write_text('first target\n')
        self.git('add', 'file.txt')
        self.git('commit', '-qm', 'first')
        first = self.git('rev-parse', 'HEAD').stdout.strip()
        (self.root / 'file.txt').write_text('second target\n')
        self.git('add', 'file.txt')
        self.git('commit', '-qm', 'second')
        second = self.git('rev-parse', 'HEAD').stdout.strip()
        self.assertEqual(self.install().returncode, 0)
        (self.events / 'continue').write_text('go')
        updates = f'refs/heads/one {first} refs/heads/one {base}\nrefs/heads/two {second} refs/heads/two {base}\n'
        result = subprocess.run([str(self.root / '.git/hooks/pre-push'), 'external', 'remote'], cwd=self.root,
                                input=updates, text=True, capture_output=True, env=self.env, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.events / 'push-seen').read_text().splitlines(), [f'first target {first}', f'second target {second}'])

    def test_pint_passes_filenames_without_shell_interpretation(self):
        (self.root / 'vendor/bin').mkdir(parents=True)
        pint = self.root / 'vendor/bin/pint'
        pint.write_text('#!/usr/bin/env python3\nimport json, os, sys\nopen(os.environ["HOOK_TEST_ARGS"], "w").write(json.dumps(sys.argv[1:]))\n')
        pint.chmod(0o755)
        hook = self.pint_hook()
        args_path = self.events / 'pint-args'
        filename = 'packages/name with spaces;touch PWNED.php'
        result = subprocess.run(['bash', str(hook), filename], cwd=self.root, capture_output=True, text=True,
                                env={**BASE_ENV, 'HOOK_TEST_ARGS': str(args_path)}, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        import json
        self.assertEqual(json.loads(args_path.read_text()), [filename])
        self.assertFalse((self.root / 'PWNED.php').exists())

    def test_pint_propagates_an_unexpected_failure(self):
        (self.root / 'vendor/bin').mkdir(parents=True)
        pint = self.root / 'vendor/bin/pint'
        pint.write_text('#!/bin/sh\nexit 9\n')
        pint.chmod(0o755)
        result = subprocess.run(['bash', str(self.pint_hook()), 'file.php'], cwd=self.root,
                                capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 9)

    def test_pint_preserves_formatter_failure_and_fix_exit_contract(self):
        (self.root / 'vendor/bin').mkdir(parents=True)
        pint = self.root / 'vendor/bin/pint'
        pint.write_text('#!/bin/sh\necho FAIL\nexit 1\n')
        pint.chmod(0o755)
        result = subprocess.run(['bash', str(self.pint_hook()), 'file.php'], cwd=self.root,
                                capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 3)
        pint.write_text('#!/bin/sh\necho FIXED\nexit 1\n')
        result = subprocess.run(['bash', str(self.pint_hook()), 'file.php'], cwd=self.root,
                                capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 1)

    def test_staged_snapshot_survives_concurrent_writer_and_partial_staging(self):
        self.assertEqual(self.install().returncode, 0)
        path = self.root / 'file.txt'
        path.write_text('staged\n')
        self.git('add', 'file.txt')
        path.write_text('first unstaged\n')
        proc = subprocess.Popen(['git', 'commit', '-m', 'change'], cwd=self.root, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, text=True,
                                env=self.env)
        self.addCleanup(lambda: proc.kill() if proc.poll() is None else None)
        self.wait_ready(proc)
        path.write_text('writer continued\n')
        (self.events / 'continue').write_text('go')
        out, err = proc.communicate(timeout=30)
        self.assertEqual(path.read_text(), 'writer continued\n', out + err)
        self.assertEqual(proc.returncode, 0, out + err)
        self.assertEqual(self.git('show', 'HEAD:file.txt').stdout, 'staged\n')
        self.assertNotEqual(Path((self.events / 'cwd').read_text()), self.root)

    def test_formatter_failure_does_not_change_original(self):
        self.assertEqual(self.install().returncode, 0)
        path = self.root / 'file.txt'
        path.write_text('staged\n')
        self.git('add', 'file.txt')
        path.write_text('unstaged\n')
        (self.events / 'continue').write_text('go')
        result = subprocess.run(['git', 'commit', '-m', 'change'], cwd=self.root, capture_output=True, text=True,
                                env={**self.env, 'HOOK_TEST_MODIFY': '1'}, timeout=30)
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(path.read_text(), 'unstaged\n')
        self.assertEqual(self.git('show', ':file.txt').stdout, 'staged\n')
        self.assertIn('snapshot', (result.stdout + result.stderr).lower())

    def test_install_is_idempotent_and_keeps_framework_legacy(self):
        hook = self.root / '.git/hooks/pre-commit'
        hook.write_text('''#!/usr/bin/env bash
# File generated by pre-commit: https://pre-commit.com
# ID: 138fd403232d2ddd5efb44317e38bf03

# start templated
INSTALL_PYTHON=/nonexistent/python
ARGS=(hook-impl --config=.pre-commit-config.yaml --hook-type=pre-commit)
# end templated

HERE="$(cd "$(dirname "$0")" && pwd)"
ARGS+=(--hook-dir "$HERE" -- "$@")

if [ -x "$INSTALL_PYTHON" ]; then
    exec "$INSTALL_PYTHON" -mpre_commit "${ARGS[@]}"
elif command -v pre-commit > /dev/null; then
    exec pre-commit "${ARGS[@]}"
else
    echo '`pre-commit` not found.  Did you forget to activate your virtualenv?' 1>&2
    exit 1
fi
''')
        hook.chmod(0o755)
        legacy = self.root / '.git/hooks/pre-commit.legacy'
        legacy.write_text('#!/bin/sh\npwd > "$HOOK_TEST_EVENTS/legacy"\n')
        legacy.chmod(0o755)
        original_legacy = legacy.read_bytes()
        self.assertEqual(self.install().returncode, 0)
        installed = hook.read_bytes()
        self.assertEqual(self.install().returncode, 0)
        self.assertEqual(hook.read_bytes(), installed)
        self.assertEqual(legacy.read_bytes(), original_legacy)
        (self.root / 'file.txt').write_text('changed\n')
        self.git('add', 'file.txt')
        (self.events / 'continue').write_text('go')
        result = subprocess.run(['git', 'commit', '-m', 'change'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertNotEqual(Path((self.events / 'legacy').read_text().strip()), self.root)

    def test_install_refuses_overwriting_two_existing_hooks(self):
        hook = self.root / '.git/hooks/pre-commit'
        legacy = self.root / '.git/hooks/pre-commit.legacy'
        hook.write_text('#!/bin/sh\nexit 0\n')
        legacy.write_text('#!/bin/sh\nexit 1\n')
        before = hook.read_bytes(), legacy.read_bytes()
        result = self.install()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((hook.read_bytes(), legacy.read_bytes()), before)

    def test_existing_hook_is_chained_in_snapshot(self):
        hook = self.root / '.git/hooks/pre-commit'
        hook.write_text('#!/bin/sh\npwd > "$HOOK_TEST_EVENTS/legacy"\nprintf "%s" "$0" > "$HOOK_TEST_EVENTS/legacy-script"\n')
        hook.chmod(0o755)
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('changed\n')
        self.git('add', 'file.txt')
        (self.events / 'continue').write_text('go')
        result = subprocess.run(['git', 'commit', '-m', 'change'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertNotEqual(Path((self.events / 'legacy').read_text().strip()), self.root)
        self.assertNotEqual(Path((self.events / 'legacy-script').read_text()), hook)

    def test_intent_to_add_is_not_changed_or_stashed(self):
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('staged\n')
        (self.root / 'new.txt').write_text('unadded user content\n')
        self.git('add', 'file.txt')
        self.git('add', '-N', 'new.txt')
        (self.events / 'continue').write_text('go')
        result = subprocess.run(['git', 'commit', '-m', 'change'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.root / 'new.txt').read_text(), 'unadded user content\n')
        self.assertIn('new.txt', self.git('status', '--porcelain').stdout)

    def test_staged_symlink_is_refused_without_following_it(self):
        self.assertEqual(self.install().returncode, 0)
        target = Path(self.temp.name) / 'outside'
        target.write_text('preserve me\n')
        (self.root / 'file.txt').unlink()
        (self.root / 'file.txt').symlink_to(target)
        self.git('add', 'file.txt')
        result = subprocess.run(['git', 'commit', '-m', 'symlink'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('symlink', result.stderr)
        self.assertEqual(target.read_text(), 'preserve me\n')
        self.assertFalse((self.events / 'ready').exists())

    def test_unchanged_outside_symlink_cannot_overwrite_concurrent_writer(self):
        outside = self.events / 'outside'
        outside.write_text('original\n')
        (self.root / 'escape').symlink_to(outside)
        self.git('add', 'escape')
        self.git('commit', '-qm', 'tracked symlink')
        legacy = self.root / '.git/hooks/pre-commit'
        legacy.write_text('''#!/usr/bin/env python3
import os, pathlib, time
p = pathlib.Path(os.environ['HOOK_TEST_EVENTS'])
(p / 'legacy-ready').write_text('ready')
while not (p / 'writer-done').exists(): time.sleep(0.01)
pathlib.Path('escape').write_text('legacy overwrote concurrent writer\\n')
raise SystemExit(1)
''')
        legacy.chmod(0o755)
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        writer = subprocess.Popen(['python3', '-c', '''import pathlib, sys, time
p = pathlib.Path(sys.argv[1])
while not (p / 'start-writer').exists(): time.sleep(0.01)
(p / 'outside').write_text('writer continued\\n')
(p / 'writer-done').write_text('done')
''', str(self.events)])
        self.addCleanup(lambda: writer.kill() if writer.poll() is None else None)
        hook = subprocess.Popen([str(self.root / '.git/hooks/pre-commit')], cwd=self.root,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=self.env)
        self.addCleanup(lambda: hook.kill() if hook.poll() is None else None)
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline and hook.poll() is None and not (self.events / 'legacy-ready').exists():
            time.sleep(0.02)
        (self.events / 'start-writer').write_text('go')
        writer.wait(timeout=30)
        out, err = hook.communicate(timeout=30)
        self.assertNotEqual(hook.returncode, 0, out + err)
        self.assertEqual(outside.read_text(), 'writer continued\n')
        self.assertFalse((self.events / 'legacy-ready').exists(), out + err)

    def test_staged_composer_lock_cannot_use_the_old_installed_graph(self):
        package = {'name': 'fixture/package', 'version': '1.0.0'}
        (self.root / 'composer.json').write_text('{}\n')
        (self.root / 'composer.lock').write_text(json.dumps({'packages': [package], 'packages-dev': []}))
        (self.root / 'vendor/composer').mkdir(parents=True)
        (self.root / 'vendor/bin').mkdir()
        (self.root / 'vendor/autoload.php').write_text('')
        (self.root / 'vendor/composer/installed.json').write_text(json.dumps({'packages': [package], 'dev': True}))
        self.git('add', 'composer.json', 'composer.lock')
        self.git('commit', '-qm', 'old dependencies')
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'composer.lock').write_text(json.dumps({'packages': [{'name': 'fixture/package', 'version': '2.0.0'}], 'packages-dev': []}))
        self.git('add', 'composer.lock')
        (self.events / 'continue').write_text('go')
        result = subprocess.run([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                text=True, capture_output=True, timeout=30)
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse((self.events / 'ready').exists(), result.stdout + result.stderr)

    def test_dependency_snapshot_is_not_writable_through_a_tracked_symlink(self):
        (self.root / 'node_modules').mkdir()
        (self.root / 'node_modules/target').write_text('preserved\n')
        (self.root / 'package.json').write_text('{"name":"fixture","version":"1.0.0"}')
        (self.root / 'package-lock.json').write_text(json.dumps({'name': 'fixture', 'version': '1.0.0', 'lockfileVersion': 3, 'packages': {'': {'name': 'fixture', 'version': '1.0.0'}}}))
        (self.root / 'node_modules/.package-lock.json').write_text(json.dumps({'name': 'fixture', 'lockfileVersion': 3, 'packages': {}}))
        (self.root / 'escape').symlink_to('node_modules/target')
        self.git('add', 'escape', 'package.json', 'package-lock.json')
        self.git('commit', '-qm', 'link')
        legacy = self.root / '.git/hooks/pre-commit'
        legacy.write_text("#!/bin/sh\nprintf 'changed\\n' > escape\nexit 1\n")
        legacy.chmod(0o755)
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        result = subprocess.run([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                text=True, capture_output=True, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('legacy hook failed', result.stderr)
        self.assertFalse((self.events / 'ready').exists())
        self.assertEqual((self.root / 'node_modules/target').read_text(), 'preserved\n')

    def test_concurrent_dependency_writer_does_not_change_hook_snapshot(self):
        (self.root / 'node_modules').mkdir()
        (self.root / 'node_modules/target').write_text('snapshot value\n')
        (self.root / 'package.json').write_text('{"name":"fixture","version":"1.0.0"}')
        (self.root / 'package-lock.json').write_text(json.dumps({'name': 'fixture', 'version': '1.0.0', 'lockfileVersion': 3, 'packages': {'': {'name': 'fixture', 'version': '1.0.0'}}}))
        (self.root / 'node_modules/.package-lock.json').write_text(json.dumps({'name': 'fixture', 'lockfileVersion': 3, 'packages': {}}))
        (self.root / 'hook.py').write_text('''import os, pathlib, time
p = pathlib.Path(os.environ['HOOK_TEST_EVENTS'])
(p / 'ready').write_text('ready')
while not (p / 'continue').exists(): time.sleep(0.01)
(p / 'snapshot-dependency').write_text(pathlib.Path('node_modules/target').read_text())
''')
        self.git('add', 'package.json', 'package-lock.json', 'hook.py')
        self.git('commit', '-qm', 'dependency fixture')
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        hook = subprocess.Popen([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.addCleanup(lambda: hook.kill() if hook.poll() is None else None)
        self.wait_ready(hook)
        subprocess.run(['python3', '-c', 'import pathlib,sys; pathlib.Path(sys.argv[1]).write_text("writer value\\n")',
                        str(self.root / 'node_modules/target')], check=True)
        (self.events / 'continue').write_text('go')
        out, err = hook.communicate(timeout=30)
        self.assertEqual(hook.returncode, 0, out + err)
        self.assertEqual((self.events / 'snapshot-dependency').read_text(), 'snapshot value\n')
        self.assertEqual((self.root / 'node_modules/target').read_text(), 'writer value\n')

    def test_snapshot_uses_captured_symlink_when_live_dependency_is_retargeted(self):
        for name in ('a', 'b'):
            (self.root / 'packages' / name).mkdir(parents=True)
            (self.root / 'packages' / name / 'content.php').write_text(name + '\n')
        (self.root / 'vendor/fixture').mkdir(parents=True)
        (self.root / 'vendor/fixture/package').symlink_to((self.root / 'packages/a').resolve(), target_is_directory=True)
        snapshot = Path(self.temp.name) / 'snapshot'
        snapshot.mkdir()
        shutil.copytree(self.root / 'packages', snapshot / 'packages')
        script = '''import importlib.util, os, pathlib, sys, time
root, snapshot, events, source = map(pathlib.Path, sys.argv[1:])
root, snapshot = root.resolve(), snapshot.resolve()
spec = importlib.util.spec_from_file_location('safe_hook', source)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
original = module.dependency_inventory
count = 0
def gated(path):
    global count
    result = original(path)
    if path == root / 'vendor':
        count += 1
        if count == 2:
            (events / 'inventory-ready').write_text('ready')
            while not (events / 'writer-done').exists(): time.sleep(0.01)
    return result
module.dependency_inventory = gated
module.copy_dependencies(root, snapshot)
print((snapshot / 'vendor/fixture/package/content.php').read_text().strip())
'''
        proc = subprocess.Popen(['python3', '-B', '-c', script, str(self.root), str(snapshot), str(self.events),
                                 str(SOURCE / 'scripts/hooks/pre-commit.py')], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.addCleanup(lambda: proc.kill() if proc.poll() is None else None)
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline and proc.poll() is None and not (self.events / 'inventory-ready').exists():
            time.sleep(0.02)
        self.assertTrue((self.events / 'inventory-ready').exists(), 'copy did not reach the retarget boundary')
        writer = '''import pathlib, sys
link, target, events = map(pathlib.Path, sys.argv[1:])
link.unlink()
link.symlink_to(target, target_is_directory=True)
(events / 'writer-done').write_text('done')
'''
        subprocess.run(['python3', '-c', writer, str(self.root / 'vendor/fixture/package'),
                        str(self.root / 'packages/b'), str(self.events)], check=True)
        out, err = proc.communicate(timeout=30)
        self.assertEqual(proc.returncode, 0, err)
        self.assertEqual(out.strip(), 'a')
        self.assertEqual((self.root / 'packages/a/content.php').read_text(), 'a\n')
        self.assertEqual((self.root / 'packages/b/content.php').read_text(), 'b\n')

    def test_staged_npm_lock_cannot_use_the_old_installed_graph(self):
        original = {'name': 'fixture', 'lockfileVersion': 3, 'packages': {'': {'name': 'fixture'}, 'node_modules/fixture': {'version': '1.0.0'}}}
        (self.root / 'package.json').write_text('{"name":"fixture"}\n')
        (self.root / 'package-lock.json').write_text(json.dumps(original))
        (self.root / 'node_modules').mkdir()
        (self.root / 'node_modules/.package-lock.json').write_text(json.dumps({'name': 'fixture', 'lockfileVersion': 3, 'packages': {'node_modules/fixture': {'version': '1.0.0'}}}))
        self.git('add', 'package.json', 'package-lock.json')
        self.git('commit', '-qm', 'old dependencies')
        self.assertEqual(self.install().returncode, 0)
        original['packages']['node_modules/fixture']['version'] = '2.0.0'
        (self.root / 'package-lock.json').write_text(json.dumps(original))
        self.git('add', 'package-lock.json')
        (self.events / 'continue').write_text('go')
        result = subprocess.run([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                text=True, capture_output=True, timeout=30)
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse((self.events / 'ready').exists(), result.stdout + result.stderr)

    def test_external_npm_path_overlay_is_refused_before_hooks(self):
        external = Path(self.temp.name) / 'external-package'
        external.mkdir()
        (external / 'package.json').write_text('{"name":"external","version":"1.0.0"}')
        (self.root / 'node_modules').mkdir()
        (self.root / 'node_modules/external').symlink_to(external, target_is_directory=True)
        package = {'resolved': '../external-package', 'link': True}
        (self.root / 'package.json').write_text('{"name":"fixture"}')
        (self.root / 'package-lock.json').write_text(json.dumps({'lockfileVersion': 3, 'packages': {'': {'name': 'fixture'}, 'node_modules/external': package}}))
        (self.root / 'node_modules/.package-lock.json').write_text(json.dumps({'lockfileVersion': 3, 'packages': {'node_modules/external': package}}))
        self.git('add', 'package.json', 'package-lock.json')
        self.git('commit', '-qm', 'external path package')
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        result = subprocess.run([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                text=True, capture_output=True, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Dependency symlink', result.stderr)
        self.assertFalse((self.events / 'ready').exists())

    def test_known_documentation_hooks_ignore_unrelated_broken_dependency_trees(self):
        self.mark_fixture_hooks_known()
        (self.root / 'node_modules').mkdir()
        (self.root / 'node_modules/broken').symlink_to('/not/a/dependency', target_is_directory=True)
        (self.root / 'vendor').mkdir()
        (self.root / 'documentation.md').write_text('documentation\n')
        self.git('add', 'documentation.md')
        self.assertEqual(self.install().returncode, 0)
        (self.events / 'continue').write_text('go')
        result = subprocess.run([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue((self.events / 'ready').exists())

    def test_legacy_hook_requires_unverified_dependency_trees_even_for_documentation(self):
        self.mark_fixture_hooks_known()
        (self.root / 'node_modules').mkdir()
        (self.root / 'node_modules/broken').symlink_to('/not/a/dependency', target_is_directory=True)
        legacy = self.root / '.git/hooks/pre-commit'
        legacy.write_text('#!/bin/sh\ntouch "$HOOK_TEST_EVENTS/legacy-ready"\n')
        legacy.chmod(0o755)
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'documentation.md').write_text('documentation\n')
        self.git('add', 'documentation.md')
        result = subprocess.run([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                text=True, capture_output=True, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.events / 'legacy-ready').exists())

    def test_changed_hook_implementation_requires_unknown_dependency_graphs(self):
        self.mark_fixture_hooks_known()
        (self.root / 'node_modules').mkdir()
        (self.root / 'node_modules/broken').symlink_to('/not/a/dependency', target_is_directory=True)
        changed_hook = self.root / 'scripts/check-debugging.py'
        changed_hook.write_text('# changed hook implementation\n')
        (self.root / 'documentation.md').write_text('documentation\n')
        self.git('add', 'scripts/check-debugging.py', 'documentation.md')
        self.assertEqual(self.install().returncode, 0)
        result = subprocess.run([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                text=True, capture_output=True, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.events / 'ready').exists())

    def test_staged_composer_path_overlay_cannot_use_a_different_manifest(self):
        package = {'name': 'fixture/package', 'version': '1.0.0'}
        (self.root / 'packages/pkg').mkdir(parents=True)
        manifest = self.root / 'packages/pkg/composer.json'
        manifest.write_text(json.dumps(package))
        (self.root / 'composer.json').write_text('{}')
        (self.root / 'composer.lock').write_text(json.dumps({'packages': [package], 'packages-dev': []}))
        (self.root / 'vendor/composer').mkdir(parents=True)
        (self.root / 'vendor/bin').mkdir()
        (self.root / 'vendor/fixture').mkdir()
        (self.root / 'vendor/fixture/package').symlink_to((self.root / 'packages/pkg').resolve(), target_is_directory=True)
        (self.root / 'vendor/autoload.php').write_text('')
        (self.root / 'vendor/composer/installed.json').write_text(json.dumps({'packages': [package], 'dev': True}))
        self.git('add', 'composer.json', 'composer.lock', 'packages/pkg/composer.json')
        self.git('commit', '-qm', 'path dependency')
        self.assertEqual(self.install().returncode, 0)
        manifest.write_text(json.dumps({**package, 'require': {'php': '^8.4'}}))
        self.git('add', 'packages/pkg/composer.json')
        manifest.write_text(json.dumps(package))
        result = subprocess.run([str(self.root / '.git/hooks/pre-commit')], cwd=self.root, env=self.env,
                                text=True, capture_output=True, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('differs from the snapshot', result.stderr)
        self.assertFalse((self.events / 'ready').exists())

    def test_legacy_failure_stops_without_running_framework_hooks(self):
        hook = self.root / '.git/hooks/pre-commit'
        hook.write_text('#!/bin/sh\nexit 3\n')
        hook.chmod(0o755)
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        result = subprocess.run(['git', 'commit', '-m', 'legacy'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('legacy hook failed', result.stderr)
        self.assertFalse((self.events / 'ready').exists())
        self.assertEqual((self.root / 'file.txt').read_text(), 'staged\n')

    def test_split_index_is_normalised_without_touching_caller_index(self):
        self.assertEqual(self.install().returncode, 0)
        self.git('update-index', '--split-index')
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        (self.events / 'continue').write_text('go')
        result = subprocess.run(['git', 'commit', '-m', 'split'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.git('show', 'HEAD:file.txt').stdout, 'staged\n')

    def test_changed_index_refuses_instead_of_checking_stale_staging(self):
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        proc = subprocess.Popen(['python3', 'scripts/hooks/pre-commit.py'], cwd=self.root, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, text=True, env=self.env)
        self.addCleanup(lambda: proc.kill() if proc.poll() is None else None)
        self.wait_ready(proc)
        (self.root / 'other.txt').write_text('newly staged\n')
        self.git('add', 'other.txt')
        (self.events / 'continue').write_text('go')
        out, err = proc.communicate(timeout=30)
        self.assertNotEqual(proc.returncode, 0, out + err)
        self.assertIn('index changed', err)
        self.assertEqual(self.git('show', ':other.txt').stdout, 'newly staged\n')

    def test_ambiguous_line_endings_are_refused(self):
        self.assertEqual(self.install().returncode, 0)
        (self.root / '.gitattributes').write_text('* text=auto\n')
        self.git('add', '.gitattributes')
        result = subprocess.run(['git', 'commit', '-m', 'attributes'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('line-ending policy', result.stderr)
        self.assertFalse((self.events / 'ready').exists())

    def test_submodule_index_entry_is_refused_before_hooks(self):
        self.assertEqual(self.install().returncode, 0)
        head = self.git('rev-parse', 'HEAD').stdout.strip()
        self.git('update-index', '--add', '--cacheinfo', '160000,' + head + ',submodule')
        result = subprocess.run(['git', 'commit', '-m', 'submodule'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('submodules', result.stderr)
        self.assertFalse((self.events / 'ready').exists())
        self.assertEqual((self.root / 'file.txt').read_text(), 'base\n')

    def test_skip_worktree_entry_is_refused_before_hooks(self):
        self.assertEqual(self.install().returncode, 0)
        self.git('update-index', '--skip-worktree', 'other.txt')
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        result = subprocess.run(['git', 'commit', '-m', 'sparse'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Skip-worktree', result.stderr)
        self.assertFalse((self.events / 'ready').exists())
        self.assertEqual((self.root / 'other.txt').read_text(), 'other base\n')

    def test_hook_skipping_environment_is_refused(self):
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('staged\n')
        self.git('add', 'file.txt')
        result = subprocess.run(['git', 'commit', '-m', 'skipped'], cwd=self.root, capture_output=True, text=True,
                                env={**self.env, 'SKIP': 'fixture'}, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Hook-skipping', result.stderr)
        self.assertFalse((self.events / 'ready').exists())

    def test_git_filter_is_refused_before_hook_execution(self):
        self.assertEqual(self.install().returncode, 0)
        (self.root / '.gitattributes').write_text('* text=auto eol=lf\nfile.txt filter=example\n')
        (self.root / 'file.txt').write_text('changed\n')
        self.git('add', '.gitattributes', 'file.txt')
        result = subprocess.run(['git', 'commit', '-m', 'filtered'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('filter', result.stderr)
        self.assertEqual((self.root / 'file.txt').read_text(), 'changed\n')
        self.assertFalse((self.events / 'ready').exists())

    def test_staged_rename_checks_destination(self):
        self.assertEqual(self.install().returncode, 0)
        self.git('mv', 'file.txt', 'renamed.txt')
        (self.events / 'continue').write_text('go')
        result = subprocess.run(['git', 'commit', '-m', 'rename'], cwd=self.root, capture_output=True, text=True,
                                env=self.env, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('renamed.txt', (self.events / 'arguments').read_text())

    def test_pathspec_commit_preserves_other_staged_file(self):
        self.assertEqual(self.install().returncode, 0)
        (self.root / 'file.txt').write_text('selected\n')
        (self.root / 'other.txt').write_text('other staged\n')
        self.git('add', 'file.txt', 'other.txt')
        (self.events / 'continue').write_text('go')
        result = subprocess.run(['git', 'commit', '-m', 'change', '--', 'file.txt'], cwd=self.root,
                                capture_output=True, text=True, env=self.env, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.git('show', 'HEAD:file.txt').stdout, 'selected\n')
        self.assertEqual(self.git('show', 'HEAD:other.txt').stdout, 'other base\n')
        self.assertEqual(self.git('show', ':other.txt').stdout, 'other staged\n')


if __name__ == '__main__':
    unittest.main(verbosity=2)
