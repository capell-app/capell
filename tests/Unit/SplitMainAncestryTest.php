<?php

declare(strict_types=1);

use Capell\Release\ProcessCommandRunner;

require_once dirname(__DIR__, 2) . '/scripts/release/ReleaseEngine.php';

it('protects split main publication with real Git ancestry and leases', function (string $scenario): void {
    $result = (new ProcessCommandRunner)->run([
        PHP_BINARY,
        dirname(__DIR__) . '/fixtures/split-main-ancestry.php',
        $scenario,
    ]);

    expect($result['exitCode'])->toBe(0, $result['error'] ?? '')
        ->and($result['output'])->toContain('PASS ' . $scenario);
})->with(['fast-forward', 'divergence', 'unknown-ancestry', 'unfetchable-lease', 'empty', 'late-race']);
