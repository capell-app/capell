#!/usr/bin/env bash
# The judgements scripts/upgrade-path.sh makes without a real site: which data changes count as
# a loss, which release it starts from, and that a failed command fails its step.
# Real steps (composer, artisan) are replaced with fake ones, so this runs in seconds.
# shellcheck disable=SC2030,SC2031 # each case sources the script in its own subshell on purpose
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="${UPGRADE_PATH_SCRIPT:-$root/scripts/upgrade-path.sh}"
database="$root/scripts/upgrade-path/database.php"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
failures=0

check() { # description, expected, actual
    if [[ "$2" == "$3" ]]; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s (expected %s, got %s)\n' "$1" "$2" "$3"
        failures=$((failures + 1))
    fi
}

contains() { # description, file, needle
    if grep -qF -- "$3" "$2"; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s (missing "%s" in %s)\n' "$1" "$3" "$2"
        failures=$((failures + 1))
    fi
}

sqlite() { # database file, SQL
    # shellcheck disable=SC2016 # a PHP program, not shell text
    php -r '(new PDO("sqlite:" . $argv[1]))->exec($argv[2]);' "$1" "$2"
}

snapshot_after() { # name, SQL applied to a copy of the site
    cp "$scratch/site.sqlite" "$scratch/$1.sqlite"
    sqlite "$scratch/$1.sqlite" "$2"
    php "$database" snapshot "$scratch/$1.sqlite" "$scratch/$1.json" >/dev/null
}

compare() { # name, allowed changes file
    php "$database" compare "$scratch/before.json" "$scratch/$1.json" "${2:-$scratch/none.json}" >"$scratch/$1.out" 2>&1
}

echo '{"tables": {}, "columns": {}}' >"$scratch/none.json"
sqlite "$scratch/site.sqlite" "
    CREATE TABLE pages (id INTEGER PRIMARY KEY, title TEXT, summary TEXT, site_id INTEGER);
    CREATE INDEX pages_site ON pages (site_id);
    INSERT INTO pages (title, summary, site_id) VALUES ('Home', 'Welcome', 1), ('About', 'Us', 1), ('Contact', NULL, 1);
    CREATE TABLE cache (key TEXT, value TEXT);
    INSERT INTO cache VALUES ('a', 'b');
"
php "$database" snapshot "$scratch/site.sqlite" "$scratch/before.json" >/dev/null

snapshot_after added "ALTER TABLE pages ADD COLUMN subtitle TEXT; CREATE TABLE redirects (id INTEGER); INSERT INTO pages (title) VALUES ('News');"
compare added
check 'an upgrade that only adds tables, columns and rows passes' 0 $?

snapshot_after dropped-column "ALTER TABLE pages DROP COLUMN summary;"
compare dropped-column
check 'a dropped populated column fails' 1 $?
contains 'the dropped column is named' "$scratch/dropped-column.out" 'pages.summary has gone'

snapshot_after dropped-table "DROP TABLE pages;"
compare dropped-table
check 'a dropped table fails' 1 $?

snapshot_after deleted-rows "DELETE FROM pages WHERE title = 'About';"
compare deleted-rows
check 'deleted rows fail' 1 $?
contains 'the row loss is counted' "$scratch/deleted-rows.out" '3 before, 2 after'

snapshot_after blanked "UPDATE pages SET summary = NULL;"
compare blanked
check 'values blanked in a kept column fail' 1 $?

echo '{"tables": {"cache": "store is cleared"}, "columns": {"pages.summary": "moved to page blocks"}}' >"$scratch/allowed.json"
snapshot_after declared "ALTER TABLE pages DROP COLUMN summary; DELETE FROM cache;"
compare declared "$scratch/allowed.json"
check 'a removal declared in the allowed changes passes' 0 $?
compare deleted-rows "$scratch/allowed.json"
check 'an allowed change does not excuse a different loss' 1 $?

php "$database" pick-column "$scratch/before.json" "$scratch/none.json" >"$scratch/pick.out" 2>&1
check 'a droppable populated column is found for the seeded fault' 'pages summary' "$(cat "$scratch/pick.out")"

bash "$script" --inject nonsense >"$scratch/inject.out" 2>&1
check 'unknown fault exits 2' 2 $?
bash "$script" --from latest >"$scratch/from.out" 2>&1
check 'a --from that is not a released version exits 2' 2 $?

