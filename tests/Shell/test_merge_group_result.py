import os
from pathlib import Path
import subprocess
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts' / 'check-merge-group-workflow-result.sh'


class MergeGroupResultTest(unittest.TestCase):
    def result(self, event, matrix, validator, required='true'):
        return subprocess.run(
            ['bash', str(SCRIPT)],
            env={**os.environ, 'EVENT_NAME': event, 'MATRIX_RESULT': matrix, 'VALIDATOR_RESULT': validator, 'MATRIX_REQUIRED': required},
            capture_output=True, text=True, check=False,
        )

    def test_accepts_only_pr_placeholder_or_successful_merge_group(self):
        self.assertEqual(self.result('pull_request', 'skipped', 'success', 'false').returncode, 0)
        self.assertEqual(self.result('pull_request', 'success', 'success').returncode, 0)
        self.assertEqual(self.result('merge_group', 'success', 'success').returncode, 0)

    def test_rejects_skipped_cancelled_or_failed_merge_group(self):
        for result in ('skipped', 'cancelled', 'failure', ''):
            with self.subTest(result=result):
                self.assertNotEqual(self.result('merge_group', result, 'success').returncode, 0)

    def test_rejects_failed_validator_or_unexpected_event(self):
        self.assertNotEqual(self.result('merge_group', 'success', 'failure').returncode, 0)
        self.assertNotEqual(self.result('pull_request', 'failure', 'success').returncode, 0)
        self.assertNotEqual(self.result('pull_request', 'skipped', 'success', 'true').returncode, 0)
        self.assertNotEqual(self.result('merge_group', 'success', 'success', 'false').returncode, 0)

    def test_accepts_a_skipped_matrix_only_when_the_merge_group_does_not_need_it(self):
        self.assertEqual(self.result('merge_group', 'skipped', 'success', 'false').returncode, 0)
        self.assertNotEqual(self.result('merge_group', 'skipped', 'success', 'true').returncode, 0)
        self.assertNotEqual(self.result('merge_group', 'skipped', 'failure', 'false').returncode, 0)
        self.assertNotEqual(self.result('merge_group', 'failure', 'success', 'false').returncode, 0)
        self.assertNotEqual(self.result('push', 'success', 'success').returncode, 0)


if __name__ == '__main__':
    unittest.main()
