<?php

declare(strict_types=1);

it('keeps every core split pull request forwarding workflow aligned with the core monorepo', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $packages = json_decode(
        file_get_contents($repositoryRoot . '/config/release-packages.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(array_column($packages, 'path'))->toBe([
        'packages/core',
        'packages/admin',
        'packages/frontend',
        'packages/installer',
        'packages/marketplace',
    ]);

    $expectedWorkflow = null;

    foreach ($packages as $package) {
        $workflowPath = $repositoryRoot . '/' . $package['path'] . '/.github/workflows/forward-pr-to-monorepo.yml';

        expect($workflowPath)->toBeFile();

        $workflow = file_get_contents($workflowPath);

        expect($workflow)
            ->toBeString()
            ->toContain("github.event.repository.name != 'capell'")
            ->toContain('MONOREPO_REPOSITORY: capell-app/capell')
            ->toContain('MONOREPO_BASE: 4.x')
            ->toContain('repository: capell-app/capell')
            ->not->toContain('capell-app/capell-packages');

        if ($expectedWorkflow === null) {
            $expectedWorkflow = $workflow;

            continue;
        }

        expect($workflow)->toBe($expectedWorkflow);
    }
});
