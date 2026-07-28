<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/release/ReleaseEngine.php';
require_once dirname(__DIR__, 2) . '/scripts/release/ReleaseEligibility.php';

use Capell\Release\CommandRunner;
use Capell\Release\ReleaseEligibilityChecker;
use Capell\Release\ReleaseException;

it('requires successful exact-SHA Core, App, and Packages workflow evidence', function (): void {
    $coreSha = str_repeat('a', 40);
    $appSha = str_repeat('b', 40);
    $packagesSha = str_repeat('c', 40);
    $runner = releaseEligibilityRunner($appSha, $packagesSha);

    $evidence = new ReleaseEligibilityChecker($runner)->check($coreSha);

    expect($evidence['core_test_all']['sha'])->toBe($coreSha)
        ->and($evidence['app_preflight']['sha'])->toBe($appSha)
        ->and($evidence['app_preflight']['runs'])->toHaveCount(6)
        ->and($evidence['packages_preflight']['sha'])->toBe($packagesSha)
        ->and($evidence['packages_preflight']['runs'])->toHaveCount(6);
});

it('fails closed when a workflow result is not bound to the requested SHA', function (): void {
    $runner = releaseEligibilityRunner(
        appSha: str_repeat('b', 40),
        packagesSha: str_repeat('c', 40),
        mismatchedWorkflow: 'security-audit.yml',
    );

    expect(fn (): array => new ReleaseEligibilityChecker($runner)->check(str_repeat('a', 40)))
        ->toThrow(ReleaseException::class, 'no successful security-audit.yml run');
});

it('does not accept a targeted workflow dispatch as hosted Core Test All evidence', function (): void {
    $runner = releaseEligibilityRunner(
        appSha: str_repeat('b', 40),
        packagesSha: str_repeat('c', 40),
        coreWorkflowEvent: 'workflow_dispatch',
    );

    expect(fn (): array => new ReleaseEligibilityChecker($runner)->check(str_repeat('a', 40)))
        ->toThrow(ReleaseException::class, 'no successful test-full.yml run');
});

it('accepts digest-bound repository-owned local preflight evidence', function (): void {
    $coreSha = str_repeat('a', 40);
    $appSha = str_repeat('b', 40);
    $packagesSha = str_repeat('c', 40);
    $directory = sys_get_temp_dir() . '/capell-release-evidence-' . bin2hex(random_bytes(8));
    mkdir($directory);

    $gates = [
        'core_test_all' => ['repository' => 'capell-app/capell', 'sha' => $coreSha, 'command' => 'composer test:all:matrix:local'],
        'app_preflight' => ['repository' => 'capell-app/capell-app', 'sha' => $appSha, 'command' => './capell composer preflight:all'],
        'packages_preflight' => ['repository' => 'capell-app/capell-packages', 'sha' => $packagesSha, 'command' => 'composer preflight:all'],
    ];

    foreach ($gates as $gate => &$record) {
        $logPath = $directory . '/' . $gate . '.log';
        file_put_contents($logPath, $gate . ' passed');
        $record += [
            'exit_code' => 0,
            'started_at' => '2026-07-28T11:00:00Z',
            'completed_at' => '2026-07-28T12:00:00Z',
            'log_path' => $logPath,
            'log_sha256' => hash_file('sha256', $logPath),
            'source_tree' => str_repeat('d', 40),
            'composer_lock_sha256' => str_repeat('e', 64),
            'dependency_shas' => $gate === 'app_preflight'
                ? ['capell-app/capell' => $coreSha, 'capell-app/capell-packages' => $packagesSha]
                : [],
        ];
    }

    unset($record);

    $manifest = json_encode([
        'schema_version' => 2,
        'producer' => 'capell-app/scripts/release-local-gates.php',
        'generated_at' => '2026-07-28T12:00:00Z',
        'gates' => $gates,
    ], JSON_THROW_ON_ERROR);

    $evidence = new ReleaseEligibilityChecker(
        releaseEligibilityRunner($appSha, $packagesSha),
        static fn (array $expectedShas): string => $manifest,
    )->check($coreSha);

    expect($evidence['core_test_all']['runs'][0]['source'])->toBe('local')
        ->and($evidence['app_preflight']['sha'])->toBe($appSha)
        ->and($evidence['packages_preflight']['sha'])->toBe($packagesSha);
});

