<?php

declare(strict_types=1);

/**
 * Admission into the host-wide heavy-work pool shared by every agent session
 * (`heavy status` shows holders; slots live under HEAVY_SLOT_ROOT, default
 * /tmp/capell-heavy-slots). This file only builds the command line; the queue,
 * load cap and HEAVY_SLOT_OWNER inheritance (nested stages of an admitted run
 * do not wait again) belong to the guard script, which is reused unchanged.
 *
 * Guard lookup: HEAVY_SLOT_GUARD, scripts/guards/heavy-slot.sh in this checkout,
 * then ~/.local/share/heavy/heavy-slot.sh. When none exists the command runs
 * unqueued and a warning says so.
 *
 * Bypass: CAPELL_NO_LOCK=1 or HEAVY_SLOT_ENABLED=0 (diagnostics only).
 */
function capellHeavyGuard(): ?string
{
    $candidates = [
        getenv('HEAVY_SLOT_GUARD'),
        __DIR__ . '/guards/heavy-slot.sh',
        (getenv('HOME') ?: '') . '/.local/share/heavy/heavy-slot.sh',
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @param  list<string>  $command
 * @return list<string>
 */
function capellHeavyCommand(array $command, int $weight, string $label): array
{
    if (getenv('CAPELL_NO_LOCK') === '1' || getenv('HEAVY_SLOT_ENABLED') === '0') {
        return $command;
    }

    $guard = capellHeavyGuard();

    if ($guard === null) {
        fwrite(STDERR, "heavy: no heavy-slot guard found (HEAVY_SLOT_GUARD, scripts/guards/heavy-slot.sh or ~/.local/share/heavy/heavy-slot.sh); running without host-wide admission. Concurrent gates can overload the host.\n");

        return $command;
    }

    return [$guard, '--weight', (string) $weight, $label, ...$command];
}
