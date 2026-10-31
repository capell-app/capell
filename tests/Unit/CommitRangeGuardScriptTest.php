<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * A throwaway Git repository carrying this checkout's real guard configuration,
 * so the test exercises the same hooks, stages and excludes CI uses.
 *
 * @return array{root: string, git: Closure(string...): Process}
 */
function commitRangeFixture(): array
{
    $root = sys_get_temp_dir() . '/capell-commit-range-' . bin2hex(random_bytes(4));
    mkdir($root . '/scripts/guards', recursive: true);
    $repository = dirname(__DIR__, 2);

    foreach (['.pre-commit-config.yaml', 'scripts/check-debugging.py', 'scripts/guards/check-commit-range.sh'] as $file) {
        copy($repository . '/' . $file, $root . '/' . $file);
    }

    chmod($root . '/scripts/guards/check-commit-range.sh', 0o755);

    $git = static function (string ...$arguments) use ($root): Process {
        $process = new Process(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', '-c', 'core.hooksPath=/dev/null', ...$arguments], $root);
        $process->mustRun();

        return $process;
    };

    $git('init', '--quiet', '--initial-branch=main');
    $git('add', '.');
    $git('commit', '--quiet', '--no-verify', '-m', 'base');

    return ['root' => $root, 'git' => $git];
}

/** @param array{root: string, git: Closure(string...): Process} $fixture */
function commitRangeCheck(array $fixture, string $base): Process
{
    $process = new Process(['scripts/guards/check-commit-range.sh', $base, 'HEAD'], $fixture['root'], [
        'PRE_COMMIT' => false,
        'PRE_COMMIT_HOME' => getenv('PRE_COMMIT_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/.cache/pre-commit'),
    ]);
    $process->setTimeout(300);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    if (new Process(['pre-commit', '--version'])->run() !== 0) {
        $this->markTestSkipped('pre-commit is not installed; CI runs this guard through pipx.');
    }
});

it('finds a debugging call that was committed with --no-verify', function (string $file, string $contents, string $expected): void {
    $fixture = commitRangeFixture();
    $base = trim($fixture['git']('rev-parse', 'HEAD')->getOutput());
    @mkdir(dirname($fixture['root'] . '/' . $file), recursive: true);
    file_put_contents($fixture['root'] . '/' . $file, $contents);
    $fixture['git']('add', $file);
    $fixture['git']('commit', '--quiet', '--no-verify', '-m', 'bypassed');

    $process = commitRangeCheck($fixture, $base);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput() . $process->getErrorOutput())->toContain($expected);
})->with([
    // Markers are assembled so the guards under test do not flag this file.
    'CLI exit in a new script' => ['scripts/new-tool.php', "<?php\n" . 'ex' . "it(2);\n", 'scripts/new-tool.php:2:' . 'ex' . 'it(2);'],
    'dd in package code' => ['packages/core/src/Probe.php', "<?php\n" . 'd' . "d(\$value);\n", 'packages/core/src/Probe.php:2:' . 'd' . 'd($value);'],
    'do-not-commit marker' => ['docs/note.md', 'Draft. ' . 'DO NOT ' . "COMMIT\n", 'docs/note.md'],
]);

it('passes a clean range and honours the configured exemptions', function (): void {
    $fixture = commitRangeFixture();
    $base = trim($fixture['git']('rev-parse', 'HEAD')->getOutput());
    file_put_contents($fixture['root'] . '/scripts/with-lock.php', "<?php\n" . 'ex' . "it(0);\n");
    file_put_contents($fixture['root'] . '/README.md', "Clean change.\n");
    $fixture['git']('add', 'scripts/with-lock.php', 'README.md');
    $fixture['git']('commit', '--quiet', '--no-verify', '-m', 'clean');

    $process = commitRangeCheck($fixture, $base);

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
});

it('names a base the checkout does not have instead of passing silently', function (): void {
    $fixture = commitRangeFixture();

    $process = commitRangeCheck($fixture, str_repeat('0', 40));

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('is not a commit in this checkout');
});
