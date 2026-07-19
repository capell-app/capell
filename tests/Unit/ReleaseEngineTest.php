<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/release/ReleaseEngine.php';

use Capell\Release\CommandRunner;
use Capell\Release\DependencyGraph;
use Capell\Release\PlanValidator;
use Capell\Release\ProcessCommandRunner;
use Capell\Release\ReleaseEngine;
use Capell\Release\ReleaseException;
use Capell\Release\ResumeDecision;

beforeEach(function (): void {
    putenv('RELEASE_PREFLIGHT_SCRIPT=' . dirname(__DIR__, 2) . '/scripts/release-preflight.php');
});

it('captures large output from both process streams without blocking', function (): void {
    $runner = new ProcessCommandRunner;
    $result = $runner->run([
        PHP_BINARY,
        '-r',
        'fwrite(STDERR, str_repeat("error", 20000)); echo str_repeat("output", 20000);',
    ]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['output'])->toHaveLength(120000)
        ->and($result['error'] ?? '')->toHaveLength(100000);
});

it('orders direct dependencies before consumers', function (): void {
    expect(DependencyGraph::order(['admin' => ['core'], 'core' => [], 'marketplace' => ['admin', 'core']]))
        ->toBe(['core', 'admin', 'marketplace']);
});

it('rejects dependency cycles', function (): void {
    DependencyGraph::order(['core' => ['admin'], 'admin' => ['core']]);
})->throws(ReleaseException::class, 'Dependency cycle');

it('rejects self versions and legacy Capell constraints', function (array $manifest): void {
    (new PlanValidator)->validateManifest($manifest);
})->with([
    [['version' => '1.0.0']],
    [['require' => ['capell-app/core' => '*']]],
    [['require' => ['capell-app/core' => '^4.0']]],
    [['require' => ['capell-app/core' => '^3.0']]],
    [['require' => ['capell-app/core' => '~4.0']]],
    [['require' => ['capell-app/core' => '>=4 <5']]],
    [['require' => ['capell-app/core' => '4']]],
    [['require' => ['capell-app/core' => '0.0.*']]],
])->throws(ReleaseException::class);

it('accepts a stable v1 internal minimum', function (): void {
    (new PlanValidator)->validateManifest(['require' => ['capell-app/core' => '^1.0']]);
    expect(true)->toBeTrue();
});

it('requires the plan ledger to exactly match the declared release package inventory', function (array $definitions): void {
    $plan = releaseEnginePlan(str_repeat('a', 40), str_repeat('b', 40));

    expect(fn () => PlanValidator::assertDeclaredMaturity($plan, $definitions))
        ->toThrow(ReleaseException::class, 'Plan ledger must exactly match declared release package inventory.');
})->with([
    'missing declaration' => [[]],
    'unknown declaration' => [[['name' => 'capell-app/admin', 'maturity' => 'stable']]],
    'additional declaration' => [[
        ['name' => 'capell-app/core', 'maturity' => 'stable'],
        ['name' => 'capell-app/admin', 'maturity' => 'stable'],
    ]],
]);

it('rejects release metadata that does not describe the exact version transition', function (array $package): void {
    expect(fn () => PlanValidator::assertVersionTransition($package))
        ->toThrow(ReleaseException::class, 'Invalid release transition for capell-app/core.');
})->with([
    'unknown type' => [['name' => 'capell-app/core', 'current_version' => '1.0.0', 'proposed_version' => '1.0.1', 'release_type' => 'hotfix', 'maturity' => 'stable']],
    'baseline with current version' => [['name' => 'capell-app/core', 'current_version' => '1.0.0', 'proposed_version' => '1.0.0', 'release_type' => 'baseline', 'maturity' => 'stable']],
    'skipped patch' => [['name' => 'capell-app/core', 'current_version' => '1.0.0', 'proposed_version' => '1.0.2', 'release_type' => 'patch', 'maturity' => 'stable']],
    'invalid beta opening' => [['name' => 'capell-app/core', 'current_version' => '1.0.0', 'proposed_version' => '1.1.0-beta.2', 'release_type' => 'beta', 'maturity' => 'beta']],
    'invalid promotion' => [['name' => 'capell-app/core', 'current_version' => '1.1.0-beta.2', 'proposed_version' => '1.1.1', 'release_type' => 'promote', 'maturity' => 'stable']],
]);

