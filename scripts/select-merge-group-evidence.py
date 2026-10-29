#!/usr/bin/env python3
"""Decide whether a merge-group commit can reuse its pull request's Test All run.

The queue commit may reuse the run only when it carries exactly the tree the
run tested; anything else needs the complete matrix on the queue commit.
"""

import json
import sys


def select(context):
    head_sha = context.get('pr_head_sha')
    head_tree = context.get('pr_head_tree')
    group_tree = context.get('group_tree')
    if not all(isinstance(value, str) and value for value in (head_sha, head_tree, group_tree)):
        return {'reuse': 'false', 'reason': 'The pull request head or a tree could not be resolved.'}
    if head_tree != group_tree:
        return {'reuse': 'false', 'reason': 'The queue commit differs from the tested pull request tree.'}

    candidates = [
        run for run in context.get('runs', [])
        if isinstance(run, dict)
        and run.get('head_sha') == head_sha
        and run.get('event') == 'pull_request'
        and run.get('status') == 'completed'
        and run.get('conclusion') == 'success'
        and isinstance(run.get('id'), int)
        and isinstance(run.get('run_attempt'), int)
    ]
    if not candidates:
        return {'reuse': 'false', 'reason': 'No successful Test All run exists for the pull request head.'}

    run = max(candidates, key=lambda candidate: (candidate['run_attempt'], candidate['id']))
    return {
        'reuse': 'true',
        'run_id': str(run['id']),
        'run_attempt': str(run['run_attempt']),
        'reason': f"Reusing Test All run {run['id']} for the identical tree.",
    }


def main(argv):
    if len(argv) != 2:
        print('Usage: select-merge-group-evidence.py <context.json>', file=sys.stderr)
        return 2
    try:
        with open(argv[1], encoding='utf-8') as handle:
            result = select(json.load(handle))
    except (OSError, json.JSONDecodeError) as error:
        print(error, file=sys.stderr)
        return 1
    for key, value in result.items():
        print(f'{key}={value}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))
