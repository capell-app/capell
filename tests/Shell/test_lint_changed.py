#!/usr/bin/env python3
"""lint-changed must format new files that git has not been told about yet."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import unittest.mock

ROOT = Path(__file__).resolve().parents[2]
# Running under an outer git hook leaks GIT_DIR, GIT_INDEX_FILE and friends into
# the fixture repositories, so `git init` and `git config` would write to the real one.
BASE_ENV = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}


class LintChangedTest(unittest.TestCase):
    def run_lint(self, root: Path, binary: Path, *arguments: str):
        env = dict(BASE_ENV, PATH=str(binary) + os.pathsep + BASE_ENV['PATH'])
        return subprocess.run(['bash', str(root / 'scripts' / 'lint-changed.sh'), *arguments], cwd=root, env=env, text=True, capture_output=True)

    def test_default_mode_formats_untracked_files(self):
        with tempfile.TemporaryDirectory(prefix='capell-lint-changed-') as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            shutil.copy(ROOT / 'scripts' / 'lint-changed.sh', root / 'scripts' / 'lint-changed.sh')
            binary = root / 'bin'
            binary.mkdir()
            calls = root / 'npx-calls.txt'
            npx = binary / 'npx'
            npx.write_text('#!/bin/sh\necho "$@" >> "%s"\n' % calls)
            npx.chmod(0o755)
            for command in (['git', 'init', '-q'], ['git', 'config', 'user.email', 'a@b.c'], ['git', 'config', 'user.name', 'n'], ['git', 'commit', '-q', '--allow-empty', '-m', 'init']):
                subprocess.run(command, cwd=root, check=True, env=BASE_ENV)
            (root / 'docs' / 'new').mkdir(parents=True)
            (root / 'docs' / 'new' / 'note.md').write_text('# Note\n')
            (root / 'ignored.log').write_text('x')
            (root / '.gitignore').write_text('ignored.log\n')

            result = self.run_lint(root, binary)

            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            recorded = calls.read_text()
            self.assertIn('docs/new/note.md', recorded)
            self.assertNotIn('ignored.log', recorded)

    def test_committed_files_are_not_reformatted_in_default_mode(self):
        with tempfile.TemporaryDirectory(prefix='capell-lint-changed-') as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            shutil.copy(ROOT / 'scripts' / 'lint-changed.sh', root / 'scripts' / 'lint-changed.sh')
            binary = root / 'bin'
            binary.mkdir()
            npx = binary / 'npx'
            npx.write_text('#!/bin/sh\necho CALLED\n')
            npx.chmod(0o755)
            (root / 'a.md').write_text('# A\n')
            for command in (['git', 'init', '-q'], ['git', 'config', 'user.email', 'a@b.c'], ['git', 'config', 'user.name', 'n'], ['git', 'add', 'a.md', 'scripts'], ['git', 'commit', '-q', '-m', 'init']):
                subprocess.run(command, cwd=root, check=True, env=BASE_ENV)

            result = self.run_lint(root, binary)

            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertNotIn('CALLED', result.stdout)

    def test_fixture_setup_ignores_inherited_git_environment(self):
        with tempfile.TemporaryDirectory(prefix='capell-lint-changed-decoy-') as directory:
            decoy = Path(directory) / 'decoy.git'
            subprocess.run(['git', 'init', '-q', '--bare', str(decoy)], check=True, env=BASE_ENV)
            before = (decoy / 'config').read_text()
            leaked = dict(os.environ, GIT_DIR=str(decoy))

            with unittest.mock.patch.dict(os.environ, leaked, clear=True):
                self.test_default_mode_formats_untracked_files()

            self.assertEqual((decoy / 'config').read_text(), before)


if __name__ == '__main__':
    unittest.main()