it('validates local package requirements against an imported external ledger', function (): void {
    $sourceCommit = str_repeat('a', 40);
    $externalCommit = str_repeat('b', 40);
    $externalTree = str_repeat('c', 40);
    $localTree = str_repeat('d', 40);
    $external = [
        'name' => 'capell-app/core',
        'path' => 'packages/core',
        'repository' => 'capell-app/core',
        'version' => '1.0.0',
        'previous_version' => null,
        'source_commit' => $externalCommit,
        'subtree_hash' => $externalTree,
        'direct_capell_dependencies' => [],
        'resolved_minimum_versions' => [],
        'maturity' => 'stable',
    ];
    $local = [
        'name' => 'capell-app/example',
        'path' => 'packages/example',
        'repository' => 'capell-app/example',
        'version' => '1.0.0',
        'previous_version' => null,
        'source_commit' => $sourceCommit,
        'source_tag' => 'example/v1.0.0',
        'subtree_hash' => $localTree,
        'direct_capell_dependencies' => ['capell-app/core'],
        'resolved_minimum_versions' => ['capell-app/core' => '1.0.0'],
        'maturity' => 'stable',
    ];
    $selected = [
        'name' => 'capell-app/example',
        'path' => 'packages/example',
        'split_repository' => 'capell-app/example',
        'current_version' => null,
        'proposed_version' => '1.0.0',
        'source_commit' => $sourceCommit,
        'source_tag' => 'example/v1.0.0',
        'subtree_hash' => $localTree,
        'direct_capell_dependencies' => ['capell-app/core'],
        'resolved_minimum_versions' => ['capell-app/core' => '1.0.0'],
        'reason' => 'baseline',
        'release_type' => 'baseline',
        'publication_state' => 'pending',
        'tag_sha' => null,
        'maturity' => 'stable',
    ];
    $plan = [
        'schema_version' => 1,
        'source' => ['repository' => 'source', 'commit' => $sourceCommit],
        'inventory' => [['name' => 'capell-app/example', 'path' => 'packages/example', 'repository' => 'capell-app/example', 'version' => '1.0.0']],
        'external_ledger' => [$external],
        'ledger' => [$local],
        'packages' => [$selected],
        'dependency_order' => ['capell-app/example'],
    ];

    (new PlanValidator)->validate($plan);
    expect(true)->toBeTrue();

    $missing = $plan;
    $missing['external_ledger'] = [];
    expect(fn () => (new PlanValidator)->validate($missing))
        ->toThrow(ReleaseException::class, 'unknown, duplicate, or self dependency');

    $incompatible = $plan;
    $incompatible['external_ledger'][0]['version'] = '1.1.0';
    expect(fn () => (new PlanValidator)->validate($incompatible))
        ->toThrow(ReleaseException::class, 'incompatible minimum');

    $cycle = $plan;
    $cycle['external_ledger'][0]['direct_capell_dependencies'] = ['capell-app/example'];
    $cycle['external_ledger'][0]['resolved_minimum_versions'] = ['capell-app/example' => '1.0.0'];
    expect(fn () => (new PlanValidator)->validate($cycle))
        ->toThrow(ReleaseException::class, 'Dependency cycle');
});

it('resumes only an existing matching immutable tag', function (): void {
    expect(ResumeDecision::forTag(null, 'abc'))->toBe('publish')
        ->and(ResumeDecision::forTag('abc', 'abc'))->toBe('resume');
    ResumeDecision::forTag('def', 'abc');
})->throws(ReleaseException::class, 'immutable tag');

it('publishes a verified split and records atomic resumable state', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $split = str_repeat('c', 40);
    $tagSha = str_repeat('d', 40);
    $runner = new class($sha, $tree, $split, $tagSha) implements CommandRunner
    {
        public array $commands = [];

        public function __construct(private readonly string $sha, private readonly string $tree, private readonly string $split, private readonly string $tagSha) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $joined = implode(' ', $command);

            if (str_contains($joined, 'git/ref/heads/main')) {
                return ['output' => str_repeat('f', 40), 'exitCode' => 0];
            }

            return match (true) {
                str_contains($joined, 'status --porcelain') => ['output' => '', 'exitCode' => 0],
                str_contains($joined, 'rev-parse HEAD') => ['output' => $this->sha, 'exitCode' => 0],
                str_contains($joined, ':packages/core') => ['output' => $this->tree, 'exitCode' => 0],
                str_contains($joined, 'commit-tree'), str_contains($joined, 'subtree split') => ['output' => $this->split, 'exitCode' => 0],
                str_contains($joined, str_repeat('f', 40) . '^{tree}') => ['output' => str_repeat('e', 40), 'exitCode' => 0],
                str_contains($joined, '^{tree}') => ['output' => $this->tree, 'exitCode' => 0],
                str_contains($joined, 'git/ref/tags') && count(array_filter($this->commands, fn (array $seen): bool => str_contains(implode(' ', $seen), 'git/ref/tags'))) === 1 => ['output' => '', 'exitCode' => 1],
                str_contains($joined, 'git/ref/tags') => ['output' => $this->tagSha, 'exitCode' => 0],
                default => ['output' => '', 'exitCode' => 0],
            };
        }
    };
    $plan = ['schema_version' => 1, 'source' => ['repository' => 'source', 'commit' => $sha], 'inventory' => [['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'version' => '1.0.0']], 'ledger' => [['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'version' => '1.0.0', 'previous_version' => null, 'source_commit' => $sha, 'subtree_hash' => $tree, 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'maturity' => 'stable']], 'packages' => [[
        'name' => 'capell-app/core', 'path' => 'packages/core', 'split_repository' => 'capell-app/core', 'current_version' => null,
        'proposed_version' => '1.0.0', 'source_commit' => $sha, 'source_tag' => 'core/v1.0.0', 'subtree_hash' => $tree, 'direct_capell_dependencies' => [],
        'resolved_minimum_versions' => [], 'reason' => 'baseline', 'release_type' => 'baseline', 'publication_state' => 'pending', 'tag_sha' => null, 'maturity' => 'stable',
    ]], 'dependency_order' => ['capell-app/core']];
    $path = tempnam(sys_get_temp_dir(), 'release-plan-');
    putenv('GH_TOKEN=test-token');
    new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, $path);
    $state = json_decode((string) file_get_contents($path . '.state.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($state['packages']['capell-app/core']['split_sha'])->toBe($split)
        ->and($state['packages']['capell-app/core']['tag_sha'])->toBe($tagSha)
        ->and(implode("\n", array_map(fn (array $command): string => implode(' ', $command), $runner->commands)))
        ->toContain('commit-tree')->toContain(':refs/heads/main')->toContain('--force-with-lease=refs/heads/main:' . str_repeat('f', 40))->toContain(':refs/tags/v1.0.0');
    $commands = array_map(fn (array $command): string => implode(' ', $command), $runner->commands);
    $mainIndex = array_find_key($commands, fn (string $command): bool => str_contains($command, ':refs/heads/main'));
    $preflightIndex = array_find_key($commands, fn (string $command): bool => str_contains($command, 'release-preflight.php'));
    $sourceTagIndex = array_find_key($commands, fn (string $command): bool => str_contains($command, 'refs/tags/core/v1.0.0:refs/tags/core/v1.0.0'));
    $tagIndex = array_find_key($commands, fn (string $command): bool => str_contains($command, ':refs/tags/v1.0.0'));
    expect($mainIndex)->toBeLessThan($preflightIndex)->and($preflightIndex)->toBeLessThan($sourceTagIndex)->and($sourceTagIndex)->toBeLessThan($tagIndex);
    @unlink($path);
    @unlink($path . '.state.json');
});

