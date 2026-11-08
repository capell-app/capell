<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance\RuntimeReadiness;

use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Capell\Core\Tests\Support\Fixtures\Autoload\TestPageContentGraphExtractor;
use Illuminate\Support\ServiceProvider;
use Override;

final class HarnessRuntimeProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('test/harness-runtime-readiness', 'runtime');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->make(ExtensionContributionReceiptRegistry::class)->recordFromContext(
            ExtensionContributionReceiptType::ContentGraph,
            'runtime',
            self::class,
        );
        $this->app->make(ContentGraphRegistry::class)->register(TestPageContentGraphExtractor::class);
    }
}
