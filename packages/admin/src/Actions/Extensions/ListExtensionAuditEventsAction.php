<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionAuditEventData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Lorisleiva\Actions\Concerns\AsAction;

final class ListExtensionAuditEventsAction
{
    use AsAction;

    /** @return list<ExtensionAuditEventData> */
    public function handle(int $limit = 10): array
    {
        return array_values(collect(array_values(BuildExtensionOperationsSummaryAction::run()->packages))
            ->filter(fn (ExtensionOperationPackageData $package): bool => $package->installed)
            ->map(fn (ExtensionOperationPackageData $package): ExtensionAuditEventData => new ExtensionAuditEventData(
                id: 'installed-' . $package->packageName,
                packageName: $package->packageName,
                event: 'installed',
                occurredAt: now()->toImmutable(),
                message: $package->label,
            ))
            ->take($limit)
            ->values()
            ->all());
    }
}
