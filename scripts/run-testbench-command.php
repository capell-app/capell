<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Run a Testbench command with the in-memory cache store required by the
 * generated workbench. Keeping the environment in Process avoids relying on
 * POSIX inline environment assignment, which Composer cannot execute on
 * Windows.
 *
 * @var list<string> $argv
 */
$arguments = array_values(array_filter(
    array_slice($argv, 1),
    is_string(...),
));

if ($arguments === []) {
    throw new InvalidArgumentException('A Testbench command is required.');
}

$root = dirname(__DIR__);
$command = $arguments[0];
$environment = ['CACHE_STORE' => 'array'];

// A Pest worker exports its resolved runtime-role cache paths through $_ENV.
// They belong to that application; inheriting them makes the child treat them
// as immutable overrides and read or overwrite the calling worker's caches.
foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
    $environment[$key] = false;
}

if (in_array($command, ['list', 'optimize'], true)) {
    $runtimeRoleBootstrap = new Process(
        [PHP_BINARY, $root . '/scripts/configure-testbench-runtime-role.php'],
        $root,
        $environment,
    );
    $runtimeRoleBootstrap->setTimeout(null);

    $runtimeRoleBootstrapExitCode = $runtimeRoleBootstrap->run();

    if ($runtimeRoleBootstrapExitCode !== 0) {
        throw new RuntimeException(
            sprintf(
                "Unable to configure the Testbench runtime role bootstrap.\nCommand: %s\nWorking directory: %s\nExit code: %d\n--- stdout ---\n%s\n--- stderr ---\n%s",
                $runtimeRoleBootstrap->getCommandLine(),
                $root,
                $runtimeRoleBootstrapExitCode,
                $runtimeRoleBootstrap->getOutput(),
                $runtimeRoleBootstrap->getErrorOutput(),
            ),
            $runtimeRoleBootstrapExitCode,
        );
    }
}

$process = new Process(
    [PHP_BINARY, $root . '/vendor/bin/testbench', ...$arguments],
    $root,
    $environment,
);
$process->setTimeout(null);

$exitCode = $process->run(static function (string $type, string $buffer): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
});

if ($exitCode !== 0) {
    throw new RuntimeException(sprintf(
        "Testbench command failed with exit code %d.\nCommand: %s\nWorking directory: %s",
        $exitCode,
        $process->getCommandLine(),
        $root,
    ), $exitCode);
}
