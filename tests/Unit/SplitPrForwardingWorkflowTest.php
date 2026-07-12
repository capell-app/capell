<?php

declare(strict_types=1);

it('keeps every core split pull request forwarding workflow aligned with the core monorepo', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $packageNames = json_decode(
        file_get_contents($repositoryRoot . '/config/release-packages.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($packageNames)->toBe([
        'admin',
        'core',
        'frontend',
        'installer',
        'marketplace',
    ]);

    $expectedWorkflow = null;

    foreach ($packageNames as $packageName) {
        $workflowPath = $repositoryRoot . sprintf('/packages/%s/.github/workflows/forward-pr-to-monorepo.yml', $packageName);

        expect($workflowPath)->toBeFile();

        $workflow = file_get_contents($workflowPath);

        expect($workflow)
            ->toBeString()
            ->toContain("github.event.repository.name != 'capell'")
            ->toContain('MONOREPO_REPOSITORY: capell-app/capell')
            ->toContain('MONOREPO_BASE: main')
            ->toContain('repository: capell-app/capell')
            ->not->toContain('capell-app/capell-packages');

        if ($expectedWorkflow === null) {
            $expectedWorkflow = $workflow;

            continue;
        }

        expect($workflow)->toBe($expectedWorkflow);
    }
});
