import os
from pathlib import Path
import subprocess
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts' / 'check-merge-group-workflow-result.sh'


class MergeGroupResultTest(unittest.TestCase):
    def result(self, event, matrix='skipped', validator='success', evidence='success', reuse='false'):
        return subprocess.run(
            ['bash', str(SCRIPT)],
            env={
                **os.environ, 'EVENT_NAME': event, 'MATRIX_RESULT': matrix, 'VALIDATOR_RESULT': validator,
                'EVIDENCE_RESULT': evidence, 'REUSE': reuse,
            },
            capture_output=True, text=True, check=False,
        )

    def test_accepts_the_pull_request_placeholder(self):
        self.assertEqual(self.result('pull_request').returncode, 0)

    def test_accepts_reused_evidence_without_a_matrix_run(self):
        self.assertEqual(self.result('merge_group', matrix='skipped', reuse='true').returncode, 0)

    def test_accepts_a_successful_fallback_matrix(self):
        self.assertEqual(self.result('merge_group', matrix='success').returncode, 0)

    def test_rejects_a_fallback_without_a_passing_matrix(self):
        for matrix in ('skipped', 'cancelled', 'failure', ''):
            with self.subTest(matrix=matrix):
                self.assertNotEqual(self.result('merge_group', matrix=matrix).returncode, 0)

    def test_rejects_reuse_that_also_ran_the_matrix(self):
        self.assertNotEqual(self.result('merge_group', matrix='success', reuse='true').returncode, 0)

    def test_rejects_failed_selection_or_validator_tests(self):
        self.assertNotEqual(self.result('merge_group', evidence='failure', reuse='true').returncode, 0)
        self.assertNotEqual(self.result('merge_group', matrix='success', validator='failure').returncode, 0)
        self.assertNotEqual(self.result('push').returncode, 0)


if __name__ == '__main__':
    unittest.main()