it('aborts a mismatched remote tag before pushing', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $split = str_repeat('c', 40);
    $runner = new class($sha, $tree, $split) implements CommandRunner
    {
        public array $commands = [];

        public function __construct(private readonly string $sha, private readonly string $tree, private readonly string $split) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);

            return ['output' => match (true) {
                str_contains($text, 'status --porcelain') => '', str_contains($text, 'rev-parse HEAD') => $this->sha,
                str_contains($text, ':packages/core'), str_contains($text, '^{tree}') => $this->tree,
                str_contains($text, 'subtree split') => $this->split, str_contains($text, 'git/ref/tags') => str_repeat('d', 40),
                str_contains($text, 'git/tags/') => str_repeat('e', 40), default => '',
            }, 'exitCode' => 0];
        }
    };
    $plan = releaseEnginePlan($sha, $tree);
    try {
        new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, tempnam(sys_get_temp_dir(), 'plan-'));
    } catch (ReleaseException $releaseException) {
        expect($releaseException->getMessage())->toContain('immutable tag');
    }

    expect(array_filter($runner->commands, fn (array $command): bool => in_array('push', $command, true)))->toBeEmpty();
});

it('verify rejects remote main drift', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $split = str_repeat('c', 40);
    $tag = str_repeat('d', 40);
    $runner = new readonly class($sha, $tree, $tag) implements CommandRunner
    {
        public function __construct(private string $sha, private string $tree, private string $tag) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $text = implode(' ', $command);

            return ['output' => match (true) {
                str_contains($text, 'status') => '',str_contains($text, 'rev-parse HEAD') => $this->sha,str_contains($text, ':packages/core') => $this->tree,str_contains($text, 'isPrerelease') => 'false',str_contains($text, 'git/ref/tags') => $this->tag,str_contains($text, 'git/ref/heads/main') => str_repeat('e', 40),default => 'ok'
            }, 'exitCode' => 0];
        }
    };
    $path = tempnam(sys_get_temp_dir(), 'plan-');
    file_put_contents($path . '.state.json', json_encode(['packages' => ['capell-app/core' => ['tag_sha' => $tag, 'split_sha' => $split]]], JSON_THROW_ON_ERROR));
    $plan = releaseEnginePlan($sha, $tree);
    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->verify($plan, $path))
        ->toThrow(ReleaseException::class, 'Remote main drift');
    @unlink($path);
    @unlink($path . '.state.json');
});

it('publish refuses exact source subtree drift before splitting', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $runner = new class($sha) implements CommandRunner
    {
        public array $commands = [];

        public function __construct(private readonly string $sha) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);

            return ['output' => match (true) {
                str_contains($text, 'status') => '',str_contains($text, 'rev-parse HEAD') => $this->sha,default => str_repeat('f', 40)
            }, 'exitCode' => 0];
        }
    };
    $plan = releaseEnginePlan($sha, $tree);
    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, tempnam(sys_get_temp_dir(), 'plan-')))
        ->toThrow(ReleaseException::class, 'Source tree drift');
    expect(array_filter($runner->commands, fn (array $command): bool => in_array('subtree', $command, true)))->toBeEmpty();
});

