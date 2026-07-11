<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Frontend\Support\Cache\LazyLoadedSiteContext;

it('defers full site loading until the site is requested', function (): void {
    $site = Site::factory()->createOne();
    $context = new LazyLoadedSiteContext($site->withoutRelations(), $site->language);

    expect($context->isFullyLoaded())->toBeFalse()
        ->and($context->language()->is($site->language))->toBeTrue();

    $loadedSite = $context->site();

    expect($loadedSite)->toBeInstanceOf(Site::class)
        ->and($loadedSite->is($site))->toBeTrue()
        ->and($context->isFullyLoaded())->toBeTrue();
});

it('preloads the site once for later access', function (): void {
    $site = Site::factory()->createOne();
    $context = new LazyLoadedSiteContext($site->withoutRelations(), $site->language);

    $context->preloadSite();

    $loadedSite = $context->site();

    expect($context->isFullyLoaded())->toBeTrue()
        ->and($loadedSite->is($site))->toBeTrue();
});
