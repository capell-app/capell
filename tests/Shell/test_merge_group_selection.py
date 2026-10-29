from pathlib import Path
import importlib.util
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts' / 'select-merge-group-evidence.py'
spec = importlib.util.spec_from_file_location('select_merge_group_evidence', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def run(**overrides):
    return {
        'id': 10, 'run_attempt': 1, 'head_sha': 'abc', 'event': 'pull_request',
        'status': 'completed', 'conclusion': 'success', **overrides,
    }


def context(**overrides):
    return {'pr_head_sha': 'abc', 'pr_head_tree': 't1', 'group_tree': 't1', 'runs': [run()], **overrides}


class MergeGroupSelectionTest(unittest.TestCase):
    def test_reuses_a_successful_run_for_an_identical_tree(self):
        result = module.select(context())
        self.assertEqual(result['reuse'], 'true')
        self.assertEqual(result['run_id'], '10')
        self.assertEqual(result['run_attempt'], '1')

    def test_prefers_the_latest_attempt(self):
        result = module.select(context(runs=[run(id=10, run_attempt=2), run(id=11, run_attempt=1)]))
        self.assertEqual((result['run_id'], result['run_attempt']), ('10', '2'))

    def test_falls_back_when_the_queue_tree_differs(self):
        self.assertEqual(module.select(context(group_tree='t2'))['reuse'], 'false')

    def test_falls_back_without_a_qualifying_run(self):
        for candidate in (
            run(conclusion='failure'), run(conclusion='cancelled'), run(status='in_progress', conclusion=None),
            run(head_sha='other'), run(event='workflow_dispatch'),
        ):
            with self.subTest(candidate=candidate):
                self.assertEqual(module.select(context(runs=[candidate]))['reuse'], 'false')
        self.assertEqual(module.select(context(runs=[]))['reuse'], 'false')

    def test_falls_back_when_an_identifier_is_missing(self):
        for key in ('pr_head_sha', 'pr_head_tree', 'group_tree'):
            with self.subTest(key=key):
                self.assertEqual(module.select(context(**{key: ''}))['reuse'], 'false')


if __name__ == '__main__':
    unittest.main()
