<?php

declare(strict_types=1);

it('keeps every core split pull request forwarding workflow aligned with the core monorepo', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $splitWorkflow = file_get_contents($repositoryRoot . '/.github/workflows/split-monorepo.yml');

    expect($splitWorkflow)->toBeString();

    $matrixMatchCount = preg_match(
        '/\n\s+package:\R(?<packages>(?:\s+- [a-z0-9-]+\R?)+)/',
        $splitWorkflow,
        $matrixMatches,
    );

    expect($matrixMatchCount)->toBe(1);

    if (! isset($matrixMatches['packages'])) {
        throw new RuntimeException('The split workflow package matrix could not be read.');
    }

    $packageMatchCount = preg_match_all(
        '/^\s+- (?<package>[a-z0-9-]+)$/m',
        $matrixMatches['packages'],
        $packageMatches,
    );
    $packageNames = $packageMatches['package'];

    expect($packageMatchCount)->toBe(5)
        ->and($packageNames)->toBe([
            'admin',
            'core',
            'frontend',
            'installer',
            'marketplace',
        ]);

    $expectedWorkflow = null;

    foreach ($packageNames as $packageName) {
        $workflowPath = $repositoryRoot . "/packages/{$packageName}/.github/workflows/forward-pr-to-monorepo.yml";

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