/** @return array<string,mixed> */
function releaseEnginePlan(string $sha, string $tree): array
{
    $history = ['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'version' => '1.0.0', 'previous_version' => null, 'source_commit' => $sha, 'subtree_hash' => $tree, 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'maturity' => 'stable'];

    return ['schema_version' => 1, 'source' => ['repository' => 'source', 'commit' => $sha], 'inventory' => [['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'version' => '1.0.0']], 'ledger' => [$history], 'packages' => [[
        'name' => 'capell-app/core', 'path' => 'packages/core', 'split_repository' => 'capell-app/core', 'current_version' => null, 'proposed_version' => '1.0.0',
        'source_commit' => $sha, 'source_tag' => 'core/v1.0.0', 'subtree_hash' => $tree, 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'reason' => 'baseline',
        'release_type' => 'baseline', 'publication_state' => 'pending', 'tag_sha' => null, 'maturity' => 'stable',
    ]], 'dependency_order' => ['capell-app/core']];
}

/** @param array<string,mixed> $plan */
function releaseEngineRootForPlan(array $plan): string
{
    $root = sys_get_temp_dir() . '/capell-release-' . bin2hex(random_bytes(5));
    mkdir($root . '/config', 0777, true);
    $definitions = array_map(fn (array $entry): array => [
        'name' => $entry['name'],
        'path' => $entry['path'],
        'repository' => $entry['repository'],
        'maturity' => $entry['maturity'],
    ], $plan['ledger']);
    file_put_contents($root . '/config/release-packages.json', json_encode($definitions, JSON_THROW_ON_ERROR));

    return $root;
}

function twoPackageReleasePlan(string $sha, array $trees): array
{
    $plan = releaseEnginePlan($sha, $trees['capell-app/core']);
    $plan['inventory'][] = ['name' => 'capell-app/admin', 'path' => 'packages/admin', 'repository' => 'capell-app/admin', 'version' => '1.0.0'];
    $plan['ledger'][] = ['name' => 'capell-app/admin', 'path' => 'packages/admin', 'repository' => 'capell-app/admin', 'version' => '1.0.0', 'previous_version' => null, 'source_commit' => $sha, 'subtree_hash' => $trees['capell-app/admin'], 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'maturity' => 'stable'];
    $plan['packages'][] = ['name' => 'capell-app/admin', 'path' => 'packages/admin', 'split_repository' => 'capell-app/admin', 'current_version' => null, 'proposed_version' => '1.0.0', 'source_commit' => $sha, 'source_tag' => 'admin/v1.0.0', 'subtree_hash' => $trees['capell-app/admin'], 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'reason' => 'baseline', 'release_type' => 'baseline', 'publication_state' => 'pending', 'tag_sha' => null, 'maturity' => 'stable'];
    $plan['dependency_order'][] = 'capell-app/admin';

    return $plan;
}

it('persists full history and releases every foundation package at one lockstep version', function (): void {
    $root = sys_get_temp_dir() . '/capell-release-' . bin2hex(random_bytes(5));
    mkdir($root . '/config', 0777, true);
    foreach (['core' => [], 'admin' => ['capell-app/core' => 'self.version'], 'marketplace' => ['capell-app/admin' => 'self.version']] as $path => $requires) {
        mkdir($root . '/packages/' . $path, 0777, true);
        file_put_contents($root . '/packages/' . $path . '/composer.json', json_encode(['name' => 'capell-app/' . $path, 'require' => $requires], JSON_THROW_ON_ERROR));
    }

    file_put_contents($root . '/config/release-packages.json', json_encode(array_map(fn (string $path): array => ['name' => 'capell-app/' . $path, 'path' => 'packages/' . $path, 'repository' => 'capell-app/' . $path], ['core', 'admin', 'marketplace']), JSON_THROW_ON_ERROR));
    $runner = new class implements CommandRunner
    {
        public string $commit;

        public array $trees = [];

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $text = implode(' ', $command);
            $output = match (true) {
                str_contains($text, 'status --porcelain') => '',
                str_contains($text, 'rev-parse HEAD') => $this->commit,
                str_contains($text, 'config --get remote.origin.url') => 'git@example.test:capell/core.git',
                str_contains($text, ':packages/core') => $this->trees['core'],
                str_contains($text, ':packages/admin') => $this->trees['admin'],
                str_contains($text, ':packages/marketplace') => $this->trees['marketplace'],
                default => '',
            };

            return ['output' => $output, 'exitCode' => 0];
        }
    };
    $runner->commit = str_repeat('1', 40);
    $runner->trees = ['core' => str_repeat('a', 40), 'admin' => str_repeat('b', 40), 'marketplace' => str_repeat('c', 40)];

    $engine = new ReleaseEngine($root, $runner);
    $baseline = $engine->plan('1.0.0');
    expect($baseline['ledger'])->toHaveCount(3)->and($baseline['packages'])->toHaveCount(3)
        ->and(array_column($baseline['packages'], 'source_tag'))->toBe(['core/v1.0.0', 'admin/v1.0.0', 'marketplace/v1.0.0']);

    $runner->commit = str_repeat('2', 40);
    $runner->trees['core'] = str_repeat('d', 40);
    $increment = $engine->plan('incremental', $baseline, ['capell-app/core' => 'minor']);
    expect(array_column($increment['packages'], 'name'))->toBe(['capell-app/core', 'capell-app/admin', 'capell-app/marketplace'])
        ->and(array_values(array_unique(array_column($increment['packages'], 'proposed_version'))))->toBe(['1.1.0'])
        ->and(array_values(array_unique(array_column($increment['packages'], 'release_type'))))->toBe(['minor'])
        ->and(array_values(array_unique(array_column($increment['packages'], 'reason'))))->toBe(['Lockstep minor foundation release.'])
        ->and($increment['ledger'])->toHaveCount(3);

    $runner->commit = str_repeat('3', 40);
    $runner->trees['marketplace'] = str_repeat('e', 40);
    $next = $engine->plan('incremental', $increment, ['capell-app/marketplace' => 'major']);
    expect(array_column($next['packages'], 'name'))->toBe(['capell-app/core', 'capell-app/admin', 'capell-app/marketplace'])
        ->and(array_values(array_unique(array_column($next['packages'], 'proposed_version'))))->toBe(['2.0.0'])
        ->and(array_values(array_unique(array_column($next['packages'], 'release_type'))))->toBe(['major'])
        ->and(array_values(array_unique(array_column($next['packages'], 'reason'))))->toBe(['Lockstep major foundation release.'])
        ->and($next['ledger'])->toHaveCount(3)
        ->and(array_values(array_unique(array_column($next['ledger'], 'version'))))->toBe(['2.0.0']);
});

