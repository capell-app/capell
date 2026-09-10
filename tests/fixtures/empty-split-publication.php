<?php

declare(strict_types=1);

use Capell\Release\CommandRunner;
use Capell\Release\ProcessCommandRunner;
use Capell\Release\ReleaseEngine;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once $argv[1] ?? dirname(__DIR__, 2) . '/scripts/release/ReleaseEngine.php';

// A deleted historical blob exceeds the receiver's small pack limit. Publishing
// the current package tree should never send that unreachable release history.
$root = sys_get_temp_dir() . '/empty-split-' . bin2hex(random_bytes(6));
mkdir($root . '/source/packages/core', 0777, true);
$process = new ProcessCommandRunner;
$run = static function (array $command, ?string $cwd = null) use ($process): string {
    $result = $process->run($command, $cwd);
    if ($result['exitCode'] !== 0) {
        throw new RuntimeException(implode(' ', $command) . ': ' . ($result['error'] ?? $result['output']));
    }

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
    file_put_contents($root . '/source/packages/core/obsolete.bin', random_bytes(262144));
    $git(['add', '.']);
    $git(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'Historical package']);
    unlink($root . '/source/packages/core/obsolete.bin');
    $git(['add', '.']);
    $git(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'Current package']);
    $sha = $git(['rev-parse', 'HEAD']);
    $tree = $git(['rev-parse', 'HEAD:packages/core']);
    $history = ['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'version' => '1.0.0', 'previous_version' => null, 'source_commit' => $sha, 'subtree_hash' => $tree, 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'maturity' => 'stable'];
    $plan = ['schema_version' => 1, 'source' => ['repository' => 'source', 'commit' => $sha], 'inventory' => [['name' => 'capell-app/core', 'path' => 'packages/core', 'repository' => 'capell-app/core', 'version' => '1.0.0']], 'ledger' => [$history], 'packages' => [[
        'name' => 'capell-app/core', 'path' => 'packages/core', 'split_repository' => 'capell-app/core', 'current_version' => null, 'proposed_version' => '1.0.0',
        'source_commit' => $sha, 'source_tag' => 'core/v1.0.0', 'subtree_hash' => $tree, 'direct_capell_dependencies' => [], 'resolved_minimum_versions' => [], 'reason' => 'baseline',
        'release_type' => 'baseline', 'publication_state' => 'pending', 'tag_sha' => null, 'maturity' => 'stable',
    ]], 'dependency_order' => ['capell-app/core']];
    $runner = new class($root, $process) implements CommandRunner
    {
        /** @var list<list<string>> */
        public array $commands = [];

        public function __construct(private readonly string $root, private readonly ProcessCommandRunner $process) {}

        public function run(array $command, ?string $workingDirectory = null): array
        {
            $this->commands[] = array_values($command);
            if ($command[0] === 'gh') {
                if ($command[1] === 'release') {
                    return ['output' => '{}', 'exitCode' => 0];
                }

                $prefix = 'repos/capell-app/core/git/ref/';
                throw_unless(str_starts_with($command[2], $prefix), RuntimeException::class, 'Unexpected GitHub fixture command');

                return $this->process->run(['git', '--git-dir=' . $this->root . '/split.git', 'rev-parse', '--verify', 'refs/' . substr($command[2], strlen($prefix))]);
            }

            // Only transport is substituted: commit creation, tree validation,
            // leases, main/tag pushes and source-tag publication use real Git.
            $splitPush = in_array('push', $command, true) && in_array('https://github.com/capell-app/core.git', $command, true);
            $command = array_map(fn (string $arg): string => match ($arg) {
                'https://github.com/capell-app/core.git' => $this->root . '/split.git',
                'origin' => $this->root . '/origin.git',
                default => $arg,
            }, $command);
            if ($splitPush) {
                $command[] = '--receive-pack=git -c receive.maxInputSize=65536 receive-pack';
            }

            return $this->process->run($command, $workingDirectory);
        }
    };
    putenv('GH_TOKEN=local-fixture-token');
    new ReleaseEngine($root . '/source', $runner)->publish($plan, $root . '/plan.json');
    $remote = static fn (array $args): string => $run(['git', '--git-dir=' . $root . '/split.git', ...$args]);
    $split = $remote(['rev-parse', 'refs/heads/main']);
    $actualTree = $remote(['rev-parse', 'refs/heads/main^{tree}']);
    $parents = $remote(['show', '-s', '--format=%P', 'refs/heads/main']);
    $count = $remote(['rev-list', '--count', 'refs/heads/main']);
    throw_if($count !== '1' || $parents !== '' || $actualTree !== $tree || $remote(['rev-parse', 'refs/tags/v1.0.0']) !== $split, RuntimeException::class, 'Expected one parentless commit and matching planned/tagged tree');

    $commits = array_values(array_filter($runner->commands, static fn (array $command): bool => in_array('commit-tree', $command, true)));
    throw_if(count($commits) !== 1 || in_array('-p', $commits[0], true) || $run($commits[0], $root . '/source') !== $split, RuntimeException::class, 'Root split must use one deterministic parentless commit-tree command');

    $state = json_decode((string) file_get_contents($root . '/plan.json.state.json'), true, 512, JSON_THROW_ON_ERROR);
    throw_if($state['packages']['capell-app/core']['state'] !== 'published', RuntimeException::class, 'Publication did not complete');

    echo "PASS commits={$count} parents=0 tree={$actualTree} plan_tree={$tree} split={$split} deterministic=yes main+tag=pushed receive_limit=65536\n";
} finally {
    putenv($token === false ? 'GH_TOKEN' : 'GH_TOKEN=' . $token);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($root);
}
