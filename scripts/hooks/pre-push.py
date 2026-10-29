#!/usr/bin/env python3
"""Check pushed commits in an isolated checkout without stashing user edits."""
import importlib.util
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

spec = importlib.util.spec_from_file_location('safe_commit', Path(__file__).with_name('pre-commit.py'))
safe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(safe)


def git(root, *args, env=None, input_bytes=None):
    return safe.run(['git', *args], cwd=root, env=env, input_bytes=input_bytes)


def main():
    if len(sys.argv) != 3 or os.environ.get('SKIP') or os.environ.get('PRE_COMMIT_ALLOW_NO_CONFIG'):
        raise safe.HookFailure('Invalid push arguments or hook-skipping environment options.')
    remote, url = sys.argv[1:]
    incoming = sys.stdin.buffer.read()
    root = Path(os.fsdecode(git(Path.cwd(), 'rev-parse', '--show-toplevel')).strip()).resolve()
    head = git(root, 'rev-parse', '--verify', 'HEAD').decode().strip()
    objects = Path(os.fsdecode(git(root, 'rev-parse', '--git-path', 'objects')).strip())
    objects = (root / objects).resolve()
    common = Path(os.fsdecode(git(root, 'rev-parse', '--git-common-dir')).strip())
    common = (root / common).resolve()
    git_dir = git(root, 'rev-parse', '--absolute-git-dir').decode().strip()
    legacy = common / 'hooks/pre-push.legacy'
    info = common / 'info/attributes'
    if '\n' in str(objects) or (info.is_file() and info.stat().st_size):
        raise safe.HookFailure('Local checkout attributes or the object path cannot safely be reproduced.')
    updates = []
    for line in incoming.decode().splitlines():
        local, target, branch, previous = line.rsplit(maxsplit=3)
        if set(target) == {'0'}:
            continue
        if set(previous) != {'0'} and subprocess.run(['git', 'cat-file', '-e', previous], cwd=root, capture_output=True).returncode == 0:
            start = previous
        else:
            ancestors = git(root, 'rev-list', target, '--topo-order', '--reverse', '--not', f'--remotes={remote}').decode().splitlines()
            if not ancestors:
                continue
            roots = git(root, 'rev-list', '--max-parents=0', target).decode().splitlines()
            start = None if ancestors[0] in roots else git(root, 'rev-parse', f'{ancestors[0]}^').decode().strip()
        updates.append((local, target, branch, start))
    if not updates and not legacy.exists():
        return 0
    fmt = git(root, 'rev-parse', '--show-object-format').decode().strip()
    env = safe.clean_environment()
    php = Path('/opt/homebrew/opt/php@8.4/bin/php')
    if php.is_file() and os.access(php, os.X_OK):
        env['PATH'] = str(php.parent) + os.pathsep + env.get('PATH', '')
    jobs = [(head, [], True)] if legacy.exists() or legacy.is_symlink() else []
    for update in updates:
        target_commit = git(root, 'rev-parse', f'{update[1]}^{{commit}}').decode().strip()
        jobs.append((target_commit, [update], False))
    for snapshot_head, snapshot_updates, run_legacy in jobs:
        with tempfile.TemporaryDirectory(prefix='capell-pre-push-') as directory:
            snapshot = (Path(directory) / 'snapshot').resolve()
            snapshot.mkdir()
            template = Path(directory) / 'empty-template'
            template.mkdir()
            git(snapshot, 'init', '-q', f'--template={template}', f'--object-format={fmt}', str(snapshot), env=env)
            (snapshot / '.git/hooks').mkdir(exist_ok=True)
            (snapshot / '.git/objects/info/alternates').write_text(str(objects) + '\n')
            (snapshot / '.git/HEAD').write_text(snapshot_head + '\n')
            git(snapshot, 'read-tree', snapshot_head, env=env)
            entries = git(snapshot, 'ls-files', '--stage', '-z', env=env).split(b'\0')
            names = []
            symlinks = []
            for entry in filter(None, entries):
                metadata, name = entry.split(b'\t', 1)
                mode = metadata.split(b' ', 1)[0]
                if mode in (b'160000', b'040000') or name.startswith(b'-'):
                    raise safe.HookFailure('Submodules, sparse entries and leading-dash paths are unsupported by the push snapshot.')
                if mode == b'120000':
                    symlinks.append(os.fsdecode(name))
                names.append(name)
            if names:
                attrs = git(snapshot, 'check-attr', '--cached', '-z', '--stdin', 'filter', 'text', 'eol', 'working-tree-encoding', 'ident', env=env, input_bytes=b'\0'.join(names) + b'\0').split(b'\0')
                if len(attrs) != len(names) * 15 + 1:
                    raise safe.HookFailure('Incomplete checkout attributes.')
                values = {}
                for i in range(0, len(attrs) - 1, 3):
                    name, attr, value = attrs[i:i+3]
                    values.setdefault(name, {})[attr] = value
                for name, row in values.items():
                    if any(row.get(a) not in (b'unspecified', b'unset') for a in (b'filter', b'working-tree-encoding', b'ident')) or (row.get(b'text') != b'unset' and row.get(b'eol') not in (b'lf', b'crlf')):
                        raise safe.HookFailure(f'Unsupported checkout attributes for {os.fsdecode(name)!r}.')
            git(snapshot, 'checkout-index', '--all', '--force', env=env)
            safe.validate_symlinks(snapshot, symlinks)
            unknown_hooks = not safe.known_hook_config(snapshot) or run_legacy
            safe.copy_dependencies(root, snapshot, include_vendor=True, include_node=unknown_hooks)
            safe.validate_symlinks(snapshot, symlinks)
            safe.validate_dependencies(root, snapshot, env)
            # The guard resolves its analysis base and origin inside this private repository.
            source_env = dict(env, GIT_DIR=git_dir, GIT_WORK_TREE=str(root))
            origin = subprocess.run(['git', 'remote', 'get-url', 'origin'], cwd=snapshot, env=source_env, capture_output=True, text=True)
            if origin.returncode == 0:
                git(snapshot, 'config', '--local', 'remote.origin.url', origin.stdout.strip(), env=env)
            base = os.environ.get('CAPELL_PRE_PUSH_ANALYSIS_BASE', 'origin/main')
            resolved = subprocess.run(['git', 'rev-parse', '--verify', '--quiet', base], cwd=root, capture_output=True, text=True)
            if resolved.returncode == 0:
                env['CAPELL_PRE_PUSH_ANALYSIS_BASE'] = resolved.stdout.strip()
            # The existing analysis guard refuses dirty PHP in the real checkout.
            # Its copy inside the snapshot cannot see those edits, so enforce the
            # same boundary here as well as running the guard itself below.
            def normalise(value):
                value = re.sub(r'^git@github\.com:', 'https://github.com/', value)
                return re.sub(r'/$', '', re.sub(r'\.git$', '', value))

            if (snapshot / 'scripts/guards/pre-push-analysis.sh').is_file():
                if origin.returncode:
                    raise safe.HookFailure('Cannot resolve origin for the existing pre-push analysis guard.')
                if normalise(url) == normalise(origin.stdout.strip()) and any(branch.startswith('refs/heads/') for _, _, branch, _ in snapshot_updates):
                    for _, target, branch, _ in snapshot_updates:
                        if branch.startswith('refs/heads/') and git(root, 'rev-parse', f'{target}^{{commit}}').decode().strip() != head:
                            raise safe.HookFailure('The pushed branch commit is not checked out; check it out before pushing.')
                    if resolved.returncode:
                        raise safe.HookFailure(f'Unknown pre-push analysis base {base}; fetch it or set CAPELL_PRE_PUSH_ANALYSIS_BASE.')
                    changed = git(root, 'diff', '--name-only', '--diff-filter=ACMR', '-z', f'{resolved.stdout.strip()}...HEAD').split(b'\0')
                    paths = [os.fsdecode(name) for name in changed if re.match(rb'^(?:packages|tests)/.+\.php$|^scripts/benchmark-boot.*\.php$', name) and (snapshot / os.fsdecode(name)).is_file()]
                    if paths and git(root, 'diff', '--name-only', '-z', 'HEAD', '--', *paths):
                        raise safe.HookFailure('Changed PHP files have uncommitted edits; commit or stash them before pushing.')
            if run_legacy and legacy.is_symlink():
                raise safe.HookFailure('Legacy pre-push hook is a symlink; review it first.')
            if run_legacy and legacy.exists() and os.access(legacy, os.X_OK):
                if '138fd403232d2ddd5efb44317e38bf03' in legacy.read_text(errors='replace'):
                    raise safe.HookFailure('Legacy pre-push is another framework hook; review it first.')
                isolated = snapshot / '.git/hooks/pre-push.legacy'
                shutil.copy2(legacy, isolated)
                before = git(snapshot, 'write-tree', env=env)
                result = subprocess.run([str(isolated), remote, url], cwd=snapshot, env=env, input=incoming, check=False)
                if result.returncode or before != git(snapshot, 'write-tree', env=env) or git(snapshot, 'diff', '--no-ext-diff', '--binary', env=env):
                    raise safe.HookFailure('Legacy pre-push failed or modified the isolated snapshot.')
            executable = shutil.which('pre-commit')
            if executable is None:
                raise safe.HookFailure('pre-commit is not installed.')
            for local, target, branch, start in snapshot_updates:
                args = [executable, 'run', '--hook-stage', 'pre-push', '--remote-name', remote, '--remote-url', url, '--local-branch', local, '--remote-branch', branch]
                if start is None:
                    args += ['--all-files']
                else:
                    args += ['--from-ref', start, '--to-ref', target, '--files', '.pre-commit-config.yaml']
                if subprocess.run(args, cwd=snapshot, env=env, check=False).returncode:
                    raise safe.HookFailure('Pre-push checks failed in the isolated snapshot.')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, KeyError, TypeError, UnicodeError, safe.HookFailure) as error:
        print(f'Capell pre-push refused: {error}', file=sys.stderr)
        raise SystemExit(1)
