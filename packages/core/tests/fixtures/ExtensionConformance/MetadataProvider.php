<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Illuminate\Support\ServiceProvider;

final class MetadataProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ConformanceRecorder::class)->record('metadata');
        $this->app->make(ExtensionContributionReceiptRegistry::class)->recordContribution(
            ExtensionContributionType::Model,
            'fixture.metadata',
            self::class,
            self::class,
            'metadata',
        );
    }
}
