<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\WriteInstallHandoffAction;
use Capell\Core\Data\Install\InstallHandoffData;

it('writes machine-readable install handoff evidence atomically', function (): void {
    $directory = sys_get_temp_dir() . '/capell-install-handoff-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $path = $directory . '/handoff.json';
    $handoff = new InstallHandoffData(
        schemaVersion: 1,
        status: 'completed',
        selectedPackages: ['capell-app/core'],
        outcomes: ['migrations' => 'completed', 'setup' => 'completed', 'doctor' => 'passed'],
        urls: ['admin' => null, 'public' => 'https://example.test/'],
        firstPage: ['status' => 'present_unverified'],
        warnings: [],
        nextAction: [
            'label' => 'Create and verify your first editable public page',
            'url' => 'https://docs.capell.app/getting-started/create-your-first-page/',
        ],
        publicImpact: [
            'summary' => 'Public rendering remains application-owned.',
            'accountConnection' => 'not_required',
            'telemetrySubmission' => 'not_performed',
        ],
    );

    try {
        WriteInstallHandoffAction::run($handoff, $path);

        expect($path)->toBeFile()
            ->and(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->toBe($handoff->toArray())
            ->and(glob($path . '.tmp-*') ?: [])->toBe([]);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }

        rmdir($directory);
    }
});

it('refuses to write into a missing parent directory', function (): void {
    $handoff = new InstallHandoffData(
        schemaVersion: 1,
        status: 'incomplete',
        selectedPackages: [],
        outcomes: ['migrations' => 'incomplete', 'setup' => 'incomplete', 'doctor' => 'unknown'],
        urls: ['admin' => null, 'public' => 'https://example.test/'],
        firstPage: ['status' => 'unavailable'],
        warnings: [],
        nextAction: ['label' => 'Review installation', 'url' => 'https://docs.capell.app/getting-started/install/'],
        publicImpact: [
            'summary' => 'No public impact has been verified.',
            'accountConnection' => 'not_required',
            'telemetrySubmission' => 'not_performed',
        ],
    );

    expect(fn (): string => WriteInstallHandoffAction::run(
        $handoff,
        sys_get_temp_dir() . '/missing-capell-handoff-' . bin2hex(random_bytes(6)) . '/handoff.json',
    ))->toThrow(RuntimeException::class, 'parent directory');
});
