<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class BuildMarketplaceConnectionContextAction
{
    use AsAction;

    /**
     * @return array{instance_id?: string, account_id?: string}
     */
    public function handle(?MarketplaceInstance $instance = null, ?string $fallbackInstanceId = null): array
    {
        $instance ??= $this->latestInstance();

        if (! $instance instanceof MarketplaceInstance) {
            return $this->fallbackContext($fallbackInstanceId);
        }

        $instanceId = $instance->instance_id;

        if (! is_string($instanceId) || $instanceId === '') {
            return $this->fallbackContext($fallbackInstanceId);
        }

        if (! Str::isUuid($instanceId)) {
            Log::warning('capell-marketplace: ignoring invalid marketplace instance id', [
                'marketplace_instance_key' => $instance->getKey(),
                'instance_id' => $instanceId,
            ]);

            return $this->fallbackContext($fallbackInstanceId);
        }

        return array_filter([
            'instance_id' => $instanceId,
            'account_id' => $instance->account_id,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function latestInstance(): ?MarketplaceInstance
    {
        try {
            return MarketplaceInstance::query()
                ->latest('last_heartbeat_at')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{instance_id?: string} */
    private function fallbackContext(?string $fallbackInstanceId): array
    {
        return is_string($fallbackInstanceId) && Str::isUuid($fallbackInstanceId)
            ? ['instance_id' => $fallbackInstanceId]
            : [];
    }
}
