<?php

declare(strict_types=1);

namespace Capell\Tests\Unit;

use Capell\Tests\Support\CommandFixture;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/../Support/ScriptFixture.php';
require_once __DIR__ . '/../Support/CommandFixture.php';

// These workflow contracts need no Laravel application or shared Testbench tree.
final class CiWorkflowCredentialHygieneTest extends TestCase
{
    /** @return Iterator<string, array{string, int, bool}> */
    public static function releaseSources(): Iterator
    {
        yield 'ancestor of workflow main' => [str_repeat('a', 40), 0, true];
        yield 'unmerged source' => [str_repeat('a', 40), 1, false];
        yield 'unavailable source' => [str_repeat('a', 40), 128, false];
        yield 'option instead of SHA' => ['--all', 0, false];
    }

    /** @return Iterator<string, array{string}> */
    public static function credentialWorkflows(): Iterator
    {
        yield 'screenshots' => ['.github/workflows/screenshots.yml'];
        yield 'release smoke' => ['.github/workflows/public-release-smoke.yml'];
        yield 'full tests' => ['.github/workflows/test-full.yml'];
        yield 'reusable fast gate' => ['.github/workflows/test-fast-pr.yml'];
        yield 'PHP quality' => ['.github/workflows/code-quality-and-styling.yml'];
        yield 'Rector' => ['.github/workflows/rector-full-dry-run.yml'];
    }

    /** @return Iterator<string, array{string}> */
    public static function allWorkflows(): Iterator
    {
        $root = dirname(__DIR__, 2);
        foreach (glob($root . '/{.github/workflows,.github/split-repository,packages/*/.github/workflows}/*.{yml,yaml}', GLOB_BRACE) ?: [] as $path) {
            yield substr($path, strlen($root) + 1) => [$path];
        }
    }

    /** @return Iterator<string, array{string, string, bool, bool}> */
    public static function dependencyJobs(): Iterator
    {
        yield 'screenshots' => ['screenshots', 'capture-core-screenshots', true, false];
        yield 'release smoke' => ['public-release-smoke', 'product-contracts', true, false];
        yield 'PHP quality cache miss' => ['code-quality-and-styling', 'quality', true, false];
        yield 'PHP quality cache hit' => ['code-quality-and-styling', 'quality', true, true];
        yield 'Rector' => ['rector-full-dry-run', 'rector', false, false];
    }

    /** @return Iterator<string, array{string, bool}> */
    public static function screenshotSelections(): Iterator
    {
        yield 'all manifests by default' => ['', true];
        yield 'core' => ['core', true];
        yield 'documentation' => ['documentation', true];
        yield 'shell substitution' => ['$(touch @ROOT@/injected)', false];
        yield 'quote escape' => ['x"; touch @ROOT@/injected; echo "', false];
        yield 'multiple lines' => ["core\nfrontend", false];
        yield 'path traversal' => ['../core', false];
        yield 'unknown package' => ['missing', false];
    }

