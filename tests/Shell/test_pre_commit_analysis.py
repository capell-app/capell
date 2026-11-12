#!/usr/bin/env python3
"""Exercise the hook against real PHPStan and immutable fixture HEAD blobs.

Run with host PHP 8.4 on PATH and python3 -B tests/Shell/test_pre_commit_analysis.py.
No commits, index changes or refs are needed for these fixtures.
"""
import importlib.util
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
        # Composer requires these project files relative to the autoload base
        # directory, which the HEAD export now anchors to itself rather than to Core.
        activity = Path("packages/core/src/Support/Activity")
        shutil.copytree(REPOSITORY / activity, self.root / activity)
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
        shutil.copyfile(REPOSITORY / "scripts/heavy-admission.php", self.root / "scripts/heavy-admission.php")

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
            # Hang ceiling only: a busy host must slow the hook, never fail it.
            cwd=self.root, capture_output=True, text=True, timeout=300,
            env={**os.environ, "PATH": f"{self.root}/bin:{os.environ['PATH']}",
                 "HOOK_FIXTURE_ROOT": str(self.root), "AI_AGENT": "codex",
                 "CLAUDECODE": "1", "CODEX_THREAD_ID": "fixture",
                 # The fixture tests the hook, not host admission; the real guard cannot lease a temp tree.
                 "HEAVY_SLOT_ENABLED": "0"},
        )
        elapsed = time.monotonic() - started
        print(f"hook {files}: exit={result.returncode}, seconds={elapsed:.3f}", flush=True)
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

    def test_baseline_uses_the_exports_own_composer_autoloader(self):
        # The bootstrap throws if the HEAD export autoloads through the working
        # tree's vendor (a vendor symlink); a throw is reported as a failed analysis.
        (self.root / "boot.php").write_text(
            "<?php\n$cwd = getcwd();\n"
            "if (str_contains($cwd, 'pre-commit-head')) {\n"
            "    foreach (array_keys(Composer\\Autoload\\ClassLoader::getRegisteredLoaders()) as $vendor) {\n"
            "        if (! str_starts_with($vendor, 'phar://') && ! str_starts_with($vendor, $cwd . '/')) {\n"
            "            throw new RuntimeException('baseline autoloads outside the export: ' . $vendor);\n"
            "        }\n    }\n}\n"
        )
        config = self.root / "phpstan/diff.neon"
        config.write_text(config.read_text() + "    bootstrapFiles:\n        - %currentWorkingDirectory%/boot.php\n")
        self.write('packages/Legacy.php', LEGACY + EXTRA, LEGACY)
        output = self.run_hook(1, 'packages/Legacy.php')
        self.assertIn('1 new error', output)
        self.assertNotIn('baseline autoloads outside', output)


class ExportedBaselineAutoloadTest(unittest.TestCase):
    """The HEAD baseline must autoload HEAD's classes, not the working tree's."""

    def test_vendor_autoload_resolves_into_the_export(self):
        specification = importlib.util.spec_from_file_location(
            "pre_commit_analysis", REPOSITORY / "scripts/guards/pre-commit-analysis.py"
        )
        guard = importlib.util.module_from_spec(specification)
        specification.loader.exec_module(guard)

        with tempfile.TemporaryDirectory(prefix="core-autoload-") as directory:
            root = Path(directory).resolve() / "tree"
            (root / "src").mkdir(parents=True)
            (root / "vendor/composer").mkdir(parents=True)
            (root / "vendor/acme/pkg").mkdir(parents=True)
            (root / "var/phpstan/pre-commit-head").mkdir(parents=True)
            (root / "vendor/acme/pkg/Dependency.php").write_text("<?php\nnamespace Acme;\nclass Dependency {}\n")
            # A minimal Composer autoloader, laid out as Composer writes it: the base
            # directory is derived from the real location of vendor/composer.
            (root / "vendor/autoload.php").write_text(
                "<?php\nrequire __DIR__ . '/composer/autoload_real.php';\n"
            )
            (root / "vendor/composer/autoload_real.php").write_text(
                "<?php\n$vendorDir = dirname(__DIR__);\n$baseDir = dirname($vendorDir);\n"
                "spl_autoload_register(static function (string $class) use ($vendorDir, $baseDir): void {\n"
                "    $map = ['Fixture\\\\' => $baseDir . '/src/', 'Acme\\\\' => $vendorDir . '/acme/pkg/'];\n"
                "    foreach ($map as $prefix => $directory) {\n"
                "        if (str_starts_with($class, $prefix)) {\n"
                "            require $directory . substr($class, strlen($prefix)) . '.php';\n"
                "        }\n    }\n});\n"
            )
            head = "<?php\nnamespace Fixture;\nclass Thing { public function value(): int { return 1; } }\n"
            staged = (
                "<?php\nnamespace Fixture;\nclass Thing {\n"
                "    public function value(): int { return 1; }\n"
                "    public function bootedByProvider(): bool { return true; }\n}\n"
            )
            (root / "src/Thing.php").write_text(staged)
            git = ["git", "-c", "user.name=t", "-c", "user.email=t@example.com"]
            subprocess.run([*git[:1], "init", "-q"], cwd=root, check=True)
            subprocess.run([*git, "add", "-f", "src/Thing.php"], cwd=root, check=True)
            (root / "src/Thing.php").write_text(head)
            subprocess.run([*git, "add", "src/Thing.php"], cwd=root, check=True)
            subprocess.run([*git, "commit", "-q", "-m", "head"], cwd=root, check=True)
            (root / "src/Thing.php").write_text(staged)

            export = Path(directory).resolve() / "export"
            export.mkdir()
            head_commit = subprocess.run(
                ["git", "rev-parse", "HEAD"], cwd=root, capture_output=True, text=True, check=True
            ).stdout.strip()
            guard.export_head(root, export, ["src/Thing.php"], head_commit)

            probe = (
                "require $argv[1] . '/vendor/autoload.php';"
                "$r = new ReflectionClass(Fixture\\Thing::class);"
                "echo $r->getFileName(), '|', (int) $r->hasMethod('bootedByProvider'), '|',"
                "(new ReflectionClass(Acme\\Dependency::class))->getFileName();"
            )
            result = subprocess.run(
                ["php", "-r", probe, str(export)], capture_output=True, text=True, check=False
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            file, has_staged_method, dependency = result.stdout.split("|")
            self.assertEqual(Path(file).resolve(), (export / "src/Thing.php").resolve())
            self.assertEqual(has_staged_method, "0", "baseline autoloaded the working tree's class")
            # Installed packages stay linked, not copied.
            self.assertEqual(Path(dependency).resolve(), (root / "vendor/acme/pkg/Dependency.php").resolve())


if __name__ == '__main__':
    unittest.main(verbosity=2)
