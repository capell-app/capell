<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Illuminate\Support\ServiceProvider;

final class RuntimeProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ConformanceRecorder::class)->record('runtime');
        $this->app->make(ExtensionContributionReceiptRegistry::class)->recordContribution(
            ExtensionContributionType::Model,
            'fixture.runtime',
            self::class,
            self::class,
            'runtime',
        );
    }
}
