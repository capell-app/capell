#!/usr/bin/env python3
"""The Prettier commit hook must cover every file type CI's prettier:check rejects."""
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class PrettierHookCoverageTest(unittest.TestCase):
    def test_hook_formats_every_extension_ci_checks(self):
        check = json.loads((ROOT / 'composer.json').read_text())['scripts']['prettier:check']
        extensions = re.search(r'\{([^}]+)\}', check).group(1).split(',')

        config = (ROOT / '.pre-commit-config.yaml').read_text()
        hook = re.search(r'- id: prettier\n(?:\s+\w[^\n]*\n)*?\s+files: ([^\n]+)\n', config)
        self.assertIsNotNone(hook, 'the prettier hook has no files pattern')
        pattern = re.compile(hook.group(1).strip())

        missing = [extension for extension in extensions if not pattern.search('packages/core/example.' + extension)]
        self.assertEqual(missing, [], 'prettier:check covers these but the commit hook skips them')


if __name__ == '__main__':
    unittest.main()
