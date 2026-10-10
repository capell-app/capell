<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class WrongContextProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $receipts = $this->app->make(ExtensionContributionReceiptRegistry::class);

        $receipts->withContext(
            ExtensionContributionReceiptContext::forPackage(
                'capell-tests/conformance-failure',
                'admin',
                self::class,
            ),
            static function () use ($receipts): void {
                $receipts->recordContribution(
                    ExtensionContributionType::OutboundEvent,
                    'fixture.wrong-context',
                    ConformanceOutboundEvent::class,
                    self::class,
                    'admin',
                );
            },
        );
    }
}
