<?php

declare(strict_types=1);

namespace Capell\Release;

final class ReleaseEligibilityChecker
{
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

    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * @return array{
     *     core_test_all: array{repository: string, workflow: string, sha: string, run_id: int, run_url: string},
     *     app_preflight: array{sha: string, runs: list<array{repository: string, workflow: string, sha: string, run_id: int, run_url: string}>},
     *     packages_preflight: array{sha: string, runs: list<array{repository: string, workflow: string, sha: string, run_id: int, run_url: string}>}
     * }
     */
    public function check(string $coreSha): array
    {
        $this->assertSha($coreSha, 'Core source');

        $evidence = [
            'core_test_all' => $this->successfulRun(
                repository: 'capell-app/capell',
                workflow: 'test-full.yml',
                sha: $coreSha,
            ),
        ];

        foreach (self::DOWNSTREAM_GATES as $gate => $workflows) {
            $repository = $workflows[0]['repository'];
            $sha = $this->mainSha($repository);
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
     * @return array{repository: string, workflow: string, sha: string, run_id: int, run_url: string}
     */
    private function successfulRun(string $repository, string $workflow, string $sha): array
    {
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
            'databaseId,headSha,conclusion,url',
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
