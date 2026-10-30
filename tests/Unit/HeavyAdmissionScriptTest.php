<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

/**
 * A fake guard that records its arguments and then runs the command, standing in
 * for the host-wide heavy-slot guard.
 *
 * @return array{guard: string, log: string, composer: string}
 */
function heavyAdmissionFixture(): array
{
    $directory = sys_get_temp_dir() . '/capell-heavy-test-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $log = $directory . '/guard.log';
    $guard = $directory . '/guard.sh';
    file_put_contents($guard, "#!/bin/sh\necho \"\$1 \$2 \$3\" >> '{$log}'\nshift 3\nexec \"\$@\"\n");
    chmod($guard, 0o755);
    $composer = $directory . '/composer.sh';
    file_put_contents($composer, "#!/bin/sh\nexit 0\n");
    chmod($composer, 0o755);

    return ['guard' => $guard, 'log' => $log, 'composer' => $composer];
}

function heavyAdmissionRun(array $arguments, array $env): Process
{
    $process = new Process([PHP_BINARY, ...$arguments], dirname(__DIR__, 2), array_merge([
        'CAPELL_PREFLIGHT_ADMITTED' => false,
        'CAPELL_NO_LOCK' => false,
        'HEAVY_SLOT_ENABLED' => false,
        'CAPELL_HEAVY_WEIGHT' => false,
        'HEAVY_SLOT_OWNER' => false,
    ], $env));
    $process->run();

    return $process;
}

it('admits a whole-tree preflight once at weight 3 and does not re-wrap nested stages', function (): void {
    $fixture = heavyAdmissionFixture();
    $process = heavyAdmissionRun(['scripts/run-preflight.php', 'composer-majors'], [
        'HEAVY_SLOT_GUARD' => $fixture['guard'],
        'COMPOSER_BINARY' => $fixture['composer'],
    ]);

    expect($process->getExitCode())->toBe(0)
        ->and(trim((string) file_get_contents($fixture['log'])))->toBe('--weight 3 composer-preflight');
});

it('does not queue an already admitted or explicitly bypassed preflight', function (array $env): void {
    $fixture = heavyAdmissionFixture();
    $process = heavyAdmissionRun(['scripts/run-preflight.php', 'composer-majors'], $env + [
        'HEAVY_SLOT_GUARD' => $fixture['guard'],
        'COMPOSER_BINARY' => $fixture['composer'],
    ]);

    expect($process->getExitCode())->toBe(0)
        ->and(file_exists($fixture['log']))->toBeFalse();
})->with([
    'admitted' => [['CAPELL_PREFLIGHT_ADMITTED' => '1']],
    'CAPELL_NO_LOCK' => [['CAPELL_NO_LOCK' => '1']],
]);

it('warns and still runs when no guard exists', function (): void {
    $fixture = heavyAdmissionFixture();
    $process = heavyAdmissionRun(['scripts/run-preflight.php', 'composer-majors'], [
        'HEAVY_SLOT_GUARD' => '/nonexistent/guard.sh',
        'HOME' => '/nonexistent',
        'COMPOSER_BINARY' => $fixture['composer'],
    ]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('running without host-wide admission');
})->skip(fn (): bool => is_file(dirname(__DIR__, 2) . '/scripts/guards/heavy-slot.sh'), 'repository ships its own guard');

it('weights stage commands behind with-lock: 2 for static analysis, 3 otherwise, overridable', function (string $name, array $env, string $expected): void {
    $fixture = heavyAdmissionFixture();
    $process = heavyAdmissionRun(['scripts/with-lock.php', $name, '--', '/bin/sh', '-c', 'exit 0'], $env + [
        'HEAVY_SLOT_GUARD' => $fixture['guard'],
        'CAPELL_NO_RELEASE_LOCK' => '1',
    ]);

    expect($process->getExitCode())->toBe(0)
        ->and(trim((string) file_get_contents($fixture['log'])))->toBe($expected);
})->with([
    'static' => ['capell-static-heavy-test', ['CAPELL_NO_LOCK' => false], '--weight 2 capell-static-heavy-test'],
    'release lease' => ['capell-release-verification', [], '--weight 3 capell-release-verification'],
    'focused override' => ['capell-release-verification', ['CAPELL_HEAVY_WEIGHT' => '1'], '--weight 1 capell-release-verification'],
]);

it('queues a second preflight behind a holder in the real shared pool', function (): void {
    $guard = (getenv('HOME') ?: '') . '/.local/share/heavy/heavy-slot.sh';
    $root = sys_get_temp_dir() . '/capell-heavy-pool-' . bin2hex(random_bytes(4));
    $fixture = heavyAdmissionFixture();
    $sleeper = dirname($fixture['composer']) . '/sleeper.sh';
    file_put_contents($sleeper, "#!/bin/sh\ntouch '{$root}.running'\nsleep 6\n");
    chmod($sleeper, 0o755);
    $env = ['HEAVY_SLOT_GUARD' => $guard, 'HEAVY_SLOT_ROOT' => $root, 'HEAVY_SLOTS' => '3', 'HEAVY_MAX_LOAD' => '100000', 'CAPELL_PREFLIGHT_ADMITTED' => false, 'HEAVY_SLOT_OWNER' => false];

    $holder = new Process([PHP_BINARY, 'scripts/run-preflight.php', 'composer-majors'], dirname(__DIR__, 2), $env + ['COMPOSER_BINARY' => $sleeper]);
    $holder->start();

    $deadline = microtime(true) + 20;
    while (! file_exists($root . '.running') && microtime(true) < $deadline) {
        Sleep::usleep(100_000);
    }

    expect(file_exists($root . '.running'))->toBeTrue();

    $started = microtime(true);
    $waiter = new Process([PHP_BINARY, 'scripts/run-preflight.php', 'composer-majors'], dirname(__DIR__, 2), $env + ['COMPOSER_BINARY' => $fixture['composer'], 'HEAVY_MAX_WAIT_SECONDS' => '60']);
    $waiter->run();

    $holder->wait();

    expect($waiter->getExitCode())->toBe(0)
        ->and(microtime(true) - $started)->toBeGreaterThan(2.0)
        ->and($holder->getExitCode())->toBe(0);
})->skip(fn (): bool => ! is_executable((getenv('HOME') ?: '') . '/.local/share/heavy/heavy-slot.sh'), 'host heavy-slot guard not installed');
