<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Illuminate\Support\ServiceProvider;

final class AdminProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ConformanceRecorder::class)->record('admin');
        $this->app->make(ExtensionContributionReceiptRegistry::class)->recordContribution(
            ExtensionContributionType::AdminPage,
            'fixture.admin',
            self::class,
            self::class,
            'admin',
        );
    }
}