it('rejects beta and promote bump requests because foundation packages release in stable lockstep', function (string $type): void {
    $root = sys_get_temp_dir() . '/capell-release-' . bin2hex(random_bytes(5));
    mkdir($root . '/config', 0777, true);
    mkdir($root . '/packages/core', 0777, true);
    file_put_contents($root . '/packages/core/composer.json', json_encode(['name' => 'capell-app/core'], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/config/release-packages.json', json_encode([['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core']], JSON_THROW_ON_ERROR));
    $runner = new readonly class implements CommandRunner
    {
        public function run(array $command, ?string $workingDirectory = null): array
        {
            $text = implode(' ', $command);

            return ['output' => match (true) {
                str_contains($text, 'status --porcelain') => '',
                str_contains($text, 'rev-parse HEAD') => str_repeat('1', 40),
                default => str_repeat('a', 40),
            }, 'exitCode' => 0];
        }
    };
    expect(fn (): array => new ReleaseEngine($root, $runner)->plan('1.0.0', null, ['capell-app/core' => $type]))
        ->toThrow(ReleaseException::class, 'Foundation packages release in stable lockstep.');
})->with(['beta', 'promote']);

it('rejects invalid ledger dependency semantics', function (Closure $mutate, string $message): void {
    $plan = releaseEnginePlan(str_repeat('a', 40), str_repeat('b', 40));
    $plan['ledger'][] = ['name' => 'capell-app/admin', 'path' => 'packages/admin', 'repository' => 'capell-app/admin', 'version' => '1.0.0', 'previous_version' => null, 'source_commit' => str_repeat('a', 40), 'subtree_hash' => str_repeat('c', 40), 'direct_capell_dependencies' => ['capell-app/core'], 'resolved_minimum_versions' => ['capell-app/core' => '1.0.0'], 'maturity' => 'stable'];
    $plan['inventory'][] = ['name' => 'capell-app/admin', 'path' => 'packages/admin', 'repository' => 'capell-app/admin', 'version' => '1.0.0'];
    $mutate($plan);
    expect(fn () => (new PlanValidator)->validate($plan))->toThrow(ReleaseException::class, $message);
})->with([
    'unknown dependency' => [function (array &$plan): void {
        $plan['ledger'][1]['direct_capell_dependencies'] = ['capell-app/missing'];
        $plan['ledger'][1]['resolved_minimum_versions'] = ['capell-app/missing' => '1.0.0'];
    }, 'unknown'],
    'self dependency' => [function (array &$plan): void {
        $plan['ledger'][1]['direct_capell_dependencies'] = ['capell-app/admin'];
        $plan['ledger'][1]['resolved_minimum_versions'] = ['capell-app/admin' => '1.0.0'];
    }, 'self'],
    'cycle' => [function (array &$plan): void {
        $plan['ledger'][0]['direct_capell_dependencies'] = ['capell-app/admin'];
        $plan['ledger'][0]['resolved_minimum_versions'] = ['capell-app/admin' => '1.0.0'];
    }, 'Dependency cycle'],
    'missing minimum' => [function (array &$plan): void {
        $plan['ledger'][1]['resolved_minimum_versions'] = [];
    }, 'resolve every'],
    'extra minimum' => [function (array &$plan): void {
        $plan['ledger'][1]['resolved_minimum_versions']['capell-app/admin'] = '1.0.0';
    }, 'resolve every'],
    'incompatible minimum' => [function (array &$plan): void {
        $plan['ledger'][1]['resolved_minimum_versions']['capell-app/core'] = '1.1.0';
    }, 'incompatible'],
]);

it('rejects non-canonical versions in ledger and selected packages', function (): void {
    $plan = releaseEnginePlan(str_repeat('a', 40), str_repeat('b', 40));
    $plan['ledger'][0]['version'] = '1.01.00';
    expect(fn () => (new PlanValidator)->validate($plan))->toThrow(ReleaseException::class, 'Ledger entry');
    $plan = releaseEnginePlan(str_repeat('a', 40), str_repeat('b', 40));
    $plan['packages'][0]['proposed_version'] = '1.01.00';
    expect(fn () => (new PlanValidator)->validate($plan))->toThrow(ReleaseException::class, 'Invalid proposed version');
});

it('rejects a foundation plan with mixed package versions', function (): void {
    $plan = twoPackageReleasePlan(str_repeat('a', 40), [
        'capell-app/core' => str_repeat('b', 40),
        'capell-app/admin' => str_repeat('c', 40),
    ]);
    $plan['packages'][1]['current_version'] = '1.0.0';
    $plan['packages'][1]['proposed_version'] = '1.0.1';
    $plan['packages'][1]['source_tag'] = 'admin/v1.0.1';
    $plan['packages'][1]['release_type'] = 'patch';
    $plan['ledger'][1]['previous_version'] = '1.0.0';
    $plan['ledger'][1]['version'] = '1.0.1';
    $plan['inventory'][1]['version'] = '1.0.1';

    expect(fn () => (new PlanValidator)->validate($plan))
        ->toThrow(ReleaseException::class, 'one lockstep version');
});

it('rejects cross-representation disagreements', function (Closure $mutate, string $message): void {
    $plan = releaseEnginePlan(str_repeat('a', 40), str_repeat('b', 40));
    $mutate($plan);
    expect(fn () => (new PlanValidator)->validate($plan))->toThrow(ReleaseException::class, $message);
})->with([
    'inventory version' => [fn (array &$plan): string => $plan['inventory'][0]['version'] = '1.0.1', 'Inventory and ledger'],
    'inventory path' => [fn (array &$plan): string => $plan['inventory'][0]['path'] = 'packages/wrong', 'Inventory and ledger'],
    'selected unknown' => [fn (array &$plan): string => $plan['packages'][0]['name'] = 'capell-app/unknown', 'absent from ledger'],
    'selected path' => [fn (array &$plan): string => $plan['packages'][0]['path'] = 'packages/wrong', 'source tag'],
    'selected repository' => [fn (array &$plan): string => $plan['packages'][0]['split_repository'] = 'capell-app/wrong', 'disagrees with ledger'],
    'selected version' => [fn (array &$plan): string => $plan['packages'][0]['proposed_version'] = '1.0.1', 'source tag'],
    'selected hash' => [fn (array &$plan): string => $plan['packages'][0]['subtree_hash'] = str_repeat('c', 40), 'disagrees with ledger'],
    'selected dependencies' => [fn (array &$plan): array => $plan['packages'][0]['direct_capell_dependencies'] = ['capell-app/missing'], 'disagrees with ledger'],
    'selected minimums' => [fn (array &$plan): array => $plan['packages'][0]['resolved_minimum_versions'] = ['capell-app/missing' => '1.0.0'], 'disagrees with ledger'],
]);

it('rejects stale release state before running publication commands', function (): void {
    $plan = releaseEnginePlan(str_repeat('a', 40), str_repeat('b', 40));
    $path = tempnam(sys_get_temp_dir(), 'release-plan-');
    file_put_contents($path . '.state.json', json_encode(['plan_sha256' => str_repeat('0', 64), 'source_commit' => $plan['source']['commit'], 'packages' => []], JSON_THROW_ON_ERROR));
    $runner = new class implements CommandRunner
    {
        public array $commands = [];

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;

            return ['output' => '', 'exitCode' => 0];
        }
    };
    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, $path))
        ->toThrow(ReleaseException::class, 'different plan');
    expect($runner->commands)->toBeEmpty();
    @unlink($path);
    @unlink($path . '.state.json');
});

