#!/usr/bin/env python3
"""Add the migration stubs of every supported Spatie release to Core's migration history.

Run with PHP 8.4 on PATH after widening a constraint in packages/core/composer.json. Hosts
publish these stubs at whatever version they locked, so ownership checks need all of them.
"""
import hashlib
import json
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TARGET = ROOT / "packages/core/database/migration-history.json"
PACKAGES = {
    "laravel-settings": "create_settings_table",
    "laravel-permission": "create_permission_tables",
    "laravel-activitylog": "create_activity_log_table",
    "laravel-medialibrary": "create_media_table",
    "laravel-tags": "create_tag_tables",
}
# Dropped from Core but still published by older installations.
RETIRED_MAJORS = {"laravel-tags": {4}}
PREPARED = {"create_media_table", "create_tag_tables"}
PREPARE = """require 'vendor/autoload.php';
echo (new Capell\\Core\\Actions\\Install\\PrepareVendorTableMigrationAction)->handle($argv[1], stream_get_contents(STDIN));"""


def supported_majors(package: str) -> set[int]:
    manifest = json.loads((ROOT / "packages/core/composer.json").read_text())
    constraint = manifest["require"].get(f"spatie/{package}", "")
    return {int(major) for major in re.findall(r"\^(\d+)", constraint)} | RETIRED_MAJORS.get(package, set())


def tags(package: str) -> list[str]:
    output = subprocess.run(
        ["git", "ls-remote", "--tags", "--refs", f"https://github.com/spatie/{package}.git"],
        check=True, capture_output=True, text=True,
    ).stdout
    return [line.rsplit("/", 1)[1] for line in output.splitlines()]


def stub(package: str, name: str, tag: str) -> bytes | None:
    url = f"https://raw.githubusercontent.com/spatie/{package}/{tag}/database/migrations/{name}.php.stub"
    try:
        with urllib.request.urlopen(url, timeout=30) as response:
            return response.read()
    except urllib.error.HTTPError as error:
        if error.code == 404:
            return None
        raise


def prepared(name: str, source: bytes) -> bytes | None:
    result = subprocess.run(["php", "-r", PREPARE, name], cwd=ROOT, input=source, capture_output=True)
    return result.stdout if result.returncode == 0 else None


def main() -> int:
    history = json.loads(TARGET.read_text())
    for package, name in PACKAGES.items():
        majors = supported_majors(package)
        if not majors:
            print(f"no supported constraint for {package}", file=sys.stderr)
            continue
        entry = history.setdefault(name, {"names": [], "sha256": []})
        hashes = set(entry["sha256"])
        for tag in tags(package):
            version = re.fullmatch(r"v?(\d+)\.\d+\.\d+", tag)
            if version is None or int(version.group(1)) not in majors:
                continue
            source = stub(package, name, tag)
            if source is None:
                continue
            hashes.add(hashlib.sha256(source).hexdigest())
            configured = prepared(name, source) if name in PREPARED else None
            if configured:
                hashes.add(hashlib.sha256(configured).hexdigest())
        entry["sha256"] = sorted(hashes)
    TARGET.write_text(json.dumps(dict(sorted(history.items())), indent=4) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
