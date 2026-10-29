import subprocess
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
HOOK = ROOT / 'scripts/check-debugging.py'


class DebuggingHookTest(unittest.TestCase):
    def scan(self, name, content):
        path = ROOT / name
        path.write_bytes(content)
        return subprocess.run(['python3', str(HOOK), name], cwd=ROOT, capture_output=True)

    def test_cli_status_is_rejected_with_scoped_guidance(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'scripts') as directory:
            path = Path(directory).relative_to(ROOT) / 'command.php'
            result = self.scan(str(path), b'<?php\n' + b'ex' + b'it(1);\n')
            self.assertEqual(result.returncode, 1)
            self.assertIn(b'legitimate CLI status', result.stderr)

    def test_debug_calls_remain_rejected_and_normal_calls_pass(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'scripts') as directory:
            path = Path(directory).relative_to(ROOT) / 'command.php'
            result = self.scan(str(path), b'<?php\n' + b'd' + b'd($value);\n')
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stderr, b'')
            self.assertEqual(self.scan(str(path), b'<?php\n$logger->debug($value);\n').returncode, 0)

    def test_non_utf8_text_retains_byte_matching(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'scripts') as directory:
            path = Path(directory).relative_to(ROOT) / 'command.php'
            result = self.scan(str(path), b'\xff' + b'd' + b'd($value);\n')
            self.assertEqual(result.returncode, 1)


if __name__ == '__main__':
    unittest.main()
