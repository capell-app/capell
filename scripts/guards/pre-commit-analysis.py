#!/usr/bin/env python3
"""Reject additional PHPStan diagnostics without changing the index or worktree."""

from collections import Counter
import json
import os
from pathlib import Path
import re
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


def export_head(root, destination, files, head):
    """Copy HEAD inputs; link unchanged context/config/vendor without copying dependencies.

    Mirror ancestor directories so replacing a file never writes through a symlink.
    Keep config paths relative to the export, including existing ignore paths.
    Copy Core configuration so relative baseline paths resolve inside the export.
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
