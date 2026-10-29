#!/usr/bin/env python3
"""Require fresh, non-empty, successful JUnit evidence for every matrix cell."""

import json
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET


def local_name(element):
    return element.tag.rsplit('}', 1)[-1]


def validate(matrix_path, evidence_root, attempt, plain=False):
    """Check every cell's report.

    Reports from a run that opted into attempt-bound names live under
    `...-attempt-N`; `plain` reads an ordinary pull-request run, whose
    artefacts carry no attempt suffix.
    """
    if not plain and not re.fullmatch(r'[1-9][0-9]*', attempt):
        raise ValueError('The run attempt must be a positive integer.')

    matrix = json.loads(Path(matrix_path).read_text(encoding='utf-8'))
    cells = matrix.get('include') if isinstance(matrix, dict) else None
    if not isinstance(cells, list) or not cells:
        raise ValueError('The Test All matrix has no cells.')

    seen_ids = set()
    seen_reports = set()
    for cell in cells:
        if not isinstance(cell, dict):
            raise ValueError('The Test All matrix contains an invalid cell.')
        for field in ('id', 'artifact_slug', 'junit'):
            value = cell.get(field)
            if not isinstance(value, str) or value in ('.', '..') or not re.fullmatch(r'[A-Za-z0-9._-]+', value):
                raise ValueError(f'A Test All cell has an invalid {field}.')

        cell_id = cell['id']
        if cell_id in seen_ids:
            raise ValueError(f'Duplicate Test All cell [{cell_id}].')
        seen_ids.add(cell_id)
        suffix = '' if plain else f'-attempt-{attempt}'
        directory = (
            Path(evidence_root) / f'sentinel{suffix}'
            if cell_id.startswith('sentinel-')
            else Path(evidence_root) / 'junit' / f"junit-{cell['artifact_slug']}{suffix}"
        )
        report = directory / cell['junit']
        if report in seen_reports:
            raise ValueError(f'Duplicate JUnit evidence path for [{cell_id}].')
        seen_reports.add(report)
        try:
            root = ET.parse(report).getroot()
        except (OSError, ET.ParseError) as error:
            raise ValueError(f'Missing or invalid JUnit evidence for [{cell_id}].') from error
        if local_name(root) not in ('testsuite', 'testsuites'):
            raise ValueError(f'Invalid JUnit root for [{cell_id}].')

        tests = [element for element in root.iter() if local_name(element) == 'testcase']
        if not tests or any(not test.get('name', '').strip() for test in tests):
            raise ValueError(f'JUnit evidence for [{cell_id}] has no named tests.')
        if not any(not any(local_name(child) == 'skipped' for child in test) for test in tests):
            raise ValueError(f'JUnit evidence for [{cell_id}] contains only skipped tests.')
        if any(local_name(child) in ('failure', 'error') for test in tests for child in test):
            raise ValueError(f'JUnit evidence for [{cell_id}] contains failures.')
        for suite in root.iter():
            if local_name(suite) not in ('testsuite', 'testsuites'):
                continue
            for field in ('errors', 'failures'):
                value = suite.get(field, '0')
                if not value.isdecimal() or int(value) != 0:
                    raise ValueError(f'JUnit evidence for [{cell_id}] reports {field}.')

    return len(seen_ids)


def main(argv):
    plain = '--plain' in argv
    args = [argument for argument in argv if argument != '--plain']
    if len(args) != 4:
        print('Usage: check-merge-group-test-evidence.py [--plain] <matrix.json> <evidence-directory> <run-attempt>', file=sys.stderr)
        return 2
    try:
        count = validate(args[1], args[2], args[3], plain)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(error, file=sys.stderr)
        return 1
    print(f'Verified completed JUnit evidence for {count} Test All cells.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))
