#!/usr/bin/env python3
"""Report debugging calls while explaining CLI status guard failures."""

from pathlib import Path
import re
import sys

DEBUGGING = re.compile(rb'\bdd\(|(?<!->)(?<!::)\bdebug\(|(?<!json\.)\bdump\(|\bray\(|\brd\(|\bdie\(|\bexit\(|\bddRawSql\(')
EXIT = re.compile(rb'\bexit\(')


def main(paths):
    found = False
    for name in paths:
        with Path(name).open('rb') as source:
            for number, line in enumerate(source, 1):
                if DEBUGGING.search(line):
                    found = True
                    sys.stdout.buffer.write(f'{name}:{number}:'.encode() + line.rstrip(b'\r\n') + b'\n')
                    if name.startswith('scripts/') and EXIT.search(line):
                        sys.stderr.write('The debugging guard also matches CLI ' + 'exit' + '(). If this is a legitimate CLI status, review a narrowly scoped hook exemption for this script.\n')
    return int(found)


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
