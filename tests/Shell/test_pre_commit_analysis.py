#!/usr/bin/env python3
"""Exercise the hook against real PHPStan and immutable fixture HEAD blobs.

Run with host PHP 8.4 on PATH and python3 -B tests/Shell/test_pre_commit_analysis.py.
No commits, index changes or refs are needed for these fixtures.
"""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest

REPOSITORY = Path(__file__).resolve().parents[2]
LEGACY = "<?php\nfunction legacy(): int { return 'old'; }\n"
EXTRA = "function added(): int { return 'new'; }\n"
CLEAN = "<?php\nfunction clean(): int { return 1; }\n"


class HookTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="core-hook-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name).resolve()
        for directory in ("scripts/guards", "bin", "head", "packages", "tests", "phpstan"):
            (self.root / directory).mkdir(parents=True, exist_ok=True)
        for name in ("pre-commit-analysis.sh", "pre-commit-analysis.py"):
            shutil.copyfile(REPOSITORY / "scripts/guards" / name, self.root / "scripts/guards" / name)
        (self.root / "vendor").symlink_to(REPOSITORY / "vendor", target_is_directory=True)
        for config in ("diff",):
            (self.root / f"phpstan/{config}.neon").write_text(
                "parameters:\n    level: 9\n    tmpDir: %currentWorkingDirectory%/var/phpstan/" + config + "\n"
            )
        self.executable("bin/git", '''#!/usr/bin/env python3
from pathlib import Path
import os
import sys
root = Path(os.environ['HOOK_FIXTURE_ROOT'])
args = sys.argv[1:]
if args == ['rev-parse', '--show-toplevel']:
    print(root)
elif args == ['rev-parse', '--verify', 'HEAD']:
    print('fixture-head')
elif args[0] == 'ls-tree':
    for file in (root / 'head').rglob('*.php'):
        print(file.relative_to(root / 'head'), end='\\0')
elif args[0] == 'show':
    sys.stdout.buffer.write((root / 'head' / args[1].split(':', 1)[1]).read_bytes())
else:
    raise RuntimeError(args)
''')
        shutil.copyfile(REPOSITORY / "scripts/with-lock.php", self.root / "scripts/with-lock.php")

    def executable(self, name, content):
        path = self.root / name
        path.write_text(content)
        path.chmod(0o755)

    def write(self, file, current, head=None):
        (self.root / file).write_text(current)
        if head is not None:
            path = self.root / "head" / file
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(head)

    def run_hook(self, expected, *files):
        before = {file: (self.root / file).read_bytes() for file in files}
        started = time.monotonic()
        result = subprocess.run(
            ["bash", "scripts/guards/pre-commit-analysis.sh", *files],
            cwd=self.root, capture_output=True, text=True, timeout=60,
            env={**os.environ, "PATH": f"{self.root}/bin:{os.environ['PATH']}",
                 "HOOK_FIXTURE_ROOT": str(self.root), "AI_AGENT": "codex",
                 "CLAUDECODE": "1", "CODEX_THREAD_ID": "fixture"},
        )
        elapsed = time.monotonic() - started
        print(f"hook {files}: exit={result.returncode}, seconds={elapsed:.3f}", flush=True)
        self.assertLess(elapsed, 60)
        self.assertEqual(result.returncode, expected, result.stdout + result.stderr)
        for file in files:
            self.assertEqual((self.root / file).read_bytes(), before[file])
        self.assertFalse(list((self.root / 'var/phpstan/pre-commit-head').glob('tree-*')))
        return result.stderr

    def test_legacy_unrelated_edit(self):
        self.write('packages/Legacy.php', LEGACY + '// unrelated\n', LEGACY)
        self.assertEqual(self.run_hook(0, 'packages/Legacy.php'), '')

    def test_new_type_error(self):
        self.write('packages/Legacy.php', LEGACY + EXTRA, LEGACY)
        output = self.run_hook(1, 'packages/Legacy.php')
        self.assertIn('Function added() should return int but returns string', output)
        self.assertIn('1 new error', output)
        self.assertNotIn('Function legacy', output)

    def test_new_file(self):
        self.write('packages/New.php', LEGACY)
        self.assertIn('1 new error', self.run_hook(1, 'packages/New.php'))

    def test_clean(self):
        self.write('packages/Clean.php', CLEAN, CLEAN)
        self.assertEqual(self.run_hook(0, 'packages/Clean.php'), '')

    def test_line_shift(self):
        self.write('packages/Legacy.php', LEGACY.replace('<?php', '<?php\n\n'), LEGACY)
        self.run_hook(0, 'packages/Legacy.php')

    def test_test_configuration(self):
        self.write('tests/New.php', LEGACY)
        self.assertIn('1 new error', self.run_hook(1, 'tests/New.php'))

    def test_error_cannot_move_between_files(self):
        self.write('packages/Before.php', '<?php\n', LEGACY)
        self.write('packages/After.php', LEGACY, '<?php\n')
        self.assertIn('packages/After.php', self.run_hook(1, 'packages/Before.php', 'packages/After.php'))

    def test_duplicate_diagnostic(self):
        head = "<?php\nfunction legacy(bool $flag): int { return 'old'; }\n"
        current = "<?php\nfunction legacy(bool $flag): int { if ($flag) { return 'one'; } return 'two'; }\n"
        self.write('packages/Legacy.php', current, head)
        self.assertIn('1 new error', self.run_hook(1, 'packages/Legacy.php'))

    def test_broken_head_fails_closed(self):
        self.write('packages/Legacy.php', LEGACY, '<?php syntax error\n')
        self.assertIn('PHPStan failed', self.run_hook(1, 'packages/Legacy.php'))

    def test_counted_ignore_in_included_config(self):
        head = "<?php\nfunction legacy(bool $flag): int { return 'old'; }\n"
        current = "<?php\nfunction legacy(bool $flag): int { if ($flag) { return 'one'; } return 'two'; }\n"
        self.write('packages/Legacy.php', current, head)
        (self.root / 'phpstan/ignore.neon').write_text(
            "parameters:\n    ignoreErrors:\n        -\n"
            "            message: '#Function legacy\\(\\) should return int but returns string#'\n"
            "            identifier: return.type\n            count: 1\n"
            "            path: ../packages/Legacy.php\n"
        )
        config = self.root / 'phpstan/diff.neon'
        config.write_text('includes:\n    - ignore.neon\n' + config.read_text())
        output = self.run_hook(1, 'packages/Legacy.php')
        self.assertIn('[return.type]', output)
        self.assertIn('[ignore.count]', output)

    def test_no_files(self):
        self.run_hook(0)


if __name__ == '__main__':
    unittest.main(verbosity=2)
