<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/release/ReleaseEngine.php';
require_once dirname(__DIR__, 2) . '/scripts/release/BuildReleaseNotesAction.php';

use Capell\Release\BuildReleaseNotesAction;
use Capell\Release\CommandRunner;
use Capell\Release\ReleaseException;
use Capell\Release\SourceReleaseNotesRunner;

it('builds grouped notes from the non merge commits between two tags', function (): void {
    $runner = new class implements CommandRunner
    {
        public array $commands = [];

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;

            return ['exitCode' => 0, 'output' => $command[1] === 'log' ? "feat(admin): Add controls\nfix: Correct output\nrefactor!: Change contract\nUpdate docs" : ''];
        }
    };
    $notes = new BuildReleaseNotesAction($runner, '/source')->handle('core/v1.0.0', 'core/v1.1.0');

    expect($notes)->toContain("## Breaking changes\n\n- refactor!: Change contract")
        ->toContain("## Added\n\n- feat(admin): Add controls")
        ->toContain("## Fixed\n\n- fix: Correct output")
        ->toContain("## Changed\n\n- Update docs")
        ->and($runner->commands)->toContain(['git', 'log', '--no-merges', '--format=%s', 'refs/tags/core/v1.0.0..refs/tags/core/v1.1.0']);
});

it('refuses invalid or non ancestral tag ranges', function (): void {
    $runner = new class implements CommandRunner
    {
        public function run(array $command, ?string $workingDirectory = null): array
        {
            return ['output' => '', 'exitCode' => $command[1] === 'merge-base' ? 1 : 0];
        }
    };
    $action = new BuildReleaseNotesAction($runner, '/source');
    expect(fn (): string => $action->handle('bad..tag', 'v1.1.0'))->toThrow(ReleaseException::class, 'Invalid release notes tag')
        ->and(fn (): string => $action->handle('v1.0.0', 'v1.1.0'))->toThrow(ReleaseException::class, 'not ancestral');
});

it('prepares notes before publication and supplies them to split releases', function (): void {
    $runner = new class implements CommandRunner
    {
        public array $commands = [];

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;

            return ['output' => $command[1] === 'log' ? 'fix: Repair upgrade' : '', 'exitCode' => 0];
        }
    };
    $plan = ['source' => ['commit' => str_repeat('a', 40)], 'packages' => [[
        'current_version' => '1.0.0', 'path' => 'packages/core', 'split_repository' => 'capell-app/core',
    ]]];
    $publisher = new SourceReleaseNotesRunner($runner, '/source', $plan);
    $publisher->run(['gh', 'release', 'create', 'v1.0.1', '--repo', 'capell-app/core', '--verify-tag', '--generate-notes']);

    expect(end($runner->commands))->toBe(['gh', 'release', 'create', 'v1.0.1', '--repo', 'capell-app/core', '--verify-tag', '--notes', "## Fixed\n\n- fix: Repair upgrade"]);
});

it('stops before publication when the previous source tag cannot be read', function (): void {
    $runner = new class implements CommandRunner
    {
        public array $commands = [];

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;

            return ['output' => '', 'exitCode' => 1];
        }
    };
    $plan = ['source' => ['commit' => str_repeat('a', 40)], 'packages' => [[
        'current_version' => '1.0.0', 'path' => 'packages/core', 'split_repository' => 'capell-app/core',
    ]]];

    expect(fn (): SourceReleaseNotesRunner => new SourceReleaseNotesRunner($runner, '/source', $plan))
        ->toThrow(ReleaseException::class, 'tag range is unavailable')
        ->and($runner->commands)->toHaveCount(1);
});