it('never exposes command stderr secrets when a push fails', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $split = str_repeat('c', 40);
    $secret = 'ghs_super_secret_token';
    $runner = new readonly class($sha, $tree, $split, $secret) implements CommandRunner
    {
        public function __construct(private string $sha, private string $tree, private string $split, private string $secret) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $text = implode(' ', $command);
            if (in_array('push', $command, true)) {
                return ['output' => '', 'error' => 'authorization: Bearer ' . $this->secret, 'exitCode' => 1];
            }

            return ['output' => match (true) {
                str_contains($text, 'status') => '',str_contains($text, 'rev-parse HEAD') => $this->sha,str_contains($text, ':packages/core'),str_contains($text, '^{tree}') => $this->tree,str_contains($text, 'subtree split') => $this->split,default => ''
            }, 'exitCode' => str_contains($text, 'git/ref/tags') ? 1 : 0];
        }
    };
    putenv('GH_TOKEN=' . $secret);
    try {
        $plan = releaseEnginePlan($sha, $tree);
        new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, tempnam(sys_get_temp_dir(), 'plan-'));
        expect(false)->toBeTrue();
    } catch (ReleaseException $releaseException) {
        expect($releaseException->getMessage())->not->toContain($secret)->not->toContain('Bearer')->toContain('[redacted]');
    }
});

it('preserves completed state while recording a later package', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $split = str_repeat('c', 40);
    $tag = str_repeat('d', 40);
    $plan = releaseEnginePlan($sha, $tree);
    $path = tempnam(sys_get_temp_dir(), 'plan-');
    $hash = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($path . '.state.json', json_encode(['plan_sha256' => $hash, 'source_commit' => $sha, 'packages' => ['capell-app/earlier' => ['state' => 'published', 'tag_sha' => 'kept']]], JSON_THROW_ON_ERROR));
    $runner = new class($sha, $tree, $split, $tag) implements CommandRunner
    {
        private int $tagCalls = 0;

        public function __construct(private readonly string $sha, private readonly string $tree, private readonly string $split, private readonly string $tag) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $text = implode(' ', $command);
            if (str_contains($text, 'git/ref/tags') && ++$this->tagCalls === 1) {
                return ['output' => '', 'exitCode' => 1];
            }

            return ['output' => match (true) {
                str_contains($text, 'status') => '',str_contains($text, 'rev-parse HEAD') => $this->sha,str_contains($text, ':packages/core'),str_contains($text, '^{tree}') => $this->tree,str_contains($text, 'subtree split') => $this->split,str_contains($text, 'git/ref/tags') => $this->tag,default => ''
            }, 'exitCode' => 0];
        }
    };
    putenv('GH_TOKEN=test');
    new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, $path);
    $state = json_decode((string) file_get_contents($path . '.state.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($state['packages']['capell-app/earlier']['tag_sha'])->toBe('kept')->and($state['packages'])->toHaveKey('capell-app/core');
    @unlink($path);
    @unlink($path . '.state.json');
});

it('reuses the recorded split commit when resuming after main was pushed', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $split = str_repeat('c', 40);
    $plan = releaseEnginePlan($sha, $tree);
    $path = tempnam(sys_get_temp_dir(), 'plan-');
    $hash = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($path . '.state.json', json_encode([
        'plan_sha256' => $hash,
        'source_commit' => $sha,
        'packages' => [
            'capell-app/core' => [
                'state' => 'main_pushed',
                'split_sha' => $split,
                'tag' => 'v1.0.0',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $runner = new class($sha, $tree, $split) implements CommandRunner
    {
        public array $commands = [];

        public function __construct(
            private readonly string $sha,
            private readonly string $tree,
            private readonly string $split,
        ) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);

            if (($command[0] ?? null) === PHP_BINARY) {
                return ['output' => '', 'exitCode' => 1];
            }

            if (str_contains($text, 'git/ref/tags')
                || str_contains($text, 'rev-parse -q --verify refs/tags/')
                || str_contains($text, 'ls-remote --tags')) {
                return ['output' => '', 'exitCode' => 1];
            }

            return ['output' => match (true) {
                str_contains($text, 'status') => '',
                str_contains($text, 'rev-parse HEAD') => $this->sha,
                str_contains($text, ':packages/core') => $this->tree,
                str_contains($text, '^{tree}') => $this->tree,
                str_contains($text, 'git/ref/heads/main') => $this->split,
                default => '',
            }, 'exitCode' => 0];
        }
    };

    putenv('GH_TOKEN=test');

    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, $path))
        ->toThrow(ReleaseException::class, 'Command failed: ' . PHP_BINARY);

    $commands = array_map(static fn (array $command): string => implode(' ', $command), $runner->commands);

    expect(array_filter($commands, static fn (string $command): bool => str_contains($command, 'commit-tree')))->toBeEmpty()
        ->and(array_filter($commands, static fn (string $command): bool => str_contains($command, ':refs/heads/main')))->toBeEmpty();

    @unlink($path);
    @unlink($path . '.state.json');
});