    public function test_forwarding_copies_the_approved_source_without_running_it(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = ['.github/split-repository/forward-pr-to-monorepo.yml', ...array_map(
            static fn (string $package): string => 'packages/' . $package . '/.github/workflows/forward-pr-to-monorepo.yml',
            ['core', 'admin', 'frontend', 'installer', 'marketplace'],
        )];
        foreach ($paths as $path) {
            $workflow = Yaml::parseFile($root . '/' . $path);
            $job = $workflow['jobs']['forward'];
            $this->assertSame('split-pr-forwarding', $job['environment'] ?? null);
            $this->assertTrue($workflow['concurrency']['cancel-in-progress']);
            $this->assertArrayNotHasKey('GH_TOKEN', $job['env']);
            $this->assertSame(['contents' => 'read'], $workflow['permissions']);
            $steps = array_column($job['steps'], null, 'name');
            $this->assertFalse($steps['Checkout split PR contents']['with']['persist-credentials']);
            $this->assertFalse($steps['Checkout Capell monorepo']['with']['persist-credentials']);
            $this->assertSame('${{ github.event.pull_request.head.sha }}', $steps['Checkout split PR contents']['with']['ref']);
            $fixture = new CommandFixture;
            $fixture->files->write('split/content.txt', 'Approved source');
            $fixture->files->write('split/.git/config', 'private credentials');
            $fixture->files->write('split/package.json', '{"scripts":{"test":"touch executed-fork"}}');
            $fixture->files->write('monorepo/packages/core/stale.txt', 'old');
            $fixture->fake('git', 'if (in_array("status", $argv, true)) { echo "M package"; }');
            $fixture->fake('gh', 'if (in_array("create", $argv, true)) { echo "https://example.test/pull/1"; }');
            try {
                $process = $fixture->run(['bash', '-e', '-c', $steps['Create or update monorepo PR']['run']], [
                    'GH_TOKEN' => 'fixture-token',
                    'PACKAGE_NAME' => 'core', 'SOURCE_PR_NUMBER' => '42',
                    'SOURCE_PR_TITLE' => '$(touch executed-title)', 'SOURCE_PR_AUTHOR' => 'author',
                    'SOURCE_PR_URL' => 'https://example.test/pull/42',
                    'SOURCE_PR_BASE' => 'main', 'SOURCE_PR_HEAD' => 'feature',
                    'SOURCE_REPOSITORY' => 'example/core',
                    'MONOREPO_REPOSITORY' => $job['env']['MONOREPO_REPOSITORY'],
                    'MONOREPO_BASE' => $job['env']['MONOREPO_BASE'],
                ]);
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame('Approved source', file_get_contents($fixture->files->root . '/monorepo/packages/core/content.txt'));
                $this->assertFileDoesNotExist($fixture->files->root . '/monorepo/packages/core/stale.txt');
                $this->assertDirectoryDoesNotExist($fixture->files->root . '/monorepo/packages/core/.git');
                $this->assertFileDoesNotExist($fixture->files->root . '/split/executed-fork');
                $this->assertFileDoesNotExist($fixture->files->root . '/monorepo/executed-title');
                $this->assertSame([], $fixture->calls('npm'));
                $this->assertSame([], $fixture->calls('composer'));
                $this->assertContains([
                    'pr', 'comment', '42', '--repo', 'example/core', '--body', 'Forwarded to https://example.test/pull/1.',
                ], array_column($fixture->calls('gh'), 'arguments'));
            } finally {
                $fixture->close();
            }
        }
    }

    public function test_release_requires_main_and_approval_before_publication(): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/split-monorepo.yml');
        $this->assertSame(['contents' => 'read'], $workflow['permissions']);
        foreach (['prepare-plan', 'publish'] as $name) {
            $this->assertSame("github.ref == 'refs/heads/main'", $workflow['jobs'][$name]['if'] ?? null);
        }

