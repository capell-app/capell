<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

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
            ->toContain('MONOREPO_BASE: 1.x')
            ->toContain('repository: capell-app/capell')
            ->not->toContain('capell-app/capell-packages');

        if ($expectedWorkflow === null) {
            $expectedWorkflow = $workflow;

            continue;
        }

        expect($workflow)->toBe($expectedWorkflow);
    }
});

it('allows copying a fork head without executing it or persisting checkout credentials', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var list<array{path: string}> $packages */
    $packages = json_decode((string) file_get_contents($root . '/config/release-packages.json'), true, 512, JSON_THROW_ON_ERROR);

    foreach ($packages as $package) {
        /** @var array{jobs: array{forward: array{steps: list<array{name: string, uses?: string, with?: array<string, mixed>, run?: string}>}}} $workflow */
        $workflow = Yaml::parseFile($root . '/' . $package['path'] . '/.github/workflows/forward-pr-to-monorepo.yml');
        $steps = array_column($workflow['jobs']['forward']['steps'], null, 'name');
        $source = $steps['Checkout split PR contents'];
        $monorepo = $steps['Checkout Capell monorepo'];
        $publication = $steps['Create or update monorepo PR'];

        throw_unless(isset($source['uses'], $source['with'], $monorepo['with'], $publication['run']), RuntimeException::class, 'The forwarding workflow must define both checkouts and the publication script.');

        expect($source['uses'])->toBe('actions/checkout@11d5960a326750d5838078e36cf38b85af677262')
            ->and($source['with']['repository'])->toBe('${{ github.event.pull_request.head.repo.full_name }}')
            ->and($source['with']['ref'])->toBe('${{ github.event.pull_request.head.sha }}')
            ->and($source['with']['persist-credentials'])->toBeFalse()
            ->and($source['with']['allow-unsafe-pr-checkout'] ?? false)->toBeTrue()
            ->and($source['with'])->not->toHaveKey('token')
            ->and($monorepo['with'])->not->toHaveKey('allow-unsafe-pr-checkout');

        $forward = $publication['run'];
        expect($forward)->toContain('cd monorepo', 'rsync -a --delete --exclude', '"../split/"')
            ->not->toMatch('/(?:composer|npm|yarn|pnpm)\s+(?:install|run|test)|(?:cd|source)\s+[^\n]*split/');
    }
});
