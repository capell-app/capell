<?php

declare(strict_types=1);

use Capell\Release\CommandRunner;
use Capell\Release\ProcessCommandRunner;
use Capell\Release\ReleaseEngine;
use Capell\Release\ReleaseException;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/scripts/release/ReleaseEngine.php';

$scenario = $argv[1];
$root = sys_get_temp_dir() . '/split-ancestry-' . bin2hex(random_bytes(6));
mkdir($root . '/source/packages/core', 0777, true);
$process = new ProcessCommandRunner;
$run = static function (array $command, ?string $cwd = null) use ($process): string {
    throw_unless(array_is_list($command), RuntimeException::class, 'Fixture commands must be argument lists');
    $result = $process->run($command, $cwd);
    throw_unless($result['exitCode'] === 0, RuntimeException::class, implode(' ', $command) . ': ' . ($result['error'] ?? $result['output']));

    return $result['output'];
};
$git = static fn (array $args): string => $run(['git', ...$args], $root . '/source');
$token = getenv('GH_TOKEN');

try {
    $run(['git', 'init', '--bare', $root . '/split.git']);
    $run(['git', 'init', '--bare', $root . '/origin.git']);
    $git(['init', '--initial-branch=main']);
    mkdir($root . '/source/config');
    file_put_contents($root . '/source/config/release-packages.json', json_encode([
        ['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'maturity' => 'stable'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/source/packages/core/composer.json', '{"name":"capell-app/core","require":{}}');
    $git(['add', '.']);
    $git(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'Initial package']);
    $initialTree = $git(['rev-parse', 'HEAD:packages/core']);
    $initial = $git(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit-tree', $initialTree, '-m', 'Initial split']);
    if ($scenario !== 'empty') {
        $git(['-c', 'core.hooksPath=/dev/null', 'push', $root . '/split.git', $initial . ':refs/heads/main']);
    }

    file_put_contents($root . '/source/packages/core/CHANGELOG.md', 'New release');
    $git(['add', '.']);
    $git(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'Release package']);
    $sha = $git(['rev-parse', 'HEAD']);
    $tree = $git(['rev-parse', 'HEAD:packages/core']);
    $history = ['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'version' => '1.0.0', 'previous_version' => null, 'source_commit' => $sha, 'subtree_hash' => $tree, 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'maturity' => 'stable'];
    $plan = ['schema_version' => 1, 'source' => ['repository' => 'source', 'commit' => $sha], 'inventory' => [['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'version' => '1.0.0']], 'ledger' => [$history], 'packages' => [[
        'name' => 'capell-app/core', 'path' => 'packages/core', 'split_repository' => 'capell-app/core', 'current_version' => null, 'proposed_version' => '1.0.0',
        'source_commit' => $sha, 'source_tag' => 'core/v1.0.0', 'subtree_hash' => $tree, 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'reason' => 'baseline',
        'release_type' => 'baseline', 'publication_state' => 'pending', 'tag_sha' => null, 'maturity' => 'stable',
    ]], 'dependency_order' => ['capell-app/core']];
    $runner = new class($root, $process, $scenario, $initial, $initialTree) implements CommandRunner
    {
        public string $proposed = '';

        public string $lease = '';

        public string $remoteMain = '';

        public int $mainPushes = 0;

        public ?int $ancestryExit = null;

        public ?int $leaseFetchExit = null;

        private int $mainReads = 0;

        public function __construct(private readonly string $root, private readonly ProcessCommandRunner $process, private readonly string $scenario, private readonly string $initial, private readonly string $initialTree) {}

        #[Override]
        public function run(array $command, ?string $workingDirectory = null): array
        {
            if ($command[0] === 'gh') {
                if ($command[1] === 'release') {
                    return ['output' => '{}', 'exitCode' => 0];
                }

                $prefix = 'repos/capell-app/core/git/ref/';
                throw_unless(str_starts_with($command[2], $prefix), RuntimeException::class, 'Unexpected GitHub fixture command');
                if ($command[2] === $prefix . 'heads/main' && ++$this->mainReads === 2) {
                    if ($this->scenario === 'divergence') {
                        $this->advanceRemote();
                    }

                    if ($this->scenario === 'unfetchable-lease') {
                        $this->lease = str_repeat('f', 40);

                        return ['output' => $this->lease, 'exitCode' => 0];
                    }
                }

                $result = $this->process->run(['git', '--git-dir=' . $this->root . '/split.git', 'rev-parse', '--verify', 'refs/' . substr($command[2], strlen($prefix))]);
                if ($command[2] === $prefix . 'heads/main' && $result['exitCode'] === 0) {
                    $this->lease = $result['output'];
                    $this->remoteMain = $this->lease;
                }

                return $result;
            }

            // Only GitHub transport is substituted; fetches, ancestry checks and
            // leased pushes operate on real commits in temporary bare repos.
            $command = array_map(fn (string $arg): string => match ($arg) {
                'https://github.com/capell-app/core.git' => $this->root . '/split.git',
                'origin' => $this->root . '/origin.git',
                default => $arg,
            }, $command);
            $leaseFetch = in_array('fetch', $command, true) && end($command) === $this->lease;
            if ($leaseFetch && $this->scenario === 'unknown-ancestry') {
                // The unreferenced split object disappears before ancestry is
                // checked, so real Git must return 128 rather than 0 or 1.
                unlink($this->root . '/source/.git/objects/' . substr($this->proposed, 0, 2) . '/' . substr($this->proposed, 2));
            }

            if (in_array('push', $command, true) && str_ends_with(end($command), ':refs/heads/main')) {
                $this->mainPushes++;
                if ($this->scenario === 'late-race') {
                    $this->advanceRemote();
                }
            }

            $result = $this->process->run($command, $workingDirectory);
            if (in_array('commit-tree', $command, true)) {
                $this->proposed = $result['output'];
            }

            if ($leaseFetch) {
                $this->leaseFetchExit = $result['exitCode'];
            }

            if (in_array('merge-base', $command, true)) {
                $this->ancestryExit = $result['exitCode'];
            }

            return $result;
        }

        private function advanceRemote(): void
        {
            $result = $this->process->run(['git', '--git-dir=' . $this->root . '/split.git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit-tree', $this->initialTree, '-p', $this->initial, '-m', 'Concurrent publication']);
            throw_unless($result['exitCode'] === 0, RuntimeException::class, 'Unable to create concurrent split commit');
            $this->remoteMain = $result['output'];
            $result = $this->process->run(['git', '--git-dir=' . $this->root . '/split.git', 'update-ref', 'refs/heads/main', $this->remoteMain]);
            throw_unless($result['exitCode'] === 0, RuntimeException::class, 'Unable to advance split main');
        }
    };
    putenv('GH_TOKEN=local-fixture-token');
    $failure = null;
    try {
        new ReleaseEngine($root . '/source', $runner)->publish($plan, $root . '/plan.json');
    } catch (ReleaseException $exception) {
        $failure = $exception->getMessage();
    }

    $remote = static fn (array $args): string => $run(['git', '--git-dir=' . $root . '/split.git', ...$args]);
    $rejected = in_array($scenario, ['divergence', 'unknown-ancestry', 'unfetchable-lease'], true);
    if ($rejected) {
        throw_unless($failure !== null && str_contains($failure, 'capell-app/core') && str_contains($failure, $runner->lease) && str_contains($failure, $runner->proposed), RuntimeException::class, 'Expected ancestry refusal naming repository, lease and proposed SHA; got: ' . ($failure ?? 'publication succeeded'));
        throw_unless($runner->mainPushes === 0 && ! is_file($root . '/plan.json.state.json'), RuntimeException::class, 'Rejected ancestry must not push or record publication state');
        throw_unless($remote(['rev-parse', 'refs/heads/main']) === $runner->remoteMain, RuntimeException::class, 'Rejected publication changed split main');
        $tag = $process->run(['git', '--git-dir=' . $root . '/split.git', 'rev-parse', '--verify', 'refs/tags/v1.0.0']);
        throw_unless($tag['exitCode'] !== 0, RuntimeException::class, 'Rejected publication created a split tag');
        if ($scenario !== 'unfetchable-lease') {
            throw_unless($runner->leaseFetchExit === 0 && $runner->ancestryExit === ($scenario === 'divergence' ? 1 : 128), RuntimeException::class, 'Expected real Git ancestry failure');
        } else {
            throw_unless($runner->leaseFetchExit !== null && $runner->leaseFetchExit !== 0 && $runner->ancestryExit === null, RuntimeException::class, 'An unfetchable lease must fail before ancestry or push');
        }
    } elseif ($scenario === 'late-race') {
        throw_unless($failure !== null && $runner->ancestryExit === 0 && $runner->mainPushes === 1 && $remote(['rev-parse', 'refs/heads/main']) === $runner->remoteMain, RuntimeException::class, 'The push lease must reject movement after a successful ancestry check');
    } else {
        throw_unless($failure === null, RuntimeException::class, $failure ?? 'Publication failed');
        throw_unless($remote(['rev-parse', 'refs/heads/main']) === $runner->proposed && $remote(['rev-parse', 'refs/tags/v1.0.0']) === $runner->proposed, RuntimeException::class, 'Expected matching split main and tag');
        if ($scenario === 'fast-forward') {
            $git(['merge-base', '--is-ancestor', $initial, $runner->proposed]);
        } else {
            throw_unless($remote(['show', '-s', '--format=%P', 'refs/heads/main']) === '', RuntimeException::class, 'First publication must have no split parent');
        }
    }

    echo 'PASS ' . $scenario . ' ancestry_exit=' . ($runner->ancestryExit ?? 'none') . ' fetch_exit=' . ($runner->leaseFetchExit ?? 'none') . ' main_pushes=' . $runner->mainPushes . PHP_EOL;
} finally {
    putenv($token === false ? 'GH_TOKEN' : 'GH_TOKEN=' . $token);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        throw_unless($file instanceof SplFileInfo, RuntimeException::class, 'Unexpected fixture file entry');
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($root);
}
