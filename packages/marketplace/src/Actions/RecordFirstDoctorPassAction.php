<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceSetupMilestones;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Remembers the first fully passing `capell:doctor` run on the connected
 * Marketplace instance, only when the site has opted in to sharing setup
 * milestones. Later passes never move the recorded moment.
 */
final class RecordFirstDoctorPassAction
{
    use AsFake;
    use AsObject;

    public function handle(): void
    {
        if (! MarketplaceSetupMilestones::shared()) {
            return;
        }

        $instance = MarketplaceInstance::query()->latest('last_heartbeat_at')->first();

        if (! $instance instanceof MarketplaceInstance) {
            return;
        }

        $metadata = is_array($instance->connection_metadata) ? $instance->connection_metadata : [];

        if (isset($metadata[MarketplaceSetupMilestones::FIRST_DOCTOR_PASSED_KEY])) {
            return;
        }

        $metadata[MarketplaceSetupMilestones::FIRST_DOCTOR_PASSED_KEY] = now()->toIso8601String();
        $instance->forceFill(['connection_metadata' => $metadata])->save();
    }
}
