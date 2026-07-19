<?php

declare(strict_types=1);

use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;

final class TaggedSiteSpecApplier implements SiteSpecApplier
{
    public function key(): string
    {
        return 'navigation';
    }

    /** @param array<string, Page> $pagesBySlug */
    public function apply(CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void {}
}

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
