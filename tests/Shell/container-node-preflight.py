#!/usr/bin/env python3
"""The Docker test wrapper must detect host-native Node modules before Pest starts."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class ContainerNodePreflightTest(unittest.TestCase):
    def test_rejects_incompatible_native_modules_before_pest(self):
        with tempfile.TemporaryDirectory(prefix='capell-node-platform-') as directory:
            root = Path(directory)
            (root / 'scripts' / 'testing').mkdir(parents=True)
            shutil.copy(ROOT / 'capell', root / 'capell')
            shutil.copy(ROOT / 'scripts' / 'testing' / 'release-lock-bridge.sh', root / 'scripts' / 'testing' / 'release-lock-bridge.sh')
            (root / 'vendor' / 'bin').mkdir(parents=True)
            pest = root / 'vendor' / 'bin' / 'pest'
            pest.write_text('#!/bin/sh\n')
            pest.chmod(0o755)
            (root / 'node_modules' / 'lightningcss').mkdir(parents=True)
            binary = root / 'bin'
            binary.mkdir()
            docker = binary / 'docker'
            docker.write_text('#!/bin/sh\ncase "$*" in *"node -e"*) if [ "${NATIVE_OK:-0}" != 1 ]; then echo "wrong platform" >&2; exit 1; fi;; *"vendor/bin/pest"*) echo PEST_RAN;; esac\nexit 0\n')
            docker.chmod(0o755)
            env = dict(os.environ, PATH=str(binary) + os.pathsep + os.environ['PATH'])
            result = subprocess.run(['bash', str(root / 'capell'), 'pest', 'packages/example/tests/ExampleTest.php'], cwd=root, env=env, text=True, capture_output=True)
            self.assertEqual(result.returncode, 78, result.stdout + result.stderr)
            self.assertIn('./capell npm ci', result.stderr)
            self.assertNotIn('PEST_RAN', result.stdout)
            env['NATIVE_OK'] = '1'
            result = subprocess.run(['bash', str(root / 'capell'), 'pest', 'packages/example/tests/ExampleTest.php'], cwd=root, env=env, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('PEST_RAN', result.stdout)
            (root / 'node_modules' / 'lightningcss').rmdir()
            env.pop('NATIVE_OK')
            result = subprocess.run(['bash', str(root / 'capell'), 'pest', 'packages/example/tests/ExampleTest.php'], cwd=root, env=env, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('PEST_RAN', result.stdout)

if __name__ == '__main__':
    unittest.main()
