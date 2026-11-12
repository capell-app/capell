#!/usr/bin/env python3
"""Reject additional PHPStan diagnostics without changing the index or worktree."""

from collections import Counter
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile


class AnalysisFailure(RuntimeError):
    pass


# PHPStan switches to an agent-oriented report when it detects an AI agent,
# which breaks the plain JSON contract this hook parses.
AGENT_ENVIRONMENT = (
    "AUGMENT_AGENT", "AMP_CURRENT_THREAD_ID", "AI_AGENT", "CURSOR_TRACE_ID", "CURSOR_AGENT",
    "GEMINI_CLI", "CODEX_SANDBOX", "CODEX_THREAD_ID", "OPENCODE_CLIENT", "OPENCODE",
    "CLAUDECODE", "CLAUDE_CODE", "REPL_ID",
)


def run(command, root):
    environment = {k: v for k, v in os.environ.items() if k not in AGENT_ENVIRONMENT and not k.startswith("CODEX_")}
    return subprocess.run(command, cwd=root, capture_output=True, text=True, check=False, env=environment)


def analyse(root, files):
    return analyse_group(root, files, "diff")


def analyse_group(root, files, configuration):
    result = run(
        ["php", "-d", "memory_limit=4G", "vendor/phpstan/phpstan/phpstan",
         "analyse", f"--configuration=phpstan/{configuration}.neon",
         "--no-progress", "--error-format=json", *files], root,
    )
    try:
        report = json.loads(result.stdout)
        if (
            result.returncode not in (0, 1)
            or report["errors"]
            or not isinstance(report["files"], dict)
            or report["totals"]["errors"] != 0
            or report["totals"]["file_errors"]
            != sum(len(file["messages"]) for file in report["files"].values())
        ):
            raise ValueError("incomplete PHPStan analysis")
        errors = []
        for filename, details in report["files"].items():
            relative = Path(filename).relative_to(root).as_posix()
            if relative not in files:
                raise ValueError(f"unexpected diagnostic file: {filename}")
            for message in details["messages"]:
                # A counted-ignore mismatch is a diagnostic, not a failed run.
                # Syntax/internal errors cannot provide a trustworthy comparison.
                if not message["ignorable"] and message.get("identifier") != "ignore.count":
                    raise ValueError(message["message"])
                normalised = re.sub(r"\s+", " ", message["message"].replace(str(root), "<repo>")).strip()
                # Anonymous-class names can contain file:line locations. Keep literal
                # numeric types/messages intact while ignoring those source positions.
                normalised = re.sub(r"(?<=\.php):\d+", ":<line>", normalised)
                key = (relative, message.get("identifier", ""), normalised)
                errors.append((key, message["line"]))
        if result.returncode != int(bool(errors)):
            raise ValueError("PHPStan exit status disagrees with its diagnostics")
        return errors
    except (ValueError, KeyError, TypeError) as error:
        raise AnalysisFailure(
            f"PHPStan failed in {root}: {error}\n{result.stdout}\n{result.stderr}"
        ) from error


def export_vendor(source, target):
    """Link installed packages but copy Composer's autoload files.

    Composer derives its base directory from the real location of vendor/composer,
    so a vendor symlink makes every autoloaded class resolve to the working tree,
    which then diverges from HEAD (for example a provider calling a method that
    only exists in the staged change). Copying vendor/autoload.php and
    vendor/composer, and linking everything else, anchors the autoload to the export.
    """
    target.mkdir()
    for child in source.iterdir():
        destination = target / child.name
        if child.name == "composer" and child.is_dir():
            destination.mkdir()
            for entry in child.iterdir():
                if entry.is_file() and not entry.is_symlink():
                    (destination / entry.name).write_bytes(entry.read_bytes())
                else:
                    (destination / entry.name).symlink_to(entry, target_is_directory=entry.is_dir())
        elif child.name == "autoload.php" and child.is_file() and not child.is_symlink():
            destination.write_bytes(child.read_bytes())
        elif child.name == "phpstan" and child.is_dir():
            # PHPStan locates the project autoloader relative to its own phar, so the
            # phar must sit inside the export or it would load the working tree's.
            destination.mkdir()
            for package in child.iterdir():
                if package.name == "phpstan":
                    export_phpstan_package(package, destination / package.name)
                else:
                    (destination / package.name).symlink_to(package, target_is_directory=package.is_dir())
        else:
            destination.symlink_to(child, target_is_directory=child.is_dir())


