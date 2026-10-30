#!/usr/bin/env python3
"""Run staged hooks in a private Git snapshot, never stashing the worktree."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile


class HookFailure(RuntimeError):
    pass


# Refresh this fingerprint when reviewing a change to the repository hook set.
# Unknown configurations keep the conservative all-dependencies behaviour.
KNOWN_HOOK_CONFIG_SHA256 = '43e22aac5af2004092b4888cfb06af479002e3e055508f46f5e7afb4a8f449cd'
KNOWN_HOOK_IMPLEMENTATION_SHA256 = '4e86b25c784ccb4a10037f16efa2417cc90ab57989971113b3f343cdfa2603af'
HOOK_IMPLEMENTATIONS = (
    'scripts/guards/index-integrity.sh', 'scripts/guards/pre-commit-analysis.sh',
    'scripts/guards/pre-push-analysis.sh', 'scripts/check-debugging.py',
    '.pre-commit/pint.sh', '.pre-commit/rector.sh', '.pre-commit/prettier.sh',
    'scripts/check-composer-local-paths.php', 'tests/Shell/test_safe_pre_commit.py',
)


def hook_implementation_hash(snapshot):
    digest = hashlib.sha256()
    for name in HOOK_IMPLEMENTATIONS:
        path = snapshot / name
        digest.update(name.encode() + b'\0')
        digest.update(path.read_bytes() if path.is_file() else b'\0missing\0')
        digest.update(b'\0')
    return digest.hexdigest()


def known_hook_config(snapshot):
    config = snapshot / '.pre-commit-config.yaml'
    return (config.is_file() and hashlib.sha256(config.read_bytes()).hexdigest() == KNOWN_HOOK_CONFIG_SHA256
            and hook_implementation_hash(snapshot) == KNOWN_HOOK_IMPLEMENTATION_SHA256)


def run(command, *, cwd, env=None, input_bytes=None):
    result = subprocess.run(command, cwd=cwd, env=env, input=input_bytes, capture_output=True, check=False)
    if result.returncode:
        raise HookFailure(f"{' '.join(command[:3])} failed: {result.stderr.decode(errors='replace').strip()}")
    return result.stdout


def clean_environment():
    # Git supplies an alternate index for pathspec commits. Do not leak that
    # index or the caller's gitdir into the independent snapshot repository.
    return {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}


def validate_symlinks(snapshot, names):
    for name in names:
        link = snapshot / os.fsdecode(name)
        if not link.is_symlink() or not link.resolve().is_relative_to(snapshot.resolve()):
            raise HookFailure(f'Symlink {os.fsdecode(name)!r} leaves the isolated snapshot.')


def dependency_inventory(directory):
    entries = {}
    for base, directories, files in os.walk(directory, followlinks=False):
        for name in sorted(directories + files):
            path = Path(base) / name
            relative = str(path.relative_to(directory))
            if path.is_symlink():
                entries[relative] = ('link', os.readlink(path))
            elif path.is_dir():
                entries[relative] = ('directory', stat.S_IMODE(path.stat().st_mode))
            elif path.is_file():
                digest = hashlib.sha256()
                with path.open('rb') as handle:
                    for chunk in iter(lambda: handle.read(1024 * 1024), b''):
                        digest.update(chunk)
                metadata = path.stat()
                entries[relative] = ('file', metadata.st_size, digest.hexdigest(), stat.S_IMODE(metadata.st_mode))
            else:
                raise HookFailure(f'Unsupported dependency file type: {path}.')
    return entries


def copy_dependencies(root, snapshot, *, include_vendor=True, include_node=True):
    names = (['vendor'] if include_vendor else []) + (['node_modules'] if include_node else [])
    sources = [(name, root / name) for name in names if (root / name).is_dir()]
    inventories = {}
    total = 0
    for name, source in sources:
        if source.is_symlink():
            raise HookFailure(f'{name} is a shared dependency symlink; it cannot be isolated safely.')
        entries = dependency_inventory(source)
        inventories[name] = entries
        for relative, info in entries.items():
            if info[0] == 'file':
                total += info[1]
            elif info[0] == 'link':
                path = source / relative
                target = Path(os.path.abspath(os.path.join(path.parent, info[1])))
                if not target.is_relative_to(root):
                    raise HookFailure(f'Dependency symlink {path} leaves the checkout; it cannot be isolated safely.')
    if shutil.disk_usage(snapshot).free < total * 2 + 128 * 1024 * 1024:
        raise HookFailure('Insufficient temporary disk space for an isolated dependency snapshot.')
    for name, source in sources:
        destination = snapshot / name
        shutil.copytree(source, destination, symlinks=True)
        if dependency_inventory(destination) != inventories[name] or dependency_inventory(source) != inventories[name]:
            raise HookFailure(f'{name} changed while its dependency snapshot was copied; retry after writers stop.')
    for name, source in sources:
        destination = snapshot / name
        for relative, info in inventories[name].items():
            if info[0] != 'link':
                continue
            target = Path(os.path.abspath(os.path.join((source / relative).parent, info[1])))
            isolated_target = snapshot / target.relative_to(root)
            link = destination / relative
            link.unlink()
            link.symlink_to(os.path.relpath(isolated_target, link.parent))
    for name, source in sources:
        destination = snapshot / name
        for relative, info in inventories[name].items():
            if info[0] != 'link':
                continue
            link = destination / relative
            if not link.resolve().is_relative_to(snapshot.resolve()) or not link.exists():
                raise HookFailure(f'Dependency symlink {source / relative} has no safe snapshot target.')


def validate_dependencies(root, snapshot, env):
    vendor = snapshot / 'vendor'
    if vendor.is_dir():
        lock = snapshot / 'composer.lock'
        installed = vendor / 'composer/installed.json'
        if not lock.is_file() or not installed.is_file() or not (snapshot / 'composer.json').is_file():
            raise HookFailure('Composer snapshot and installed dependency metadata are required.')
        locked = json.loads(lock.read_text())
        actual = json.loads(installed.read_text())
        expected_packages = {p['name']: p for p in locked['packages'] + locked.get('packages-dev', [])}
        installed_packages = {p['name']: p for p in actual['packages']}
        if expected_packages.keys() != installed_packages.keys():
            raise HookFailure('Installed Composer dependencies differ from the snapshot lock file.')
        for name, expected in expected_packages.items():
            actual_package = installed_packages[name]
            for field in ('version', 'source', 'dist'):
                if expected.get(field) != actual_package.get(field):
                    raise HookFailure(f'Installed Composer dependency {name} differs from the snapshot lock file.')
            path = vendor / name
            manifest = path / 'composer.json'
            if not path.is_dir() or not manifest.is_file() or json.loads(manifest.read_text()).get('name') != name:
                raise HookFailure(f'Installed Composer dependency {name} is missing or has a mismatched manifest.')
            if path.is_symlink():
                resolved = path.resolve()
                if not resolved.is_relative_to(snapshot.resolve()):
                    raise HookFailure(f'Composer path dependency {name} is outside the snapshot.')
                # A path package's manifest participates in dependency resolution.
                original = root / resolved.relative_to(snapshot.resolve()) / 'composer.json'
                if not original.is_file() or original.read_bytes() != (resolved / 'composer.json').read_bytes():
                    raise HookFailure(f'Composer path dependency {name} differs from the snapshot.')
        composer = shutil.which('composer', path=env.get('PATH'))
        if composer is None or subprocess.run([composer, 'validate', '--check-lock', '--no-check-all', '--no-check-publish', '--no-plugins', '--no-scripts', '--no-interaction'], cwd=snapshot, env=env, capture_output=True).returncode:
            raise HookFailure('Composer manifest and lock cannot be validated in the snapshot.')
    node_modules = snapshot / 'node_modules'
    if node_modules.is_dir():
        lock = snapshot / 'package-lock.json'
        installed = node_modules / '.package-lock.json'
        if not lock.is_file() or not installed.is_file() or not (snapshot / 'package.json').is_file():
            raise HookFailure('npm snapshot and installed dependency metadata are required.')
        expected = json.loads(lock.read_text())['packages']
        actual = json.loads(installed.read_text())['packages']
        for name, package in actual.items():
            if name not in expected or any(package.get(field) != expected[name].get(field) for field in ('version', 'resolved', 'integrity', 'link')):
                raise HookFailure(f'Installed npm dependency {name} differs from the snapshot lock file.')
            if package.get('link'):
                path = (snapshot / name).resolve()
                if not path.is_relative_to(snapshot.resolve()):
                    raise HookFailure(f'npm path dependency {name} is outside the snapshot.')
        npm = shutil.which('npm', path=env.get('PATH'))
        npm_env = dict(env, npm_config_cache=str(snapshot / '.npm-cache'), npm_config_update_notifier='false')
        if npm is None or subprocess.run([npm, 'ls', '--all', '--offline'], cwd=snapshot, env=npm_env, capture_output=True).returncode:
            raise HookFailure('Installed npm dependency graph does not match the snapshot manifest.')


def main():
    if os.environ.get('SKIP') or os.environ.get('PRE_COMMIT_ALLOW_NO_CONFIG'):
        raise HookFailure('Hook-skipping environment options are not allowed for this commit.')
    root = Path(os.fsdecode(run(['git', 'rev-parse', '--show-toplevel'], cwd=Path.cwd())).strip()).resolve()
    head = os.fsdecode(run(['git', 'rev-parse', '--verify', 'HEAD'], cwd=root)).strip()
    index_path = Path(os.fsdecode(run(['git', 'rev-parse', '--git-path', 'index'], cwd=root)).strip())
    if not index_path.is_absolute():
        index_path = root / index_path
    objects = Path(os.fsdecode(run(['git', 'rev-parse', '--git-path', 'objects'], cwd=root)).strip())
    if not objects.is_absolute():
        objects = root / objects
    objects = objects.resolve()
    legacy = Path(os.fsdecode(run(['git', 'rev-parse', '--git-path', 'hooks/pre-commit.legacy'], cwd=root)).strip())
    if not legacy.is_absolute():
        legacy = root / legacy
    object_format = os.fsdecode(run(['git', 'rev-parse', '--show-object-format'], cwd=root)).strip()
    merge_head = Path(os.fsdecode(run(['git', 'rev-parse', '--git-path', 'MERGE_HEAD'], cwd=root)).strip())
    if not merge_head.is_absolute():
        merge_head = root / merge_head
    if merge_head.exists():
        raise HookFailure('A merge is in progress; snapshot hook semantics for merge conflicts are not yet supported.')
    if not index_path.is_file() or not objects.is_dir():
        raise HookFailure('The active index or object directory is missing; no files were changed.')
    if '\n' in str(objects):
        raise HookFailure('The object directory contains a newline and cannot be used as an alternate safely.')
    initial_index = index_path.read_bytes()

    with tempfile.TemporaryDirectory(prefix='capell-pre-commit-') as directory:
        snapshot = Path(directory) / 'snapshot'
        snapshot.mkdir()
        env = clean_environment()
        preferred_php = Path('/opt/homebrew/opt/php@8.4/bin/php')
        if preferred_php.is_file() and os.access(preferred_php, os.X_OK):
            env['PATH'] = str(preferred_php.parent) + os.pathsep + env.get('PATH', '')
        empty_template = Path(directory) / 'empty-template'
        empty_template.mkdir()
        run(['git', 'init', '-q', f'--template={empty_template}', f'--object-format={object_format}', str(snapshot)], cwd=snapshot, env=env)
        (snapshot / '.git/hooks').mkdir(exist_ok=True)
        (snapshot / '.git/objects/info/alternates').write_text(str(objects) + '\n')
        # Expand split indexes while Git can still find the caller's shared index.
        staged_index = snapshot / '.git/index'
        staged_index.write_bytes(initial_index)
        original_env = dict(os.environ, GIT_INDEX_FILE=str(staged_index))
        run(['git', '-c', 'core.fsmonitor=false', 'update-index', '--no-split-index'], cwd=root, env=original_env)
        (snapshot / '.git/HEAD').write_text(head + '\n')
        flags = run(['git', 'ls-files', '-v', '-z'], cwd=snapshot, env=env)
        if any(entry[:1] in (b'S', b's', b'h') for entry in flags.split(b'\0') if entry):
            raise HookFailure('Skip-worktree or assume-unchanged entries require a normal full index before committing.')
        stages = run(['git', 'ls-files', '--stage', '-z'], cwd=snapshot, env=env)
        paths = []
        modes = {}
        for entry in stages.split(b'\0'):
            if not entry:
                continue
            metadata, name = entry.split(b'\t', 1)
            mode, _, stage = metadata.split(b' ', 2)
            if stage != b'0' or mode in (b'160000', b'040000'):
                raise HookFailure('Unmerged entries, submodules or a sparse index need a full checkout before committing.')
            paths.append(name)
            modes[name] = mode
        changed = run(['git', 'diff', '--cached', '--name-only', '--no-ext-diff', '-z', '--diff-filter=ACMRTUXB'], cwd=snapshot, env=env).split(b'\0')
        changed = [name for name in changed if name]
        if any(name not in modes for name in changed):
            raise HookFailure('A staged path is absent from the copied index; review and retry.')
        if any(modes.get(name) == b'120000' for name in changed):
            raise HookFailure('Staged symlinks are not supported by the snapshot hook; a safe supported check is required.')
        if any(name.startswith(b'-') for name in changed):
            raise HookFailure('A staged filename begins with a dash and cannot safely be passed to pre-commit.')
        if paths:
            attrs = run(['git', 'check-attr', '--cached', '-z', '--stdin', 'filter', 'text', 'eol', 'working-tree-encoding', 'ident'], cwd=root, env=original_env,
                        input_bytes=b'\0'.join(paths) + b'\0').split(b'\0')
            if len(attrs) != len(paths) * 15 + 1:
                raise HookFailure('Git returned an incomplete checkout-attribute report; no checks were run.')
            by_path = {}
            for index in range(0, len(attrs) - 1, 3):
                name, attribute, value = attrs[index:index + 3]
                by_path.setdefault(name, {})[attribute] = value
            for name, values in by_path.items():
                if any(values.get(attribute) not in (b'unspecified', b'unset') for attribute in (b'filter', b'working-tree-encoding', b'ident')):
                    raise HookFailure(f'Tracked path {os.fsdecode(name)!r} uses a Git checkout filter, encoding or ident substitution.')
                if values.get(b'text') != b'unset' and values.get(b'eol') not in (b'lf', b'crlf'):
                    raise HookFailure(f'Tracked path {os.fsdecode(name)!r} has no explicit checkout line-ending policy.')
        info_attributes = Path(os.fsdecode(run(['git', 'rev-parse', '--git-path', 'info/attributes'], cwd=root)).strip())
        if not info_attributes.is_absolute():
            info_attributes = root / info_attributes
        if info_attributes.is_file() and info_attributes.stat().st_size:
            raise HookFailure('This repository has local info/attributes; snapshot checking cannot safely reproduce them.')
        run(['git', 'checkout-index', '--all', '--force'], cwd=snapshot, env=env)
        symlinks = [name for name, mode in modes.items() if mode == b'120000']
        validate_symlinks(snapshot, symlinks)
        needs_php = any(name.endswith(b'.php') for name in changed)
        needs_prettier = any(name.endswith((b'.blade.php', b'.css', b'.js', b'.yml')) for name in changed)
        if needs_php and not (root / 'vendor').is_dir():
            raise HookFailure('A coherent host vendor is required to check staged PHP in the isolated snapshot.')
        if needs_prettier and not (root / 'node_modules').is_dir():
            raise HookFailure('node_modules is required to check staged frontend files in the isolated snapshot.')
        unknown_hooks = not known_hook_config(snapshot) or legacy.is_symlink() or (legacy.exists() and os.access(legacy, os.X_OK))
        composer_changed = any(name in (b'composer.json', b'composer.lock') or name.endswith(b'/composer.json') for name in changed)
        npm_changed = any(name in (b'package.json', b'package-lock.json') or name.endswith((b'/package.json', b'/package-lock.json')) for name in changed)
        copy_dependencies(root, snapshot, include_vendor=unknown_hooks or needs_php or composer_changed,
                          include_node=unknown_hooks or needs_prettier or npm_changed)
        validate_symlinks(snapshot, symlinks)
        validate_dependencies(root, snapshot, env)
        if needs_php and (shutil.which('php', path=env.get('PATH')) is None or not (snapshot / 'vendor/phpstan/phpstan/phpstan').is_file()):
            raise HookFailure('Host PHP and PHPStan are required; Docker cannot check the temporary snapshot.')
        if legacy.is_symlink():
            raise HookFailure('The legacy hook is a symlink; review it before committing.')
        if legacy.exists() and os.access(legacy, os.X_OK):
            if '138fd403232d2ddd5efb44317e38bf03' in legacy.read_text(errors='replace'):
                raise HookFailure('The legacy hook is another pre-commit framework entrypoint; review the hook chain before committing.')
            isolated_legacy = snapshot / '.git/hooks/pre-commit.legacy'
            shutil.copy2(legacy, isolated_legacy)
            before = run(['git', 'diff', '--no-ext-diff', '--binary'], cwd=snapshot, env=env)
            before_index = run(['git', 'write-tree'], cwd=snapshot, env=env)
            result = subprocess.run([str(isolated_legacy), *sys.argv[1:]], cwd=snapshot, env=env, check=False)
            after = run(['git', 'diff', '--no-ext-diff', '--binary'], cwd=snapshot, env=env)
            after_index = run(['git', 'write-tree'], cwd=snapshot, env=env)
            if result.returncode or before != after or before_index != after_index:
                raise HookFailure('The existing legacy hook failed or modified the staged snapshot; inspect and re-stage changes.')
        files = [os.fsdecode(name) for name in changed] or ['.pre-commit-config.yaml']
        executable = shutil.which('pre-commit')
        if executable is None:
            raise HookFailure('pre-commit is not installed. Install it before committing.')
        result = subprocess.run([executable, 'run', '--hook-stage', 'pre-commit', '--files', *files], cwd=snapshot, env=env, check=False)
        if result.returncode:
            raise HookFailure('Checks failed or formatted the isolated staged snapshot. Run the indicated formatter on your checkout, review and re-stage.')
        if index_path.read_bytes() != initial_index:
            raise HookFailure('The active commit index changed while checks were running; review and retry the commit.')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, KeyError, TypeError, HookFailure) as error:
        print(f'Capell pre-commit refused: {error}', file=sys.stderr)
        raise SystemExit(1)
