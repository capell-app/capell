<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Illuminate\Support\ServiceProvider;

final class AuthProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ConformanceRecorder::class)->record('auth');
        $this->app->make(ExtensionContributionReceiptRegistry::class)->recordContribution(
            ExtensionContributionType::Permission,
            'fixture.auth',
            self::class,
            self::class,
            'auth',
        );
    }
}
