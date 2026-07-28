<?php

declare(strict_types=1);

namespace Capell\Release;

use Closure;

final class ReleaseEligibilityChecker
{
    /**
     * @var array<string, array{repository: string, command: string}>
     */
    private const array LOCAL_GATES = [
        'core_test_all' => [
            'repository' => 'capell-app/capell',
            'command' => 'composer test:all:matrix:local',
        ],
        'app_preflight' => [
            'repository' => 'capell-app/capell-app',
            'command' => './capell composer preflight:all',
        ],
        'packages_preflight' => [
            'repository' => 'capell-app/capell-packages',
            'command' => 'composer preflight:all',
        ],
    ];

    /**
     * @var array<string, list<array{repository: string, workflow: string}>>
     */
    private const array DOWNSTREAM_GATES = [
        'app_preflight' => [
            ['repository' => 'capell-app/capell-app', 'workflow' => 'tests.yml'],
            ['repository' => 'capell-app/capell-app', 'workflow' => 'php-quality.yml'],
            ['repository' => 'capell-app/capell-app', 'workflow' => 'frontend-assets.yml'],
            ['repository' => 'capell-app/capell-app', 'workflow' => 'security-audit.yml'],
            ['repository' => 'capell-app/capell-app', 'workflow' => 'rector-full-dry-run.yml'],
            ['repository' => 'capell-app/capell-app', 'workflow' => 'presentation-tests.yml'],
        ],
        'packages_preflight' => [
            ['repository' => 'capell-app/capell-packages', 'workflow' => 'test-full.yml'],
            ['repository' => 'capell-app/capell-packages', 'workflow' => 'code-quality-and-styling.yml'],
            ['repository' => 'capell-app/capell-packages', 'workflow' => 'security.yml'],
            ['repository' => 'capell-app/capell-packages', 'workflow' => 'rector-full-dry-run.yml'],
            ['repository' => 'capell-app/capell-packages', 'workflow' => 'asset-formatting.yml'],
            ['repository' => 'capell-app/capell-packages', 'workflow' => 'build-packages.yml'],
        ],
    ];

    public function __construct(
        private readonly CommandRunner $runner,
        /** @var (Closure(array<string, string>): string)|null */
        private readonly ?Closure $localGateRunner = null,
    ) {}

    /**
     * @return array<string, array{sha: string, runs: list<array<string, int|string>>}>
     */
    public function check(
        string $coreSha,
        ?string $appSha = null,
        ?string $packagesSha = null,
    ): array {
        $this->assertSha($coreSha, 'Core source');
        if ($appSha !== null) {
            $this->assertSha($appSha, 'App source');
        }

        if ($packagesSha !== null) {
            $this->assertSha($packagesSha, 'Packages source');
        }

        $expectedShas = [
            'core_test_all' => $coreSha,
            'app_preflight' => $appSha ?? $this->mainSha('capell-app/capell-app'),
            'packages_preflight' => $packagesSha ?? $this->mainSha('capell-app/capell-packages'),
        ];

        if ($this->localGateRunner instanceof Closure) {
            return $this->localEvidence($expectedShas);
        }

        $coreRun = $this->successfulRun(
            repository: 'capell-app/capell',
            workflow: 'test-full.yml',
            sha: $expectedShas['core_test_all'],
            allowedEvents: ['push'],
        );
        $evidence = [
            'core_test_all' => [
                'sha' => $expectedShas['core_test_all'],
                'runs' => [$coreRun],
            ],
        ];

        foreach (self::DOWNSTREAM_GATES as $gate => $workflows) {
            $sha = $expectedShas[$gate];
            $runs = [];

            foreach ($workflows as $workflow) {
                $runs[] = $this->successfulRun(
                    repository: $workflow['repository'],
                    workflow: $workflow['workflow'],
                    sha: $sha,
                );
            }

            $evidence[$gate] = [
                'sha' => $sha,
                'runs' => $runs,
            ];
        }

        return $evidence;
    }

