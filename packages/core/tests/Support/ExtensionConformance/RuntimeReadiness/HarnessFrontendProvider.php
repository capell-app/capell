<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance\RuntimeReadiness;

use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Override;

final class HarnessFrontendProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('test/harness-runtime-readiness', 'frontend');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->make(ExtensionContributionReceiptRegistry::class)->recordFromContext(
            ExtensionContributionReceiptType::ContentGraph,
            'frontend',
            self::class,
        );
        $this->app->make(Router::class)->get('/test-harness-readiness-frontend', static fn (): string => 'frontend ready')->name('test.harness-readiness.frontend');
    }
}
