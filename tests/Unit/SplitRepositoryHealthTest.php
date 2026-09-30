<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('keeps generated split repository policies aligned with their shared sources', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/sync-split-repository-health.php', '--check'], $root);

    $process->mustRun();

    expect($process->getOutput())->toContain('Split repository health files are aligned.');
});

it('covers every repository ecosystem with a cooldown and pins every external workflow action', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var list<array{path: string}> $packages */
    $packages = json_decode((string) file_get_contents($root . '/config/release-packages.json'), true, 512, JSON_THROW_ON_ERROR);

    foreach (['', ...array_column($packages, 'path')] as $path) {
        $directory = $root . ($path === '' ? '' : '/' . $path);
        /** @var array{version: int, updates: list<array{package-ecosystem: string, cooldown: array{default-days: int}}>} $dependabot */
        $dependabot = Yaml::parseFile($directory . '/.github/dependabot.yml');
        $ecosystems = array_column($dependabot['updates'], 'package-ecosystem');

        expect($dependabot['version'])->toBe(2)
            ->and($ecosystems)->toContain('composer', 'github-actions');

        if (is_file($directory . '/package.json')) {
            expect($ecosystems)->toContain('npm');
        }

        foreach ($dependabot['updates'] as $update) {
            expect($update['cooldown']['default-days'])->toBeGreaterThanOrEqual(3);
        }

        foreach (glob($directory . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [] as $workflowPath) {
            $workflow = (string) file_get_contents($workflowPath);
            preg_match_all('/^\s*(?:-\s*)?uses:\s*([^\s#]+)([^\r\n]*)/m', $workflow, $references, PREG_SET_ORDER);

            foreach ($references as $reference) {
                if (str_starts_with($reference[1], './')) {
                    continue;
                }

                expect($reference[1])->toMatch('/@[a-fA-F0-9]{40}$/')
                    ->and($reference[2])->toMatch('/#\s*v\S+/');
            }
        }
    }
});

it('excludes repository tooling from dist while retaining package runtime files and security policies', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var list<array{path: string}> $packages */
    $packages = json_decode((string) file_get_contents($root . '/config/release-packages.json'), true, 512, JSON_THROW_ON_ERROR);
    $excluded = ['composer.lock', 'phpstan.neon', 'phpstan', 'tests', '.github', 'scripts', 'AGENTS.md', 'workbench'];
    $retained = ['composer.json', 'SECURITY.md', 'LICENSE.md'];

    foreach ($packages as $package) {
        foreach (['.github', 'tests', 'docs', 'AGENTS.md', 'rector.php', 'package-lock.json'] as $file) {
            $excluded[] = $package['path'] . '/' . $file;
        }

        foreach (['composer.json', 'capell.json', 'SECURITY.md', 'src', 'resources', 'publishes', 'database', 'config', 'routes', 'stubs', 'stubs/extension/full/tests', 'stubs/extension/full/phpunit.xml.dist.stub'] as $file) {
            $retained[] = $package['path'] . '/' . $file;
        }
    }

    $process = new Process(['git', 'check-attr', 'export-ignore', '--stdin'], $root);
    $process->setInput(implode("\n", [...$excluded, ...$retained]) . "\n");
    $process->mustRun();

    $attributes = explode("\n", trim($process->getOutput()));

    foreach ($excluded as $file) {
        expect($attributes)->toContain($file . ': export-ignore: set');
    }

    foreach ($retained as $file) {
        expect($attributes)->toContain($file . ': export-ignore: unspecified');
    }
});