    /**
     * @param  array<string, string>  $expectedShas
     * @return array<string, array{sha: string, runs: list<array{source: string, repository: string, workflow: string, sha: string, command: string, completed_at: string, log_path: string, log_sha256: string, source_tree: string, composer_lock_sha256: string}>}>
     */
    private function localEvidence(array $expectedShas): array
    {
        $localGateRunner = $this->localGateRunner;

        if (! $localGateRunner instanceof Closure) {
            throw new ReleaseException('Release paused: local release gate runner is unavailable.');
        }

        $decoded = json_decode($localGateRunner($expectedShas), true, 512, JSON_THROW_ON_ERROR);

        if (
            ! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 2
            || ($decoded['producer'] ?? null) !== 'capell-app/scripts/release-local-gates.php'
            || ! is_string($decoded['generated_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $decoded['generated_at']) !== 1
            || ! is_array($decoded['gates'] ?? null)
        ) {
            throw new ReleaseException('Release paused: local release eligibility evidence is invalid.');
        }

        $evidence = [];

        foreach (self::LOCAL_GATES as $gate => $expected) {
            $record = $decoded['gates'][$gate] ?? null;
            $sha = $expectedShas[$gate] ?? null;

            if (
                ! is_string($sha)
                ||
                ! is_array($record)
                || ($record['repository'] ?? null) !== $expected['repository']
                || ($record['sha'] ?? null) !== $sha
                || ($record['command'] ?? null) !== $expected['command']
                || ($record['exit_code'] ?? null) !== 0
                || ! is_string($record['started_at'] ?? null)
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $record['started_at']) !== 1
                || ! is_string($record['completed_at'] ?? null)
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $record['completed_at']) !== 1
                || ! is_string($record['log_path'] ?? null)
                || ! is_string($record['log_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $record['log_sha256']) !== 1
            ) {
                throw new ReleaseException(sprintf('Release paused: local %s evidence is invalid.', $gate));
            }

            $logPath = $record['log_path'];
            $expectedDependencies = $gate === 'app_preflight'
                ? [
                    'capell-app/capell' => $expectedShas['core_test_all'],
                    'capell-app/capell-packages' => $expectedShas['packages_preflight'],
                ]
                : [];

            if (
                ! str_starts_with($logPath, '/')
                || ! is_string($record['source_tree'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/', $record['source_tree']) !== 1
                || ! is_string($record['composer_lock_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $record['composer_lock_sha256']) !== 1
                || ($record['dependency_shas'] ?? null) !== $expectedDependencies
                || ! is_file($logPath)
            ) {
                throw new ReleaseException(sprintf('Release paused: local %s log digest does not match.', $gate));
            }

            $actualLogSha256 = hash_file('sha256', $logPath);

            if (! is_string($actualLogSha256) || ! hash_equals($record['log_sha256'], $actualLogSha256)) {
                throw new ReleaseException(sprintf('Release paused: local %s log digest does not match.', $gate));
            }

            $evidence[$gate] = [
                'sha' => $sha,
                'runs' => [[
                    'source' => 'local',
                    'repository' => $expected['repository'],
                    'workflow' => 'local-preflight',
                    'sha' => $sha,
                    'command' => $expected['command'],
                    'completed_at' => $record['completed_at'],
                    'log_path' => $logPath,
                    'log_sha256' => $record['log_sha256'],
                    'source_tree' => $record['source_tree'],
                    'composer_lock_sha256' => $record['composer_lock_sha256'],
                ]],
            ];
        }

        return $evidence;
    }

    private function mainSha(string $repository): string
    {
        $sha = $this->required([
            'gh',
            'api',
            sprintf('repos/%s/git/ref/heads/main', $repository),
            '--jq',
            '.object.sha',
        ]);

        $this->assertSha($sha, $repository . ' main');

        return $sha;
    }

    /**
     * @param  list<string>|null  $allowedEvents
     * @return array{repository: string, workflow: string, sha: string, run_id: int, run_url: string}
     */
    private function successfulRun(
        string $repository,
        string $workflow,
        string $sha,
        ?array $allowedEvents = null,
    ): array {
        $output = $this->required([
            'gh',
            'run',
            'list',
            '--repo',
            $repository,
            '--workflow',
            $workflow,
            '--commit',
            $sha,
            '--status',
            'success',
            '--limit',
            '20',
            '--json',
            'databaseId,headSha,conclusion,event,url',
        ]);
        $runs = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($runs)) {
            throw new ReleaseException(sprintf('Release paused: invalid workflow evidence for %s %s.', $repository, $workflow));
        }

        foreach ($runs as $run) {
            if (
                is_array($run)
                && ($run['headSha'] ?? null) === $sha
                && ($run['conclusion'] ?? null) === 'success'
                && ($allowedEvents === null || (
                    is_string($run['event'] ?? null)
                    && in_array($run['event'], $allowedEvents, true)
                ))
                && is_int($run['databaseId'] ?? null)
                && is_string($run['url'] ?? null)
            ) {
                return [
                    'repository' => $repository,
                    'workflow' => $workflow,
                    'sha' => $sha,
                    'run_id' => $run['databaseId'],
                    'run_url' => $run['url'],
                ];
            }
        }

        throw new ReleaseException(sprintf(
            'Release paused: %s has no successful %s run for %s.',
            $repository,
            $workflow,
            $sha,
        ));
    }

    /** @param list<string> $command */
    private function required(array $command): string
    {
        $result = $this->runner->run($command);

        if ($result['exitCode'] !== 0) {
            throw new ReleaseException(sprintf(
                'Release paused: unable to inspect %s.',
                implode(' ', array_slice($command, 0, 4)),
            ));
        }

        return trim($result['output']);
    }

    private function assertSha(string $sha, string $label): void
    {
        if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
            throw new ReleaseException(sprintf('Release paused: %s is not an exact commit SHA.', $label));
        }
    }
}
