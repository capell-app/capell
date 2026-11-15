import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts' / 'ship-pr.sh'

FAKE_GH = r'''#!/usr/bin/env bash
state="$FAKE_GH_DIR"
case "$1 $2" in
  "pr checks") cat "$state/checks" ;;
  "pr edit") echo "$*" >> "$state/calls" ;;
  "pr view") cat "$state/view" ;;
  "api graphql") cat "$state/ejections" ;;
esac
'''


class ShipPrTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='ship-pr-test-')
        self.addCleanup(self.temp.cleanup)
        self.dir = Path(self.temp.name)
        fake = self.dir / 'gh'
        fake.write_text(FAKE_GH)
        fake.chmod(fake.stat().st_mode | stat.S_IEXEC)
        (self.dir / 'view').write_text('OPEN ready-to-merge\n')
        (self.dir / 'ejections').write_text('QUEUED\n')

    def run_ship(self, checks, view='MERGED ', ejections='QUEUED'):
        (self.dir / 'checks').write_text(checks)
        (self.dir / 'view').write_text(view + '\n')
        (self.dir / 'ejections').write_text(ejections + '\n')
        return subprocess.run(
            ['bash', str(SCRIPT), '1'],
            env={**os.environ, 'GH': str(self.dir / 'gh'), 'FAKE_GH_DIR': str(self.dir),
                 'SHIP_POLL_SECONDS': '0', 'SHIP_GATE_POLLS': '3', 'SHIP_QUEUE_POLLS': '3'},
            capture_output=True, text=True, timeout=60, check=False,
        )

    def labelled(self):
        calls = self.dir / 'calls'
        return calls.exists() and '--add-label ready-to-merge' in calls.read_text()

    def test_labels_a_pull_request_that_runs_no_shard_checks(self):
        result = self.run_ship('Content guards\tpass\t5s\tu\nPHP Quality (8.4)\tskipping\t0\tu\n')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(self.labelled())
        self.assertIn('no shard checks', result.stdout)

    def test_labels_once_every_shard_has_passed(self):
        checks = ''.join(f'Fast PR gate / Test - L13.* - shard {n}/3\tpass\t5m\tu\n' for n in (1, 2, 3))
        result = self.run_ship(checks)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(self.labelled())
        self.assertIn('all 3 shards passed', result.stdout)

    def test_does_not_label_while_a_shard_is_pending(self):
        checks = 'Fast PR gate / Test - L13.* - shard 1/3\tpass\t5m\tu\nFast PR gate / Test - L13.* - shard 2/3\tpending\t0\tu\n'
        result = self.run_ship(checks)
        self.assertEqual(result.returncode, 4)
        self.assertFalse(self.labelled())
        self.assertIn('NEVER SETTLED', result.stdout)

    def test_does_not_label_when_any_check_failed(self):
        result = self.run_ship('Content guards\tpass\t5s\tu\nactionlint\tfail\t5s\tu\n')
        self.assertEqual(result.returncode, 5)
        self.assertFalse(self.labelled())
        self.assertIn('actionlint', result.stdout)

    def test_does_not_label_when_no_checks_exist_yet(self):
        result = self.run_ship('')
        self.assertEqual(result.returncode, 4)
        self.assertFalse(self.labelled())

    def test_reports_an_ejection_from_the_merge_queue(self):
        script = self.dir / 'gh'
        script.write_text(script.read_text().replace('cat "$state/ejections"', 'n=$(cat "$state/n" 2>/dev/null || echo 0); echo $((n+1)) > "$state/n"; [ "$n" = 0 ] && echo QUEUED || echo none'))
        result = self.run_ship('Content guards\tpass\t5s\tu\n', view='OPEN ready-to-merge')
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn('EJECTED', result.stdout)

    def test_a_queue_that_has_not_picked_the_pull_request_up_yet_is_not_an_ejection(self):
        result = self.run_ship('Content guards\tpass\t5s\tu\n', view='OPEN ready-to-merge', ejections='none')
        self.assertEqual(result.returncode, 4, result.stdout + result.stderr)
        self.assertNotIn('EJECTED', result.stdout)

    def test_reports_a_lost_label(self):
        result = self.run_ship('Content guards\tpass\t5s\tu\n', view='OPEN ')
        self.assertEqual(result.returncode, 3)
        self.assertIn('LABEL LOST', result.stdout)
