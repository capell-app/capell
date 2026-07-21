<?php

declare(strict_types=1);

/**
 * Cross-checkout advisory lock for expensive verification stages.
 *
 * Several Capell repos are worked on concurrently from multiple git worktrees
 * (see `git worktree list`). PHPStan and the Pest suite each saturate the host
 * on their own; two sessions running them at once do not merely halve each
 * other's throughput, they push individual workers past their timeouts so the
 * run is killed having analyzed nothing. Serializing is strictly faster than
 * contending.
 *
 * `flock(1)` is unavailable on macOS hosts, and these commands run both on the
 * host and inside the Linux containers, so the lock is implemented with PHP's
 * flock() -- present everywhere PHP is.
 *
 * Usage:
 *   php scripts/with-lock.php <lock-name> -- <command> [args...]
 *
 * The lock is advisory and scoped by name, shared across every checkout on the
 * machine. Set CAPELL_NO_LOCK=1 to bypass (CI, or a deliberately parallel run).
 *
 * Exit code is the wrapped command's exit code, so callers see through it.
 */
$argv = $_SERVER['argv'];
array_shift($argv);

$separator = array_search('--', $argv, true);

if ($separator === false || $separator === 0) {
    fwrite(STDERR, "usage: php scripts/with-lock.php <lock-name> -- <command> [args...]\n");
    exit(2);
}

$name = $argv[0];
$command = array_slice($argv, $separator + 1);

if ($command === []) {
    fwrite(STDERR, "with-lock: no command given\n");
    exit(2);
}

$runCommand = static function (array $command): int {
    // The stages this wraps are interactive-ish (progress bars, coloured
    // output); passthru keeps them attached to the real terminal.
    $quoted = implode(' ', array_map(escapeshellarg(...), $command));

    passthru($quoted, $status);

    return $status;
};

if (getenv('CAPELL_NO_LOCK') === '1') {
    exit($runCommand($command));
}

$lockDir = sys_get_temp_dir() . '/capell-locks';

if (! is_dir($lockDir) && ! @mkdir($lockDir, 0o777, true) && ! is_dir($lockDir)) {
    // A lock we cannot take is not a reason to refuse to work; degrade to
    // unserialized (the previous behaviour) rather than blocking the run.
    fwrite(STDERR, "with-lock: cannot create {$lockDir}, running without a lock\n");

    exit($runCommand($command));
}

$lockFile = $lockDir . '/' . preg_replace('/[^a-z0-9._-]/i', '-', $name) . '.lock';
// 'c+' rather than 'c': we read the holder's identity back out of the file to
// report who we are waiting for, and 'c' opens write-only.
$handle = @fopen($lockFile, 'c+');

if ($handle === false) {
    fwrite(STDERR, "with-lock: cannot open {$lockFile}, running without a lock\n");

    exit($runCommand($command));
}

if (! flock($handle, LOCK_EX | LOCK_NB)) {
    // Say who we are waiting for. A silent stall here is indistinguishable
    // from a hang, which is exactly the confusion this script exists to end.
    $holder = trim((string) fread($handle, 4096));

    fwrite(STDERR, sprintf(
        "⏳ waiting for the '%s' lock%s\n",
        $name,
        $holder !== '' ? " (held by {$holder})" : '',
    ));

    flock($handle, LOCK_EX);
}

ftruncate($handle, 0);
rewind($handle);
fwrite($handle, sprintf('pid %d in %s', getmypid(), getcwd()));
fflush($handle);

try {
    $status = $runCommand($command);
} finally {
    ftruncate($handle, 0);
    flock($handle, LOCK_UN);
    fclose($handle);
}

exit($status);
