<?php

declare(strict_types=1);

it('keeps every marketplace readiness remediation anchor documented', function (): void {
    $root = dirname(__DIR__, 2);
    $hosting = (string) file_get_contents($root . '/docs/operations/marketplace-hosting.md');

    foreach ([
        'process-execution',
        'php-binary',
        'composer-binary',
        'release-root-writable',
        'queue-worker',
        'shared-cache',
        'timeout-chain',
        'deploy-publisher',
    ] as $anchor) {
        expect($hosting)->toContain('<a id="' . $anchor . '"></a>');
    }
});

it('does not document the removed marketplace domain verification flow', function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        'docs/operations/debugging-marketplace.md',
        'docs/operations/troubleshooting.md',
        'docs/operations/index.md',
        'docs/packages/extension-troubleshooting.md',
        'docs/reference/architecture-diagrams.md',
        'docs/development/screenshot-state-guide.md',
        'packages/core/README.md',
    ] as $path) {
        $contents = strtolower((string) file_get_contents($root . '/' . $path));

        expect($contents)
            ->not->toContain('domain verification')
            ->not->toContain('marketplace_registration_sessions')
            ->not->toContain('.well-known/capell/marketplace');
    }
});

it('keeps the consumer boost skill free of internal repository language', function (): void {
    $root = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($root . '/packages/core/resources/boost/skills/capell/SKILL.md');

    expect($skill)
        ->not->toContain('capell-4')
        ->not->toContain('capell-packages-4')
        ->not->toContain('monorepo')
        ->not->toContain('/Users/');
});
