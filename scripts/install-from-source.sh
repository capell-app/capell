#!/usr/bin/env bash
# Install Composer dependencies from git sources for proxied/sandboxed
# environments where GitHub zipball downloads (api.github.com/codeload) are
# blocked but `git clone` works. composer.lock is always restored untouched.
set -euo pipefail

cd "$(dirname "$0")/.."

backup="$(mktemp)"
cp composer.lock "$backup"
restore() { cp "$backup" composer.lock; rm -f "$backup"; }
trap restore EXIT

composer config -g use-github-api false

# Dist-only lock entries (e.g. phpstan/phpstan) cannot be installed with
# --prefer-source. Inject a git source pointing at the same reference into the
# temporary lock; phpstan/phpstan's repository ships the phar itself.
php -r '
$lock = json_decode(file_get_contents("composer.lock"), true, flags: JSON_THROW_ON_ERROR);
foreach (["packages", "packages-dev"] as $section) {
    foreach ($lock[$section] ?? [] as $i => $package) {
        if (isset($package["source"]) || ! isset($package["dist"]["reference"])) {
            continue;
        }
        if (! preg_match("#github\.com/repos/([^/]+/[^/]+)/zipball/#", $package["dist"]["url"] ?? "", $m)) {
            continue;
        }
        $lock[$section][$i]["source"] = [
            "type" => "git",
            "url" => "https://github.com/" . $m[1] . ".git",
            "reference" => $package["dist"]["reference"],
        ];
        fwrite(STDERR, "Injected git source for " . $package["name"] . PHP_EOL);
    }
}
file_put_contents("composer.lock", json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
'

composer install --prefer-source --no-interaction "$@"
