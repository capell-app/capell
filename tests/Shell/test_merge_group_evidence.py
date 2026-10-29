import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'scripts' / 'check-merge-group-test-evidence.py'


class MergeGroupEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.manifest = self.root / 'matrix.json'
        self.manifest.write_text(json.dumps({'include': [
            {'id': 'sentinel-unit', 'artifact_slug': 'sentinel-unit', 'junit': 'sentinel.xml'},
            {'id': 'feature-core', 'artifact_slug': 'feature-core', 'junit': 'feature.xml'},
        ]}))
        self.sentinel = self.root / 'sentinel-attempt-2' / 'sentinel.xml'
        self.feature = self.root / 'junit' / 'junit-feature-core-attempt-2' / 'feature.xml'
        for report in (self.sentinel, self.feature):
            report.parent.mkdir(parents=True, exist_ok=True)
            report.write_text('<testsuites><testsuite><testcase name="passes"/></testsuite></testsuites>')

    def run_validator(self, attempt='2'):
        return subprocess.run(
            [sys.executable, str(SCRIPT), str(self.manifest), str(self.root), attempt],
            capture_output=True, text=True, check=False,
        )

    def test_accepts_completed_reports_for_every_cell(self):
        result = self.run_validator()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_reads_unsuffixed_artefacts_of_a_pull_request_run(self):
        plain = self.root / 'plain'
        for report, relative in (
            (self.sentinel, 'sentinel/sentinel.xml'),
            (self.feature, 'junit/junit-feature-core/feature.xml'),
        ):
            target = plain / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(report.read_text())
        result = subprocess.run(
            [sys.executable, str(SCRIPT), '--plain', str(self.manifest), str(plain), '1'],
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        (plain / 'junit/junit-feature-core/feature.xml').unlink()
        result = subprocess.run(
            [sys.executable, str(SCRIPT), '--plain', str(self.manifest), str(plain), '1'],
            capture_output=True, text=True, check=False,
        )
        self.assertNotEqual(result.returncode, 0)

    def test_rejects_missing_and_invalid_reports(self):
        for content in (
            None, '', '<broken', '<testsuites/>', '<html><testcase/></html>',
            '<testsuites><testsuite><testcase/></testsuite></testsuites>',
        ):
            with self.subTest(content=content):
                if content is None:
                    self.feature.unlink(missing_ok=True)
                else:
                    self.feature.write_text(content)
                self.assertNotEqual(self.run_validator().returncode, 0)

    def test_rejects_evidence_from_another_attempt(self):
        self.assertNotEqual(self.run_validator('3').returncode, 0)
        self.assertNotEqual(self.run_validator('0').returncode, 0)

    def test_rejects_failures_errors_and_only_skipped_tests(self):
        for child in ('<failure/>', '<error/>', '<skipped/>'):
            with self.subTest(child=child):
                self.feature.write_text(f'<testsuites><testsuite><testcase name="fixture">{child}</testcase></testsuite></testsuites>')
                self.assertNotEqual(self.run_validator().returncode, 0)
        self.feature.write_text('<testsuites><testsuite errors="1"><testcase name="fixture"/></testsuite></testsuites>')
        self.assertNotEqual(self.run_validator().returncode, 0)

    def test_rejects_empty_or_unsafe_matrix(self):
        for cells in (
            [],
            [{'id': 'x', 'artifact_slug': '../escape', 'junit': 'x.xml'}],
            [
                {'id': 'feature-core', 'artifact_slug': 'feature-core', 'junit': 'feature.xml'},
                {'id': 'another-cell', 'artifact_slug': 'feature-core', 'junit': 'feature.xml'},
            ],
        ):
            with self.subTest(cells=cells):
                self.manifest.write_text(json.dumps({'include': cells}))
                self.assertNotEqual(self.run_validator().returncode, 0)


if __name__ == '__main__':
    unittest.main()
