<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceActivationContext;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class PersistMarketplaceActivationAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt): ?CapellExtension
    {
        $signedActivation = MarketplaceActivationContext::decryptFrom($attempt->context);

        if ($signedActivation === []) {
            return null;
        }

        $extension = CapellExtension::query()
            ->where('composer_name', $attempt->composer_name)
            ->first();

        if (! $extension instanceof CapellExtension) {
            throw new RuntimeException(sprintf(
                'Marketplace activation could not be persisted because extension [%s] is not installed.',
                $attempt->composer_name,
            ));
        }

        $runtimeStatus = $signedActivation['runtime_status'] ?? $signedActivation['status'] ?? 'active';

        $extension->forceFill([
            'marketplace_signed_activation' => $signedActivation,
            'marketplace_runtime_status' => is_string($runtimeStatus) && $runtimeStatus !== '' ? $runtimeStatus : 'active',
            'marketplace_activation_checked_at' => now(),
        ])->save();

        return $extension->refresh();
    }
}
