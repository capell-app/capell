<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class InstallProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->make(ConformanceRecorder::class)->record('install');
        $this->app->make(ExtensionContributionReceiptRegistry::class)->recordContribution(
            ExtensionContributionReceiptType::InstallPatch,
            'fixture.install',
            self::class,
            self::class,
            'install',
        );
    }
}
