import os
from pathlib import Path
import subprocess
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts' / 'check-first-page-gate-result.sh'


class FirstPageGateResultTest(unittest.TestCase):
    def result(self, event, install):
        return subprocess.run(
            ['bash', str(SCRIPT)],
            env={**os.environ, 'EVENT_NAME': event, 'FIRST_PAGE_RESULT': install},
            capture_output=True, text=True, check=False,
        )

    def test_pull_request_passes_without_the_install(self):
        self.assertEqual(self.result('pull_request', 'skipped').returncode, 0)

    def test_every_other_event_needs_a_successful_install(self):
        for event in ('merge_group', 'push', 'schedule', 'workflow_dispatch'):
            self.assertEqual(self.result(event, 'success').returncode, 0)
            for install in ('skipped', 'cancelled', 'failure', ''):
                with self.subTest(event=event, install=install):
                    self.assertNotEqual(self.result(event, install).returncode, 0)


if __name__ == '__main__':
    unittest.main()
