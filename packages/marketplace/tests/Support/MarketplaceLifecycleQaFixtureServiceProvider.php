<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Illuminate\Support\ServiceProvider;
use Override;

final class MarketplaceLifecycleQaFixtureServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(MarketplaceLifecycleQaFixture::class);
        $this->app->singleton(MarketplaceComposerRunner::class, fn (): MarketplaceLifecycleQaFixture => resolve(MarketplaceLifecycleQaFixture::class));
        $this->app->singleton(MarketplaceInstalledPackageVersionResolver::class, fn (): MarketplaceLifecycleQaFixture => resolve(MarketplaceLifecycleQaFixture::class));
        $this->app->singleton(
            'test.marketplace.lifecycle-qa-publisher',
            fn (): MarketplaceLifecycleQaFixture => resolve(MarketplaceLifecycleQaFixture::class),
        );
        $this->app->tag(['test.marketplace.lifecycle-qa-publisher'], MarketplaceComposerChangePublisher::TAG);
    }
}
