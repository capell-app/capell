<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of Marketplace install this host can actually perform.
 *
 * ManualOnly is a supported mode rather than an error: the catalogue stays fully
 * browsable and the primary call to action becomes the manual install
 * instructions. AutomatedViaDeployPublisher is likewise not a downgrade — the
 * host automates the change through its deployment pipeline.
 */
enum MarketplaceInstallCapability: string implements HasLabel
{
    case Automated = 'automated';
    case AutomatedViaDeployPublisher = 'automated_via_deploy_publisher';
    case ManualOnly = 'manual_only';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.readiness.capabilities.' . $this->value);
    }

    public function allowsAutomatedInstall(): bool
    {
        return $this === self::Automated || $this === self::AutomatedViaDeployPublisher;
    }
}