def export_phpstan_package(source, target):
    target.mkdir()
    for entry in source.iterdir():
        destination = target / entry.name
        if entry.name in ("phpstan", "phpstan.phar", "bootstrap.php") and entry.is_file():
            try:
                os.link(entry, destination)  # the phar is ~30 MB; avoid copying it on every commit
            except OSError:
                shutil.copyfile(entry, destination)
                shutil.copymode(entry, destination)
        else:
            destination.symlink_to(entry, target_is_directory=entry.is_dir())


def export_head(root, destination, files, head):
    """Copy HEAD inputs; link unchanged context/config without copying dependencies.

    Mirror ancestor directories so replacing a file never writes through a symlink.
    Keep config paths relative to the export, including existing ignore paths.
    Copy Core configuration so relative baseline paths resolve inside the export.
    Give the export its own Composer autoload (see export_vendor) so classes resolve
    to the export, never through a vendor symlink to the working tree.
    """
    copies = {Path(file) for file in files}

    def mirror(relative):
        source = root / relative
        target = destination / relative
        if relative.parts[0] == "phpstan" and source.is_file():
            target.write_bytes(source.read_bytes())
        elif relative in copies:
            target.write_bytes(source.read_bytes())
        elif relative == Path("phpstan") or relative.parts[0] == "phpstan" or any(relative in file.parents for file in copies):
            target.mkdir()
            for child in source.iterdir():
                mirror(child.relative_to(root))
        elif relative == Path("vendor") and source.is_dir():
            export_vendor(source, target)
        elif relative != Path("var"):
            target.symlink_to(source, target_is_directory=source.is_dir())

    for child in root.iterdir():
        mirror(child.relative_to(root))
    # Isolate HEAD analysis without clearing the working-tree PHPStan caches.
    (destination / "var").symlink_to(root / "var/phpstan/pre-commit-head")
    for file in files:
        result = subprocess.run(
            ["git", "show", f"{head}:{file}"], cwd=root, capture_output=True, check=False
        )
        if result.returncode != 0:
            raise AnalysisFailure(f"Cannot export HEAD:{file}: {result.stderr.decode()}")
        (destination / file).write_bytes(result.stdout)


def main():
    os.environ.setdefault("PHPSTAN_TURBO", "0")
    root = Path.cwd().resolve()
    files = list(dict.fromkeys(sys.argv[1:]))
    if not files:
        return 0
    try:
        for file in files:
            path = Path(file)
            if path.is_absolute() or ".." in path.parts or not path.is_file() or path.is_symlink():
                raise AnalysisFailure(f"Expected a regular repository file: {file}")
        head = run(["git", "rev-parse", "--verify", "HEAD"], root)
        if head.returncode != 0:
            raise AnalysisFailure(f"Cannot resolve HEAD: {head.stderr}")
        tracked = run(["git", "ls-tree", "-r", "--name-only", "-z", head.stdout.strip()], root)
        if tracked.returncode != 0:
            raise AnalysisFailure(f"Cannot list HEAD files: {tracked.stderr}")
        head_files = set(tracked.stdout.split("\0"))
        existing = [file for file in files if file in head_files]
        current = analyse(root, files)
        if not current:
            return 0
        previous = Counter()
        if existing:
            cache = root / "var/phpstan/pre-commit-head"
            cache.mkdir(parents=True, exist_ok=True)
            with tempfile.TemporaryDirectory(prefix="tree-", dir=cache) as directory:
                snapshot = Path(directory)
                export_head(root, snapshot, existing, head.stdout.strip())
                previous.update(key for key, _ in analyse(snapshot, existing))
        added = 0
        for key, line in current:
            if previous[key] > 0:
                previous[key] -= 1
                continue
            file, identifier, message = key
            print(f"{file}:{line}: {message} [{identifier}]", file=sys.stderr)
            added += 1
        if added:
            print(f"PHPStan: {added} new error(s) relative to HEAD.", file=sys.stderr)
            return 1
        return 0
    except (AnalysisFailure, OSError) as error:
        print(f"pre-commit PHPStan: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
