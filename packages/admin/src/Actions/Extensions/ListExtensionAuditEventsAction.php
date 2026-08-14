<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionAuditEventData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ListExtensionAuditEventsAction
{
    use AsFake;
    use AsObject;

    /** @return list<ExtensionAuditEventData> */
    public function handle(int $limit = 10): array
    {
        return array_values(collect(array_values(BuildExtensionOperationsSummaryAction::run()->packages))
            ->filter(fn (ExtensionOperationPackageData $package): bool => $package->installed)
            ->flatMap(fn (ExtensionOperationPackageData $package): array => [
                new ExtensionAuditEventData(
                    id: 'installed-' . $package->packageName,
                    packageName: $package->packageName,
                    event: 'installed',
                    occurredAt: now()->toImmutable(),
                    message: $package->label,
                ),
                ...$this->recoveryEvents($package),
            ])
            ->take($limit)
            ->values()
            ->all());
    }

    /** @return list<ExtensionAuditEventData> */
    private function recoveryEvents(ExtensionOperationPackageData $package): array
    {
        return array_values(collect($package->providerRecoveryEvents)
            ->map(function (mixed $event) use ($package): ?ExtensionAuditEventData {
                if (! is_array($event) || ! is_string($event['event'] ?? null)) {
                    return null;
                }

                $occurredAt = CarbonImmutable::make($event['occurred_at'] ?? null);

                if (! $occurredAt instanceof CarbonImmutable) {
                    return null;
                }

                return new ExtensionAuditEventData(
                    id: sprintf(
                        'provider-recovery-%s-%s',
                        $package->packageName,
                        hash('sha256', json_encode($event) ?: ''),
                    ),
                    packageName: $package->packageName,
                    event: 'provider_' . $event['event'],
                    occurredAt: $occurredAt,
                    message: is_string($event['reason'] ?? null) ? $event['reason'] : $package->label,
                    actorName: is_string($event['actor'] ?? null) ? $event['actor'] : null,
                );
            })
            ->filter(fn (mixed $event): bool => $event instanceof ExtensionAuditEventData)
            ->all());
    }
}
