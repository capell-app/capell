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

    $evidence = (new ReleaseEligibilityChecker($runner))->check($coreSha);

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

    expect(fn () => (new ReleaseEligibilityChecker($runner))->check(str_repeat('a', 40)))
        ->toThrow(ReleaseException::class, 'no successful security-audit.yml run');
});

function releaseEligibilityRunner(
    string $appSha,
    string $packagesSha,
    ?string $mismatchedWorkflow = null,
): CommandRunner {
    return new readonly class($appSha, $packagesSha, $mismatchedWorkflow) implements CommandRunner
    {
        public function __construct(
            private string $appSha,
            private string $packagesSha,
            private ?string $mismatchedWorkflow,
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
                    'url' => 'https://github.test/actions/runs/123',
                ]], JSON_THROW_ON_ERROR),
                'exitCode' => 0,
            ];
        }
    };
}