        $this->assertSame('release', $workflow['jobs']['publish']['environment'] ?? null);
        $this->assertSame('prepare-plan', $workflow['jobs']['publish']['needs']);
        $this->assertSame(['actions' => 'read', 'contents' => 'write'], $workflow['jobs']['publish']['permissions']);
    }

    #[DataProvider('releaseSources')]
    public function test_release_rejects_sources_outside_the_workflow_main_history(string $source, int $ancestryExit, bool $valid): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/split-monorepo.yml');
        $steps = array_column($workflow['jobs']['prepare-plan']['steps'], null, 'name');
        $fixture = new CommandFixture;
        $fixture->files->write('plan.json', json_encode(['source' => ['commit' => $source]], JSON_THROW_ON_ERROR));
        $fixture->files->write('artifacts/.keep', '');
        $fixture->fake('php');
        $fixture->files->write('bin/git', <<<'SH'
            #!/bin/sh
            [ "$*" = "merge-base --is-ancestor aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" ] || exit 99
            exit "$ANCESTRY_EXIT"
            SH);
        chmod($fixture->files->root . '/bin/git', 0755);
        try {
            $process = $fixture->run(['bash', '-e', '-o', 'pipefail', '-c', $steps['Validate committed release plan']['run']], [
                'GITHUB_WORKSPACE' => $fixture->files->root,
                'RUNNER_TEMP' => $fixture->files->root . '/artifacts',
                'PLAN_PATH' => 'plan.json', 'RELEASE_WORKFLOW_SHA' => str_repeat('b', 40),
                'ANCESTRY_EXIT' => (string) $ancestryExit,
            ]);
            $artifact = $fixture->files->root . '/artifacts/release-plan.json';
            if ($valid) {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertFileExists($artifact);
            } else {
                $this->assertNotSame(0, $process->getExitCode());
                $this->assertFileDoesNotExist($artifact);
            }
        } finally {
            $fixture->close();
        }
    }

    #[DataProvider('dependencyJobs')]
    public function test_dependency_installation_defers_hooks_until_credentials_are_absent(string $file, string $jobName, bool $hasNpm, bool $cacheHit): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/' . $file . '.yml');
        $job = $workflow['jobs'][$jobName];
        $fixture = new CommandFixture;
        try {
            $installCondition = null;
            foreach ($job['steps'] as $step) {
                $command = $step['run'] ?? '';
                if (str_starts_with((string) $command, 'npm ci') || str_starts_with((string) $command, 'composer install') || str_starts_with((string) $command, 'composer dump-autoload')) {
                    if (str_starts_with((string) $command, 'composer install')) {
                        $installCondition = $step['if'] ?? null;
                    }

                    if ($cacheHit && str_starts_with((string) $command, 'composer install')) {
                        $this->assertSame("steps.composer-cache.outputs.cache-hit != 'true'", $step['if'] ?? null);

                        continue;
                    }

                    if (str_starts_with((string) $command, 'composer dump-autoload')) {
                        $this->assertSame($jobName === 'quality' ? null : $installCondition, $step['if'] ?? null);
                    }

                    $process = $fixture->run(['bash', '-e', '-c', $command]);
                    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                }
            }

            $composerCalls = array_column($fixture->calls('composer'), 'arguments');
            $this->assertCount($cacheHit ? 1 : 2, $composerCalls);
            if (! $cacheHit) {
                $this->assertSame('install', $composerCalls[0][0]);
                $this->assertContains('--no-scripts', $composerCalls[0]);
                $this->assertContains('--no-plugins', $composerCalls[0]);
            }

            $this->assertSame(['dump-autoload', '--no-interaction'], end($composerCalls));
            $this->assertSame($hasNpm, $fixture->calls('npm') !== []);
            foreach ($fixture->calls('npm') as $call) {
                $this->assertContains('--ignore-scripts', $call['arguments']);
            }
        } finally {
            $fixture->close();
        }
    }

    #[DataProvider('credentialWorkflows')]
    public function test_repository_code_runs_without_checkout_credentials(string $file): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/' . $file);
        $this->assertSame(['contents' => 'read'], $workflow['permissions']);
        foreach ($workflow['jobs'] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (str_starts_with($step['uses'] ?? '', 'actions/checkout@')) {
                    $this->assertFalse($step['with']['persist-credentials'] ?? true);
                }

                if (isset($step['run'])) {
                    $environment = array_replace($workflow['env'] ?? [], $job['env'] ?? [], $step['env'] ?? []);
                    foreach ($environment as $value) {
                        $this->assertDoesNotMatchRegularExpression('/\bsecrets\./', (string) $value);
                    }
                }
            }
        }
    }

    #[DataProvider('allWorkflows')]
    public function test_every_checkout_has_an_explicit_credential_disposition(string $file): void
    {
        $workflow = Yaml::parseFile($file);
        $this->assertNotEmpty($workflow['jobs']);
        foreach ($workflow['jobs'] as $jobName => $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (! str_starts_with($step['uses'] ?? '', 'actions/checkout@')) {
                    continue;
                }

                $context = $file . ':' . $jobName;
                // Changelog's dedicated write-back action needs checkout authentication.
                // It must never share that credential with repository run steps.
                if ($file === dirname(__DIR__, 2) . '/.github/workflows/update-changelog.yml' && $jobName === 'update') {
                    $this->assertTrue($step['with']['persist-credentials'] ?? null, $context);
                    $this->assertSame(['contents' => 'write'], $workflow['permissions']);
                    $this->assertSame('main', $step['with']['ref']);
                    $this->assertSame([], array_filter($job['steps'], static fn (array $candidate): bool => isset($candidate['run'])));
                    $writers = array_values(array_filter($job['steps'], static fn (array $candidate): bool => str_starts_with($candidate['uses'] ?? '', 'stefanzweifel/git-auto-commit-action@')));
                    $this->assertCount(1, $writers);
                    $this->assertSame('main', $writers[0]['with']['branch']);
                    $this->assertSame('CHANGELOG.md', $writers[0]['with']['file_pattern']);

                    continue;
                }

                $this->assertFalse($step['with']['persist-credentials'] ?? null, $context);
            }
        }
    }

    #[DataProvider('screenshotSelections')]
    public function test_screenshot_selection_treats_dispatch_input_as_data(string $selection, bool $valid): void
    {
        $root = sys_get_temp_dir() . '/capell-workflow-' . bin2hex(random_bytes(8));
        mkdir($root . '/docs', 0700, true);
        mkdir($root . '/packages/core/docs', 0700, true);
        mkdir($root . '/scripts', 0700, true);
        copy(dirname(__DIR__, 2) . '/scripts/changed-screenshot-packages.js', $root . '/scripts/changed-screenshot-packages.js');
        file_put_contents($root . '/docs/screenshots.json', '{}');
        file_put_contents($root . '/packages/core/docs/screenshots.json', '{}');
        file_put_contents($root . '/output', '');
        $input = str_replace('@ROOT@', $root, $selection);
        $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/screenshots.yml');
        $steps = array_column($workflow['jobs']['capture-core-screenshots']['steps'], null, 'name');
        // Model Actions' expression substitution before bash parses the run block.
        $script = strtr($steps['Determine manifests to capture']['run'], [
            '${{ inputs.package }}' => $input,
            '${{ github.event_name }}' => 'workflow_dispatch',
            '/tmp/capell-core-screenshot-packages.txt' => $root . '/selection',
        ]);
        try {
            $process = new Process(['bash', '-e', '-c', $script], $root, [
                'SCREENSHOT_PACKAGE' => $input,
                'EVENT_NAME' => 'workflow_dispatch',
                'GITHUB_OUTPUT' => $root . '/output',
            ]);
            $process->setTimeout(10);
            $process->run();
            $this->assertFileDoesNotExist($root . '/injected');
            if ($valid) {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame($input === '' ? "core\ndocumentation\n" : $input . "\n", file_get_contents($root . '/selection'));
                $this->assertSame("has_packages=true\n", file_get_contents($root . '/output'));
            } else {
                $this->assertNotSame(0, $process->getExitCode());
                $this->assertFileDoesNotExist($root . '/selection');
            }
        } finally {
            foreach (['injected', 'selection', 'output', 'docs/screenshots.json', 'packages/core/docs/screenshots.json', 'scripts/changed-screenshot-packages.js'] as $file) {
                if (is_file($root . '/' . $file)) {
                    unlink($root . '/' . $file);
                }
            }

            foreach (['packages/core/docs', 'packages/core', 'packages', 'docs', 'scripts', ''] as $directory) {
                rmdir($root . '/' . $directory);
            }
        }
    }
}