latest="$(
    # shellcheck source=/dev/null
    source "$script"
    echo '{"versions": ["dev-main", "1.x-dev", "v1.1.0-beta1", "v1.0.9", "v1.0.79", "v1.0.10"]}' | latest_stable_version
)"
check 'the starting release is the newest stable one, not a branch or pre-release' '1.0.79' "$latest"

fake_ok() { echo 'fine'; }
fake_masked() {
    false
    echo 'a later command succeeded'
}

run_fake() { # summary file, steps...
    local summary="$1"
    shift
    (
        # shellcheck source=/dev/null
        source "$script"
        parse_args --summary "$summary" --core-path "$root" --workdir "$scratch/work"
        prepare_workdir
        STEPS=("$@")
        run_pipeline
    )
}

run_fake "$scratch/ok.md" 'First step|fake_ok' 'Second step|fake_ok' >"$scratch/ok.out" 2>&1
check 'passing steps exit 0' 0 $?
contains 'summary reports the pass' "$scratch/ok.md" 'PASSED'

run_fake "$scratch/masked.md" 'Masked step|fake_masked' 'Never reached|fake_ok' >"$scratch/masked.out" 2>&1
check 'a step whose middle command fails exits 1 although its last command succeeded' 1 $?
contains 'the failed step is marked' "$scratch/masked.md" '| Masked step | failed |'
contains 'later steps are marked skipped' "$scratch/masked.md" '| Never reached | skipped |'

(
    # shellcheck source=/dev/null
    source "$script"
    parse_args --core-path "$root"
    printf '%s\n' "${STEPS[@]}"
) >"$scratch/steps-default.out" 2>&1
contains 'a normal run ends by proving a broken step is caught' "$scratch/steps-default.out" 'step_proof_broken_step'
contains 'a normal run ends by proving a destructive migration is caught' "$scratch/steps-default.out" 'step_proof_destructive_migration'

# A tiny site whose pages can be changed between the "before" and "after" requests.
cat >"$scratch/router.php" <<'ROUTER'
<?php
$pages = json_decode(file_get_contents(__DIR__ . '/site.json'), true);
$page = $pages[parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)] ?? [404, 'Not found'];
http_response_code($page[0]);
echo '<html><head><title>', $page[1], '</title></head><body><a href="/about">About</a></body></html>';
ROUTER
site() { # JSON map of path to [status, title]
    printf '%s' "$1" >"$scratch/site.json"
}
recheck() { # name, allowed changes file
    php "$pages" recheck "http://127.0.0.1:$site_port" "$scratch/pages.json" "${2:-$scratch/none.json}" public >"$scratch/$1.out" 2>&1
}
pages="$root/scripts/upgrade-path/pages.php"
site_port="${UPGRADE_PATH_TEST_PORT:-8497}"
site '{"/": [200, "Home"], "/about": [200, "About us"], "/gone": [404, "Not found"]}'
php -S "127.0.0.1:$site_port" "$scratch/router.php" >"$scratch/site.log" 2>&1 &
site_pid=$!
trap 'kill "$site_pid" 2>/dev/null; rm -rf "$scratch"' EXIT
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$site_port/" && break
    sleep 0.1
done
printf '/gone\n' >"$scratch/seeds.txt"
php "$pages" crawl "http://127.0.0.1:$site_port" "$scratch/pages.json" /admin "$scratch/seeds.txt" >"$scratch/crawl.out" 2>&1
contains 'the crawl follows a link to a page no seed names' "$scratch/crawl.out" 'page /about "About us"'

recheck unchanged
check 'an unchanged site passes, including its designed 404' 0 $?

site '{"/": [200, "Home"], "/about": [500, "Server Error"], "/gone": [404, "Not found"]}'
recheck broken-page
check 'a page that rendered before and errors after fails' 1 $?
contains 'the broken page is named' "$scratch/broken-page.out" 'FAIL /about (status 500)'

echo '{"pages": {"/about": "page retired with the team section"}}' >"$scratch/allowed-pages.json"
recheck retired-page "$scratch/allowed-pages.json"
check 'a page retired on purpose and declared passes' 0 $?

site '{"/": [200, "Home"], "/about": [200, ""], "/gone": [404, "Not found"]}'
recheck lost-title
check 'a page that renders without the title it had fails' 1 $?

site '{"/": [200, "Home"], "/about": [200, "About us"], "/gone": [500, "Server Error"]}'
recheck missing-page-errors
check 'a 404 that became a server error fails' 1 $?

if ((failures > 0)); then
    printf '%d check(s) failed\n' "$failures"
    exit 1
fi
printf 'all checks passed\n'