it('fails closed when remote main drifted after a recorded push', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $split = str_repeat('c', 40);
    $driftedMain = str_repeat('d', 40);
    $plan = releaseEnginePlan($sha, $tree);
    $path = tempnam(sys_get_temp_dir(), 'plan-');
    $hash = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($path . '.state.json', json_encode([
        'plan_sha256' => $hash,
        'source_commit' => $sha,
        'packages' => [
            'capell-app/core' => [
                'state' => 'main_pushed',
                'split_sha' => $split,
                'tag' => 'v1.0.0',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $runner = new class($sha, $tree, $driftedMain) implements CommandRunner
    {
        public array $commands = [];

        public function __construct(
            private readonly string $sha,
            private readonly string $tree,
            private readonly string $driftedMain,
        ) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);

            return ['output' => match (true) {
                str_contains($text, 'status') => '',
                str_contains($text, 'rev-parse HEAD') => $this->sha,
                str_contains($text, ':packages/core') => $this->tree,
                str_contains($text, 'git/ref/heads/main') => $this->driftedMain,
                default => '',
            }, 'exitCode' => 0];
        }
    };

    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, $path))
        ->toThrow(ReleaseException::class, 'Remote main drift after recorded push for capell-app/core.');

    expect(array_filter(
        $runner->commands,
        static fn (array $command): bool => in_array('push', $command, true) || in_array('commit-tree', $command, true),
    ))->toBeEmpty();

    @unlink($path);
    @unlink($path . '.state.json');
});

it('reuses an unrecorded remote main commit when it already has the planned tree', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $main = str_repeat('c', 40);
    $plan = releaseEnginePlan($sha, $tree);
    $path = tempnam(sys_get_temp_dir(), 'plan-');
    $runner = new class($sha, $tree, $main) implements CommandRunner
    {
        public array $commands = [];

        public function __construct(
            private readonly string $sha,
            private readonly string $tree,
            private readonly string $main,
        ) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);

            if (($command[0] ?? null) === PHP_BINARY) {
                return ['output' => '', 'exitCode' => 1];
            }

            if (str_contains($text, 'git/ref/tags')
                || str_contains($text, 'rev-parse -q --verify refs/tags/')
                || str_contains($text, 'ls-remote --tags')) {
                return ['output' => '', 'exitCode' => 1];
            }

            return ['output' => match (true) {
                str_contains($text, 'status') => '',
                str_contains($text, 'rev-parse HEAD') => $this->sha,
                str_contains($text, ':packages/core') => $this->tree,
                str_contains($text, 'refs/remotes/') => $this->main,
                str_contains($text, '^{tree}') => $this->tree,
                str_contains($text, 'git/ref/heads/main') => $this->main,
                default => '',
            }, 'exitCode' => 0];
        }
    };

    putenv('GH_TOKEN=test');

    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, $path))
        ->toThrow(ReleaseException::class, 'Command failed: ' . PHP_BINARY);

    $commands = array_map(static fn (array $command): string => implode(' ', $command), $runner->commands);

    expect(array_filter($commands, static fn (string $command): bool => str_contains($command, 'commit-tree')))->toBeEmpty()
        ->and(array_filter($commands, static fn (string $command): bool => str_contains($command, ':refs/heads/main')))->toBeEmpty();

    $state = json_decode((string) file_get_contents($path . '.state.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($state['packages']['capell-app/core']['split_sha'])->toBe($main)
        ->and($state['packages']['capell-app/core']['state'])->toBe('main_pushed');

    @unlink($path);
    @unlink($path . '.state.json');
});

