<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

/**
 * Keys for the opt-in setup milestone kept on the Marketplace instance's
 * connection metadata, and the consent check every read and write goes through.
 */
final class MarketplaceSetupMilestones
{
    public const string FIRST_DOCTOR_PASSED_KEY = 'first_doctor_passed_at';

    public const string REPORTED_KEY = 'setup_milestones_reported_at';

    public static function shared(): bool
    {
        return (bool) config('capell-marketplace.heartbeat.share_setup_milestones', false);
    }
}
