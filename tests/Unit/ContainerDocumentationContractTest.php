<?php

declare(strict_types=1);

use Capell\Tests\Support\CommandFixture;
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

    // Exclusion patterns keep credentials and host dependencies out of image layers.
    expect(preg_split('/\s+/', trim($dockerignore)))->toContain('.env', '.env.*', 'vendor', 'node_modules');
    // The final image must run as an unprivileged user.
    expect($dockerfile)->toMatch('/^USER\s+www-data\s*$/m');
});

it('smokes both consumer shapes with bounded requests and locked builds', function (): void {
    $fixture = new CommandFixture;
    $fixture->files->copy('scripts/verify-container-quickstart.sh');
    foreach (['.dockerignore', 'Dockerfile', 'nginx.conf', 'compose.sqlite.yaml', 'compose.production.yaml', '.env.production.example'] as $file) {
        $fixture->files->copy('tests/fixtures/container-quickstart/' . $file);
    }

    foreach (['admin', 'core', 'frontend', 'installer', 'marketplace'] as $package) {
        $fixture->files->write('packages/' . $package . '/composer.json', '{}');
    }

    $fixture->fake('composer', <<<'PHP'
        if ($argv[1] === 'create-project') {
            $root = $argv[count($argv) - 1];
            mkdir($root . '/database', 0755, true);
        }
        PHP);
    $fixture->fake('php', 'echo "12345";');
    $fixture->fake('docker', <<<'PHP'
        if (in_array('inspect', $argv, true)) { echo 'healthy'; }
        if (in_array('ps', $argv, true)) { echo 'fixture-database'; }
        PHP);
    $fixture->fake('curl', <<<'PHP'
        $index = array_search('--output', $argv, true);
        if ($index !== false) { file_put_contents($argv[$index + 1], '<html>Consumer response</html>'); }
        PHP);
    try {
        $process = $fixture->run(['bash', 'scripts/verify-container-quickstart.sh'], [
            'CAPELL_CHECKOUT' => $fixture->files->root,
            'CAPELL_CONTAINER_SMOKE_ROOT' => $fixture->files->root . '/smoke',
            'CAPELL_CONTAINER_SMOKE_HTTP_TIMEOUT_SECONDS' => '47',
            'CAPELL_CONTAINER_SMOKE_KEEP' => 'true',
        ]);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toContain('Container quickstart smoke passed');
        $consumer = $fixture->files->root . '/smoke/consumer';
        foreach (['production', 'root', 'admin-login'] as $response) {
            expect(file_get_contents($fixture->files->root . '/smoke/response-' . $response . '.html'))->toBe('<html>Consumer response</html>');
        }

        $requests = array_column($fixture->calls('curl'), 'arguments');
        expect($requests)->toHaveCount(3);
        foreach (array_slice($requests, 1) as $arguments) {
            expect($arguments)->toContain('--max-time', '47', '--fail', '--location');
        }

        $commands = array_column($fixture->calls('docker'), 'arguments');
        $installs = array_values(array_filter($commands, static fn (array $arguments): bool => in_array('capell:install', $arguments, true)));
        expect($installs)->toHaveCount(2);
        expect($installs[1])->toContain('--production', '--user', 'root');
        $lockBuilds = array_values(array_filter($commands, static fn (array $arguments): bool => in_array('--package-lock-only', $arguments, true)));
        expect($lockBuilds)->toHaveCount(1);
        $manifestChecks = array_values(array_filter($commands, static fn (array $arguments): bool => in_array('bootstrap/cache/capell-package-manifests.php', $arguments, true)));
        expect($manifestChecks)->toHaveCount(1);
        expect(Yaml::parseFile($consumer . '/compose.production.yaml'))->toEqual(Yaml::parseFile($fixture->files->root . '/tests/fixtures/container-quickstart/compose.production.yaml'));
    } finally {
        $fixture->close();
    }
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
        ->toContain("does not set or change that account's login credentials")
        ->not->toContain('credentials for the user you passed to `--user=`');
});
