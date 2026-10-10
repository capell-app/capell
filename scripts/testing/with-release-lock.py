#!/usr/bin/env python3
"""Hold the host release gate for a checkout-owned container command."""

import fcntl
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import tempfile
from contextlib import contextmanager


def run_command(command):
    child = None
    pending = []

    def forward(number, frame):
        if child is None:
            pending.append(number)
        elif child.poll() is None:
            child.send_signal(number)

    previous = {number: signal.signal(number, forward) for number in [signal.SIGINT, signal.SIGTERM]}
    try:
        child = subprocess.Popen(command)
        for number in pending:
            if child.poll() is None:
                child.send_signal(number)
        status = child.wait()
        return status if status >= 0 else 128 - status
    finally:
        for number, handler in previous.items():
            signal.signal(number, handler)


def live_owner(handle):
    owner = os.environ.get('CAPELL_RELEASE_VERIFICATION_OWNER', '')
    if not re.fullmatch(r'[a-f0-9]{32}', owner):
        return False
    handle.seek(0)
    try:
        record = json.load(handle)
        pid = record.get('pid')
        if record.get('schema') != 1 or record.get('owner') != owner or type(pid) is not int or pid <= 1:
            return False
        os.kill(pid, 0)
    except (ValueError, AttributeError, OSError):
        return False
    return True


@contextmanager
def lease(ignore=False):
    if ignore or os.environ.get('CAPELL_NO_RELEASE_LOCK') == '1':
        yield
        return
    path = Path(os.environ.get('CAPELL_RELEASE_VERIFICATION_LOCK_PATH',
                               str(Path(tempfile.gettempdir()) / 'capell-release-verification.lock')))
    if not path.is_absolute():
        raise ValueError('Release verification lock requires an absolute path.')
    with path.open('a+') as handle:
        try:
            fcntl.flock(handle, fcntl.LOCK_SH | fcntl.LOCK_NB)
        except BlockingIOError:
            if not live_owner(handle):
                print('Waiting for full release verification to release the host gate.', file=sys.stderr, flush=True)
                fcntl.flock(handle, fcntl.LOCK_SH)
        yield


def run(command):
    with lease():
        return run_command(command)


if __name__ == '__main__':
    if len(sys.argv) < 3 or sys.argv[1] != '--':
        sys.exit('usage: python3 scripts/testing/with-release-lock.py -- <command> [args...]')
    try:
        sys.exit(run(sys.argv[2:]))
    except (OSError, ValueError) as error:
        print(f'Cannot acquire host release verification gate: {error}', file=sys.stderr)
        sys.exit(2)
