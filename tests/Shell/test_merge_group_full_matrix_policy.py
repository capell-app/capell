import os
from pathlib import Path
import subprocess
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/check-merge-group-workflow-result.sh'


class FullMatrixRequiredPolicyTest(unittest.TestCase):
    def test_identical_tree_evidence_does_not_replace_a_merge_group_run(self):
        result = subprocess.run(
            ['bash', str(SCRIPT)],
            env={**os.environ, 'EVENT_NAME': 'merge_group', 'MATRIX_RESULT': 'skipped',
                 'VALIDATOR_RESULT': 'success', 'EVIDENCE_RESULT': 'success', 'REUSE': 'true',
                 'MATRIX_REQUIRED': 'true'},
            capture_output=True, text=True, check=False,
        )
        self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
