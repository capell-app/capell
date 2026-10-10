<?php

declare(strict_types=1);

use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Tests\Support\TaggedSiteSpecApplier;

it('discovers package-owned SiteSpec appliers through the stable container tag', function (): void {
    app()->bind(TaggedSiteSpecApplier::class);
    app()->tag([TaggedSiteSpecApplier::class], SiteSpecApplier::TAG);

    $registry = new SiteSpecApplierRegistry(app());

    expect(SiteSpecApplier::TAG)->toBe('capell.site-spec.applier')
        ->and($registry->has('navigation'))->toBeTrue()
        ->and($registry->keys())->toBe(['navigation']);
});

it('rejects duplicate SiteSpec applier keys', function (): void {
    $registry = new SiteSpecApplierRegistry(app());
    $registry->register(new TaggedSiteSpecApplier);

    expect(function () use ($registry): void {
        $registry->register(new TaggedSiteSpecApplier);
    })
        ->toThrow(LogicException::class, 'already registered');
});

it('discards operation registrations between scoped lifecycles', function (): void {
    $firstRegistry = resolve(SiteSpecApplierRegistry::class);
    $firstRegistry->register(new TaggedSiteSpecApplier);

    expect(resolve(SiteSpecApplierRegistry::class))->toBe($firstRegistry)
        ->and($firstRegistry->has('navigation'))->toBeTrue();

    app()->forgetScopedInstances();

    $secondRegistry = resolve(SiteSpecApplierRegistry::class);

    expect($secondRegistry)->not->toBe($firstRegistry)
        ->and($secondRegistry->has('navigation'))->toBeFalse();
});