it('records all main pushes but creates no tags when multi-package preflight fails', function (): void {
    $sha = str_repeat('a', 40);
    $trees = ['capell-app/core' => str_repeat('b', 40), 'capell-app/admin' => str_repeat('c', 40)];
    $plan = twoPackageReleasePlan($sha, $trees);
    $path = tempnam(sys_get_temp_dir(), 'plan-');
    $secret = 'preflight-secret-token';
    $runner = new class($sha, $trees, $secret) implements CommandRunner
    {
        public array $commands = [];

        private int $splitCalls = 0;

        private int $treeCalls = 0;

        public function __construct(private readonly string $sha, private array $trees, private readonly string $secret) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);
            if (($command[0] ?? '') === PHP_BINARY) {
                return ['output' => '', 'error' => $this->secret, 'exitCode' => 1];
            }

            if (str_contains($text, 'subtree split')) {
                return ['output' => ++$this->splitCalls === 1 ? str_repeat('d', 40) : str_repeat('e', 40), 'exitCode' => 0];
            }

            if (str_contains($text, '^{tree}')) {
                return ['output' => ++$this->treeCalls === 1 ? $this->trees['capell-app/core'] : $this->trees['capell-app/admin'], 'exitCode' => 0];
            }

            return ['output' => match (true) {
                str_contains($text, 'status') => '',str_contains($text, 'rev-parse HEAD') => $this->sha,str_contains($text, ':packages/core') => $this->trees['capell-app/core'],str_contains($text, ':packages/admin') => $this->trees['capell-app/admin'],default => ''
            }, 'exitCode' => str_contains($text, 'git/ref/tags') || str_contains($text, 'git/ref/heads/main') ? 1 : 0];
        }
    };
    putenv('GH_TOKEN=test');
    try {
        new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, $path);
        expect(false)->toBeTrue();
    } catch (ReleaseException $releaseException) {
        expect($releaseException->getMessage())->not->toContain($secret);
    }

    $commands = array_map(fn (array $command): string => implode(' ', $command), $runner->commands);
    expect(array_filter($commands, fn (string $command): bool => str_contains($command, ':refs/tags/')))->toBeEmpty()
        ->and(array_filter($commands, fn (string $command): bool => str_contains($command, 'release create')))->toBeEmpty();
    $state = json_decode((string) file_get_contents($path . '.state.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($state['packages']))->toBe(['capell-app/core', 'capell-app/admin'])
        ->and(array_column($state['packages'], 'state'))->toBe(['main_pushed', 'main_pushed'])->and($state)->not->toHaveKey('preflight');
});

it('rejects a missing preflight executable before remote mutation', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    putenv('RELEASE_PREFLIGHT_SCRIPT=/definitely/missing/preflight.php');
    $runner = new class($sha, $tree) implements CommandRunner
    {
        public array $commands = [];

        public function __construct(private readonly string $sha, private readonly string $tree) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);

            return ['output' => match (true) {
                str_contains($text, 'status') => '',str_contains($text, 'rev-parse HEAD') => $this->sha,str_contains($text, ':packages/core') => $this->tree,default => ''
            }, 'exitCode' => 0];
        }
    };
    $plan = releaseEnginePlan($sha, $tree);
    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, tempnam(sys_get_temp_dir(), 'plan-')))->toThrow(ReleaseException::class, 'preflight script');
    expect(array_filter($runner->commands, fn (array $command): bool => in_array('push', $command, true) || ($command[0] ?? '') === 'gh'))->toBeEmpty();
});

it('checks a later mismatched tag before any main push or state write', function (): void {
    $sha = str_repeat('a', 40);
    $trees = ['capell-app/core' => str_repeat('b', 40), 'capell-app/admin' => str_repeat('c', 40)];
    $path = tempnam(sys_get_temp_dir(), 'plan-');
    $runner = new class($sha, $trees) implements CommandRunner
    {
        public array $commands = [];

        private int $splits = 0;

        private int $splitTrees = 0;

        private int $tagRefs = 0;

        public function __construct(private readonly string $sha, private array $trees) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);
            if (str_contains($text, 'subtree split')) {
                return ['output' => ++$this->splits === 1 ? str_repeat('d', 40) : str_repeat('e', 40), 'exitCode' => 0];
            }

            if (str_contains($text, '^{tree}')) {
                return ['output' => ++$this->splitTrees === 1 ? $this->trees['capell-app/core'] : $this->trees['capell-app/admin'], 'exitCode' => 0];
            }

            if (str_contains($text, 'git/ref/tags')) {
                return ++$this->tagRefs === 1 ? ['output' => '', 'exitCode' => 1] : ['output' => str_repeat('f', 40), 'exitCode' => 0];
            }

            if (str_contains($text, 'git/tags/')) {
                return ['output' => str_repeat('9', 40), 'exitCode' => 0];
            }

            return ['output' => match (true) {
                str_contains($text, 'status') => '',str_contains($text, 'rev-parse HEAD') => $this->sha,str_contains($text, ':packages/core') => $this->trees['capell-app/core'],str_contains($text, ':packages/admin') => $this->trees['capell-app/admin'],default => ''
            }, 'exitCode' => 0];
        }
    };
    $plan = twoPackageReleasePlan($sha, $trees);
    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, $path))->toThrow(ReleaseException::class, 'immutable tag');
    expect(array_filter($runner->commands, fn (array $command): bool => in_array('push', $command, true)))->toBeEmpty()->and(file_exists($path . '.state.json'))->toBeFalse();
});

it('rejects a matching tag without plan-bound passed preflight state', function (): void {
    $sha = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $split = str_repeat('c', 40);
    $runner = new class($sha, $tree, $split) implements CommandRunner
    {
        public array $commands = [];

        public function __construct(private readonly string $sha, private readonly string $tree, private readonly string $split) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = $command;
            $text = implode(' ', $command);

            return ['output' => match (true) {
                str_contains($text, 'status') => '',str_contains($text, 'rev-parse HEAD') => $this->sha,str_contains($text, ':packages/core'),str_contains($text, '^{tree}') => $this->tree,str_contains($text, 'subtree split'),str_contains($text, 'git/ref/tags'),str_contains($text, 'git/tags/') => $this->split,default => ''
            }, 'exitCode' => 0];
        }
    };
    $plan = releaseEnginePlan($sha, $tree);
    expect(fn () => new ReleaseEngine(releaseEngineRootForPlan($plan), $runner)->publish($plan, tempnam(sys_get_temp_dir(), 'plan-')))->toThrow(ReleaseException::class, 'passed preflight state');
    expect(array_filter($runner->commands, fn (array $command): bool => in_array('push', $command, true)))->toBeEmpty();
});
