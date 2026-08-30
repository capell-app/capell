<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Illuminate\Support\ServiceProvider;

final class FrontendProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ConformanceRecorder::class)->record('frontend');
        $this->app->make(ExtensionContributionReceiptRegistry::class)->recordContribution(
            ExtensionContributionType::RenderHook,
            'fixture.frontend',
            self::class,
            self::class,
            'frontend',
        );
    }
}
