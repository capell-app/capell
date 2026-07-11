<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\ResolveInstalledComposerVersionsAction;

it('returns the pretty version for every capell-app/* composer package', function (): void {
    $versions = ResolveInstalledComposerVersionsAction::run();

    expect($versions)->toBeArray()
        ->toHaveKey('capell-app/capell')
        ->and($versions['capell-app/capell'])->toBeString()->not->toBe('');
});

it('filters to capell-app/ prefix by default', function (): void {
    $versions = ResolveInstalledComposerVersionsAction::run();

    foreach (array_keys($versions) as $package) {
        expect(str_starts_with((string) $package, 'capell-app/'))->toBeTrue('Unexpected: ' . $package);
    }
});

it('respects a custom prefix', function (): void {
    $versions = ResolveInstalledComposerVersionsAction::run(prefixes: ['spatie/']);

    foreach (array_keys($versions) as $package) {
        expect(str_starts_with((string) $package, 'spatie/'))->toBeTrue();
    }
});
