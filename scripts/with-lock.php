<?php

declare(strict_types=1);

/**
 * Cross-checkout advisory lock for expensive verification stages.
 *
 * Several Capell repos are worked on concurrently from multiple git worktrees
 * (see `git worktree list`). PHPStan and the Pest suite each saturate the host
 * on their own; two sessions running them at once do not merely halve each
 * other's throughput, they push individual workers past their timeouts so the
 * run is killed having analyzed nothing. Serialising is strictly faster than
 * contending.
 *
 * `flock(1)` is unavailable on macOS hosts, and these commands run both on the
 * host and inside the Linux containers, so the lock is implemented with PHP's
 * flock() -- present everywhere PHP is.
 *
 * Usage:
 *   php scripts/with-lock.php <lock-name> -- <command> [args...]
 *
 * Named stage locks share one temporary filesystem. CAPELL_NO_LOCK=1 bypasses
 * those stage locks. The capell-release-verification name instead takes a shared
 * lease on the Python release gate; CAPELL_NO_RELEASE_LOCK=1 explicitly bypasses
 * that lease for a focused diagnostic. Only a matching live release owner can
 * run nested lanes while the gate is exclusively held.
 *
 * Exit code is the wrapped command's exit code, so callers see through it.
 */
// Use PHP's CLI vector: this wrapper runs before Composer installs an autoloader.
$arguments = $argv;
array_shift($arguments);

$separator = array_search('--', $arguments, true);

if ($separator === false || $separator === 0) {
    fwrite(STDERR, "usage: php scripts/with-lock.php <lock-name> -- <command> [args...]\n");
    exit(2);
}

$name = $arguments[0];
$command = array_slice($arguments, $separator + 1);

if ($command === []) {
    fwrite(STDERR, "with-lock: no command given\n");
    exit(2);
}

$runCommand = static function (array $command): int {
    if ($command[0] === 'php') {
        $command[0] = PHP_BINARY;
    }

    // The stages this wraps are interactive-ish (progress bars, coloured
    // output); passthru keeps them attached to the real terminal.
    if (preg_match('/\A[A-Za-z_]\w*=.*/', $command[0]) === 1) {
        array_unshift($command, 'env');
    }

    $quoted = implode(' ', array_map(escapeshellarg(...), $command));

    passthru($quoted, $status);

    return $status;
};

$releaseGate = $name === 'capell-release-verification';

if (($releaseGate && getenv('CAPELL_NO_RELEASE_LOCK') === '1') || (! $releaseGate && getenv('CAPELL_NO_LOCK') === '1')) {
    exit($runCommand($command));
}

if ($releaseGate) {
    $configured = getenv('CAPELL_RELEASE_VERIFICATION_LOCK_PATH');
    $lockFile = is_string($configured) && $configured !== ''
        ? $configured
        : sys_get_temp_dir() . '/capell-release-verification.lock';

    if (! str_starts_with($lockFile, '/')) {
        throw new RuntimeException('Release verification lock requires an absolute path.');
    }

    $handle = fopen($lockFile, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Cannot open release verification lock; refusing to run without it.');
    }

    try {
        if (! flock($handle, LOCK_SH | LOCK_NB)) {
            // Only the current exclusive owner can launch nested release lanes.
            // A stage-lock opt-out or a stale/different token cannot bypass it.
            $record = json_decode((string) stream_get_contents($handle), true);
            $owner = getenv('CAPELL_RELEASE_VERIFICATION_OWNER');
            $nested = is_string($owner) && preg_match('/\A[a-f0-9]{32}\z/', $owner) === 1
                && is_array($record) && ($record['schema'] ?? null) === 1
                && is_int($record['pid'] ?? null) && $record['pid'] > 1
                && function_exists('posix_kill') && posix_kill($record['pid'], 0)
                && is_string($record['owner'] ?? null) && hash_equals($record['owner'], $owner);

            if (! $nested) {
                fwrite(STDERR, "Waiting for full release verification to release the host gate.\n");

                if (! flock($handle, LOCK_SH)) {
                    throw new RuntimeException('Cannot acquire release verification lock.');
                }
            }
        }

        $status = $runCommand($command);
    } finally {
        // Shared holders must never overwrite the exclusive owner's record.
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    exit($status);
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
        $holder !== '' ? sprintf(' (held by %s)', $holder) : '',
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
