<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceSetupMilestones;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The milestone section of the heartbeat: present once, after the first passing
 * doctor run, and only while the site has opted in. It holds a single timestamp.
 */
final class BuildMarketplaceSetupMilestonesPayloadAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, string>
     */
    public function handle(?MarketplaceInstance $instance): array
    {
        if (! MarketplaceSetupMilestones::shared() || ! $instance instanceof MarketplaceInstance) {
            return [];
        }

        $metadata = is_array($instance->connection_metadata) ? $instance->connection_metadata : [];
        $passedAt = $metadata[MarketplaceSetupMilestones::FIRST_DOCTOR_PASSED_KEY] ?? null;

        if (! is_string($passedAt) || isset($metadata[MarketplaceSetupMilestones::REPORTED_KEY])) {
            return [];
        }

        return [MarketplaceSetupMilestones::FIRST_DOCTOR_PASSED_KEY => $passedAt];
    }
}
