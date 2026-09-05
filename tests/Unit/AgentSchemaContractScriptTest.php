<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps the database-free agent schema gate green for the repository artefacts', function (): void {
    $process = new Process([PHP_BINARY, 'scripts/check-agent-schema-contract.php'], dirname(__DIR__, 2));
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('runtime verification remains database-backed');
});

it('fails the static agent schema gate when the artefacts are absent', function (): void {
    $root = sys_get_temp_dir() . '/capell-agent-schema-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    try {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-agent-schema-contract.php'], null, [
            'CAPELL_AGENT_SCHEMA_ROOT' => $root,
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('resources/agent-schema/schemaorg-terms.json is missing');
    } finally {
        rmdir($root);
    }
});
