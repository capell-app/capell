<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceInstallFailureStage: string implements HasLabel
{
    case Preflight = 'preflight';
    case DeploymentHandoff = 'deployment_handoff';
    case Composer = 'composer';
    case PackageDiscovery = 'package_discovery';
    case Lifecycle = 'lifecycle';
    case Migration = 'migration';
    case HealthCheck = 'health_check';
    case Notification = 'notification';
    case Queue = 'queue';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.failure_stages.' . $this->value);
    }

    /**
     * The operator-facing name for this stage.
     *
     * Spelled out one case at a time rather than composed from the case value,
     * because a key built by concatenation can name a translation that does not
     * exist, and an unresolvable key renders as raw key text in the operator's
     * face. A match over the cases cannot.
     */
    public function progressLabel(): string
    {
        return (string) match ($this) {
            self::Preflight => __('capell-marketplace::marketplace.progress.stage_preflight'),
            self::DeploymentHandoff => __('capell-marketplace::marketplace.progress.stage_deployment_handoff'),
            self::Composer => __('capell-marketplace::marketplace.progress.stage_composer'),
            self::PackageDiscovery => __('capell-marketplace::marketplace.progress.stage_package_discovery'),
            self::Lifecycle => __('capell-marketplace::marketplace.progress.stage_lifecycle'),
            self::Migration => __('capell-marketplace::marketplace.progress.stage_migration'),
            self::HealthCheck => __('capell-marketplace::marketplace.progress.stage_health_check'),
            self::Notification => __('capell-marketplace::marketplace.progress.stage_notification'),
            self::Queue => __('capell-marketplace::marketplace.progress.stage_queue'),
        };
    }
}
