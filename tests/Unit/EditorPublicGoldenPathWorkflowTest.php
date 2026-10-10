<?php

declare(strict_types=1);

use Capell\Tests\Support\CommandFixture;
use Dotenv\Dotenv;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('schedules the editor journey only on tags or explicit requests and retains bounded failure evidence', function (): void {
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/editor-public-golden-path.yml');
    expect(array_keys($workflow['on']))->toEqualCanonicalizing(['push', 'workflow_dispatch'])
        ->and($workflow['on']['push'])->toBe(['tags' => ['*']]);
    $job = $workflow['jobs']['editor-public-golden-path'];
    expect($job['defaults']['run']['working-directory'])->toBe('repositories/capell');
    $checkouts = array_values(array_filter($job['steps'], static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/checkout@')));
    expect($checkouts)->toHaveCount(3);
    foreach ($checkouts as $checkout) {
        expect($checkout['with']['persist-credentials'])->toBeFalse();
    }

    expect(array_column(array_column($checkouts, 'with'), 'repository'))->toContain('capell-app/capell-packages', 'capell-app/capell-screenshot-tools');
    $uploads = array_values(array_filter($job['steps'], static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/upload-artifact@')));
    expect($uploads)->toHaveCount(1)
        ->and($uploads[0]['if'])->toBe('failure()')
        ->and($uploads[0]['with']['retention-days'])->toBe(7)
        ->and($uploads[0]['with']['path'])->toBe('${{ runner.temp }}/editor-public-golden-path');
});

it('prepares an isolated cache-enabled consumer and dispatches the public lifecycle journey', function (): void {
    $fixture = goldenPathCommandFixture();
    $fixture->fake('composer', <<<'PHP'
        if ($argv[1] === 'create-project') {
            $root = $argv[count($argv) - 2];
            foreach (['database', 'public', 'storage/logs'] as $path) { mkdir($root . '/' . $path, 0755, true); }
            file_put_contents($root . '/.env.example', 'APP_NAME=Consumer');
            file_put_contents($root . '/vite.config.js', 'export default {}');
        }
        PHP);
    $fixture->fake('php', 'if (in_array("--version", $argv, true)) { echo "Laravel Framework 13.0.0"; }');
    $fixture->files->write('node_modules/.bin/playwright', '#!' . PHP_BINARY . <<<'PHP'

        <?php
        file_put_contents(getenv('CAPELL_GOLDEN_PATH_ARTIFACT_DIR') . '/journey.json', json_encode([
            'arguments' => array_slice($argv, 1), 'url' => getenv('CAPELL_GOLDEN_PATH_URL'),
        ], JSON_THROW_ON_ERROR));
        PHP);
    chmod($fixture->files->root . '/node_modules/.bin/playwright', 0755);
    try {
        $process = runGoldenPathFixture($fixture);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        $consumer = $fixture->files->root . '/consumer';
        $environment = Dotenv::parse((string) file_get_contents($consumer . '/.env'));
        expect($environment)->toMatchArray([
            'APP_DEBUG' => 'false', 'CAPELL_HTML_CACHE' => 'true',
            'CAPELL_HTML_CACHE_ORIGIN_SWR' => 'false', 'CAPELL_HTML_CACHE_INVALIDATION_MODE' => 'instant',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $consumer . '/database/database.sqlite',
        ]);
        $journey = json_decode((string) file_get_contents($fixture->files->root . '/evidence/journey.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($journey['url'])->toBe('http://127.0.0.1:8765')
            ->and($journey['arguments'])->toContain($fixture->files->root . '/tests/Browser/editor-public-golden-path.spec.js', '--project=chromium', '--output=' . $consumer . '/playwright-output');
        $repositories = array_values(array_filter($fixture->calls('composer'), static fn (array $call): bool => ($call['arguments'][0] ?? '') === 'config' && ($call['arguments'][2] ?? '') === '--json'));
        expect($repositories)->toHaveCount(11);
        foreach ($repositories as $repository) {
            $definition = json_decode($repository['arguments'][3], true, flags: JSON_THROW_ON_ERROR);
            expect($definition['type'])->toBe('path')->and($definition['options']['symlink'])->toBeTrue()
                ->and(is_dir($definition['url']))->toBeTrue();
        }

        $calls = array_column($fixture->calls('php'), 'arguments');
        expect($calls)->toContain(['artisan', 'migrate', '--force', '--ansi'], ['artisan', 'filament:assets', '--ansi']);
    } finally {
        $fixture->close();
    }
});

it('rejects a dirty source or mismatching companion revision before creating a consumer', function (string $condition, string $message): void {
    $fixture = goldenPathCommandFixture();
    if ($condition === 'dirty') {
        $fixture->fake('git', 'echo in_array("status", $argv, true) ? "M tracked.php" : str_repeat("a", 40);');
    }

    try {
        $process = runGoldenPathFixture($fixture, $condition === 'mismatch' ? ['CAPELL_PACKAGES_HEAD' => 'different-source'] : []);
        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain($message)
            ->and($fixture->calls('composer'))->toBe([]);
    } finally {
        $fixture->close();
    }
})->with([
    'dirty' => ['dirty', 'requires a clean tracked Capell checkout'],
    'mismatch' => ['mismatch', 'must match the checked-out companion source'],
]);

it('executes the redaction and anonymous-output behavioural contracts', function (): void {
    $process = new Process(['node', '--test', 'tests/Browser/support/public-output.test.js'], dirname(__DIR__, 2));
    $process->setTimeout(30);

    expect($process->run())->toBe(0, $process->getOutput() . $process->getErrorOutput());
});

it('redacts backend credentials before writing retained failure evidence', function (): void {
    $fixture = new CommandFixture;
    $fixture->files->write('backend.log', 'email=owner@example.test password=private-value Bearer secret-bearer');
    try {
        $process = $fixture->run(['node', dirname(__DIR__, 2) . '/tests/Browser/support/redact-log.js', $fixture->files->root . '/evidence/backend-redacted.log', $fixture->files->root . '/backend.log'], [
            'CAPELL_DIAGNOSTIC_SECRETS' => '["owner@example.test", "private-value", "secret-bearer"]',
        ]);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_get_contents($fixture->files->root . '/evidence/backend-redacted.log'))->toContain('[redacted]')
            ->not->toContain('owner@example.test', 'private-value', 'secret-bearer');
    } finally {
        $fixture->close();
    }
});

function goldenPathCommandFixture(): CommandFixture
{
    $fixture = new CommandFixture;
    $fixture->files->copy('scripts/run-editor-public-golden-path.sh');
    $fixture->files->copy('tests/fixtures/editor-public-golden-path.json');
    foreach (['redact-log.js', 'failure-evidence.js', 'public-output.js'] as $file) {
        $fixture->files->copy('tests/Browser/support/' . $file);
    }

    $fixture->files->copy('package.json');
    $fixture->fake('git', 'if (in_array("rev-parse", $argv, true)) { echo str_repeat("a", 40); }');
    foreach (['core', 'admin', 'frontend', 'installer', 'marketplace'] as $package) {
        $fixture->files->write('packages/' . $package . '/composer.json', '{}');
    }

    foreach (['discovery-foundation', 'html-cache', 'layout-builder', 'navigation', 'content-sections', 'block-library'] as $package) {
        $fixture->files->write('companion/packages/' . $package . '/composer.json', '{}');
    }

    $fixture->files->write('node_modules/.bin/playwright', '#!/usr/bin/env bash');
    chmod($fixture->files->root . '/node_modules/.bin/playwright', 0755);

    return $fixture;
}

/** @param array<string, string> $environment */
function runGoldenPathFixture(CommandFixture $fixture, array $environment = []): Process
{
    return $fixture->run(['bash', 'scripts/run-editor-public-golden-path.sh'], array_replace([
        'CAPELL_CHECKOUT' => $fixture->files->root,
        'CAPELL_PACKAGES_ROOT' => $fixture->files->root . '/companion',
        'CAPELL_GOLDEN_PATH_CONSUMER_ROOT' => $fixture->files->root . '/consumer',
        'CAPELL_GOLDEN_PATH_ARTIFACT_DIR' => $fixture->files->root . '/evidence',
        'CAPELL_GOLDEN_PATH_REQUIRE_CLEAN' => 'true',
        'CAPELL_PACKAGES_HEAD' => '',
    ], $environment));
}