it('fails closed when a local preflight log digest does not match', function (): void {
    $coreSha = str_repeat('a', 40);
    $appSha = str_repeat('b', 40);
    $packagesSha = str_repeat('c', 40);
    $directory = sys_get_temp_dir() . '/capell-release-evidence-' . bin2hex(random_bytes(8));
    mkdir($directory);
    $logPath = $directory . '/preflight.log';
    file_put_contents($logPath, 'passed');

    $gates = [];
    foreach ([
        'core_test_all' => ['capell-app/capell', $coreSha, 'composer test:all:matrix:local'],
        'app_preflight' => ['capell-app/capell-app', $appSha, './capell composer preflight:all'],
        'packages_preflight' => ['capell-app/capell-packages', $packagesSha, 'composer preflight:all'],
    ] as $gate => [$repository, $sha, $command]) {
        $gates[$gate] = [
            'repository' => $repository,
            'sha' => $sha,
            'command' => $command,
            'exit_code' => 0,
            'started_at' => '2026-07-28T11:00:00Z',
            'completed_at' => '2026-07-28T12:00:00Z',
            'log_path' => $logPath,
            'log_sha256' => str_repeat('0', 64),
            'source_tree' => str_repeat('d', 40),
            'composer_lock_sha256' => str_repeat('e', 64),
            'dependency_shas' => $gate === 'app_preflight'
                ? ['capell-app/capell' => $coreSha, 'capell-app/capell-packages' => $packagesSha]
                : [],
        ];
    }

    $manifest = json_encode([
        'schema_version' => 2,
        'producer' => 'capell-app/scripts/release-local-gates.php',
        'generated_at' => '2026-07-28T12:00:00Z',
        'gates' => $gates,
    ], JSON_THROW_ON_ERROR);

    expect(fn (): array => new ReleaseEligibilityChecker(
        releaseEligibilityRunner($appSha, $packagesSha),
        static fn (array $expectedShas): string => $manifest,
    )->check($coreSha))->toThrow(ReleaseException::class, 'log digest does not match');
});

function releaseEligibilityRunner(
    string $appSha,
    string $packagesSha,
    ?string $mismatchedWorkflow = null,
    string $coreWorkflowEvent = 'push',
): CommandRunner {
    return new readonly class($appSha, $packagesSha, $mismatchedWorkflow, $coreWorkflowEvent) implements CommandRunner
    {
        public function __construct(
            private string $appSha,
            private string $packagesSha,
            private ?string $mismatchedWorkflow,
            private string $coreWorkflowEvent,
        ) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $text = implode(' ', $command);

            if (str_contains($text, 'git/ref/heads/main')) {
                return [
                    'output' => str_contains($text, 'capell-app/capell-app')
                        ? $this->appSha
                        : $this->packagesSha,
                    'exitCode' => 0,
                ];
            }

            $workflowIndex = array_search('--workflow', $command, true);
            $shaIndex = array_search('--commit', $command, true);
            $workflow = is_int($workflowIndex) ? ($command[$workflowIndex + 1] ?? '') : '';
            $sha = is_int($shaIndex) ? ($command[$shaIndex + 1] ?? '') : '';
            $reportedSha = $workflow === $this->mismatchedWorkflow ? str_repeat('d', 40) : $sha;

            return [
                'output' => json_encode([[
                    'databaseId' => 123,
                    'headSha' => $reportedSha,
                    'conclusion' => 'success',
                    'event' => $workflow === 'test-full.yml' ? $this->coreWorkflowEvent : 'push',
                    'url' => 'https://github.test/actions/runs/123',
                ]], JSON_THROW_ON_ERROR),
                'exitCode' => 0,
            ];
        }
    };
}
