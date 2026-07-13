#!/usr/bin/env php
<?php

declare(strict_types=1);

[$script, $planPath] = $argv + [null, null];
if (! is_string($planPath) || ! is_file($planPath)) {
    fwrite(STDERR, "A release plan is required.\n");
    exit(1);
}
$plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
$selected = array_column($plan['packages'], null, 'name');
$temporary = sys_get_temp_dir() . '/capell-preflight-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
register_shutdown_function(static function () use ($temporary): void {
    if (! is_dir($temporary)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            unlink($item->getPathname());
        } else {
            rmdir($item->getPathname());
        }
    }
    rmdir($temporary);
});
$repositories = [];
$requirements = [];
foreach ([...($plan['external_ledger'] ?? []), ...$plan['ledger']] as $package) {
    $repositories[] = ['type' => 'vcs', 'url' => 'https://github.com/' . $package['repository'] . '.git'];
    if (isset($selected[$package['name']])) {
        [$major, $minor] = explode('.', $selected[$package['name']]['proposed_version']);
        $requirements[$package['name']] = "dev-main as {$major}.{$minor}.x-dev";
    } else {
        $requirements[$package['name']] = $package['version'];
    }
}
file_put_contents($temporary . '/composer.json', json_encode(['name' => 'capell-app/release-preflight', 'repositories' => $repositories, 'require' => $requirements, 'minimum-stability' => 'dev', 'prefer-stable' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
passthru('cd ' . escapeshellarg($temporary) . ' && composer update -W --no-interaction --no-progress --prefer-dist', $exitCode);
if ($exitCode !== 0) {
    exit($exitCode);
}
$consumer = $temporary . '/consumer';
passthru('composer create-project --no-interaction --no-progress --prefer-dist laravel/laravel ' . escapeshellarg($consumer), $exitCode);
if ($exitCode !== 0) {
    exit($exitCode);
}
foreach ($repositories as $index => $repository) {
    passthru('cd ' . escapeshellarg($consumer) . ' && composer config repositories.capell-' . $index . ' vcs ' . escapeshellarg($repository['url']), $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}
$arguments = [];
foreach ($requirements as $name => $constraint) {
    $arguments[] = escapeshellarg($name . ':' . $constraint);
}
passthru('cd ' . escapeshellarg($consumer) . ' && composer require -W --no-interaction --no-progress --prefer-dist ' . implode(' ', $arguments), $exitCode);
if ($exitCode !== 0) {
    exit($exitCode);
}
touch($consumer . '/database/database.sqlite');
passthru('cd ' . escapeshellarg($consumer) . ' && php artisan package:discover && php artisan migrate --force && php artisan capell:install --no-interaction --url=http://127.0.0.1:8000 --all-packages --theme=none --name=Preflight --email=preflight@example.test --password=release-preflight-password --clear-cache --install-welcome-route', $exitCode);
if ($exitCode !== 0) {
    exit($exitCode);
}
passthru('cd ' . escapeshellarg($consumer) . ' && php artisan serve --host=127.0.0.1 --port=8099 >/tmp/capell-release-preflight.log 2>&1 & server=$!; trap "kill $server" EXIT; for path in / /admin/login; do for attempt in 1 2 3 4 5 6 7 8 9 10; do curl --location --fail --silent http://127.0.0.1:8099$path >/dev/null && break; sleep 1; done; curl --location --fail --silent http://127.0.0.1:8099$path >/dev/null || exit 1; done', $exitCode);
exit($exitCode);
