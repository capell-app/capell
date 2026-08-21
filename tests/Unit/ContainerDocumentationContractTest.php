<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('publishes the exact verified container fixtures in the container guide', function (): void {
    $root = dirname(__DIR__, 2);
    $guide = (string) file_get_contents($root . '/docs/development/container-development.md');
    $fixtureRoot = $root . '/tests/fixtures/container-quickstart';
    $fixtureNames = [
        '.dockerignore',
        'Dockerfile',
        'compose.sqlite.yaml',
        'compose.production.yaml',
        'nginx.conf',
        '.env.production.example',
    ];

    preg_match_all(
        '/<!-- capell-container-fixture: ([^ ]+) -->\s*(?:<!-- prettier-ignore -->\s*)?```[^\n]*\n(.*?)\n```/s',
        $guide,
        $matches,
        PREG_SET_ORDER,
    );

    $documentedFixtures = [];

    foreach ($matches as $match) {
        $documentedFixtures[$match[1]] = $match[2] . "\n";
    }

    expect($guide)
        ->not->toContain('Status:** Skeleton')
        ->not->toContain('to be filled')
        ->toContain('php artisan capell:doctor')
        ->toContain('http://localhost:8000/admin/login')
        ->toContain('http://localhost:8000/');

    foreach ($fixtureNames as $fixtureName) {
        expect($documentedFixtures)
            ->toHaveKey($fixtureName)
            ->and($documentedFixtures[$fixtureName])
            ->toBe((string) file_get_contents($fixtureRoot . '/' . $fixtureName));
    }
});

it('keeps runtime secrets and host dependencies out of production image layers', function (): void {
    $fixtureRoot = dirname(__DIR__, 2) . '/tests/fixtures/container-quickstart';
    $dockerignore = (string) file_get_contents($fixtureRoot . '/.dockerignore');
    $dockerfile = (string) file_get_contents($fixtureRoot . '/Dockerfile');

    expect($dockerignore)
        ->toContain(".env\n")
        ->toContain(".env.*\n")
        ->toContain("vendor\n")
        ->toContain("node_modules\n")
        ->and($dockerfile)
        ->toContain("USER www-data\n");
});

it('prepares the npm lockfile before enforcing locked production builds', function (): void {
    $root = dirname(__DIR__, 2);
    $guide = (string) file_get_contents($root . '/docs/development/container-development.md');
    $smoke = (string) file_get_contents($root . '/scripts/verify-container-quickstart.sh');
    $lockfileCommand = 'npm install --package-lock-only --no-audit --no-fund';

    expect($guide)
        ->toContain($lockfileCommand)
        ->and($smoke)
        ->toContain($lockfileCommand);
});

it('gives the copied quickstart one bounded cold-start request', function (): void {
    $smoke = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/verify-container-quickstart.sh');

    expect($smoke)
        ->toContain('http_timeout_seconds="${CAPELL_CONTAINER_SMOKE_HTTP_TIMEOUT_SECONDS:-60}"')
        ->toContain('root_response_path="${smoke_parent}/response-root.html"')
        ->toContain('test -s "${root_response_path}"')
        ->not->toContain('--max-time 5')
        ->not->toContain('--max-time 10');
});

it('keeps the SQLite and production-shaped compose fixtures operationally distinct', function (): void {
    $root = dirname(__DIR__, 2) . '/tests/fixtures/container-quickstart';
    $sqlite = Yaml::parseFile($root . '/compose.sqlite.yaml');
    $production = Yaml::parseFile($root . '/compose.production.yaml');

    expect($sqlite)->toHaveKey('services.app')
        ->and($sqlite['services']['app']['environment'])
        ->toMatchArray([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/var/www/html/database/database.sqlite',
            'QUEUE_CONNECTION' => 'sync',
        ])
        ->and($production['services'])
        ->toHaveKeys(['app', 'web', 'worker', 'scheduler', 'db'])
        ->and($production['services']['db']['image'])->toBe('mysql:8.4')
        ->and($production['services']['worker']['command'])->toContain('queue:work')
        ->and($production['services']['scheduler']['command'])->toContain('schedule:work');
});

it('describes --user as existing-author selection rather than login credentials', function (): void {
    $firstSession = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/getting-started/first-session.md');

    expect($firstSession)
        ->toContain('`--user=` selects an existing account as the default content author')
        ->toContain('does not set or change that account\'s login credentials')
        ->not->toContain('credentials for the user you passed to `--user=`');
});
