<?php

declare(strict_types=1);

/**
 * Admits a multi-step local run into the host-wide heavy pool once, before its
 * first step, so preparation never starts on a busy host and later steps never
 * queue again behind someone else halfway through.
 *
 * Usage:
 *   php scripts/with-admission.php <label> -- <command> [args...]
 *
 * The run takes weight 3 (the whole host). Nested with-lock.php stages see
 * CAPELL_HEAVY_ADMITTED=1 and run inside this slot. Already-admitted runs,
 * CAPELL_NO_LOCK=1, HEAVY_SLOT_ENABLED=0 and hosts without a guard (CI) behave
 * as scripts/heavy-admission.php describes. Exit code is the command's.
 */
$arguments = $argv;
array_shift($arguments);

$separator = array_search('--', $arguments, true);

if ($separator !== 1) {
    fwrite(STDERR, "usage: php scripts/with-admission.php <label> -- <command> [args...]\n");
    exit(2);
}

$label = $arguments[0];
$command = array_slice($arguments, 2);

if ($label === '' || $command === []) {
    fwrite(STDERR, "usage: php scripts/with-admission.php <label> -- <command> [args...]\n");
    exit(2);
}

require_once __DIR__ . '/heavy-admission.php';

$admitted = capellHeavyCommand($command, 3, $label);

if ($admitted !== $command) {
    putenv('CAPELL_HEAVY_ADMITTED=1');
}

$process = proc_open($admitted, [STDIN, STDOUT, STDERR], $pipes);
exit(is_resource($process) ? proc_close($process) : 127);
