<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionHealthAlertData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildExtensionDiagnosticsAction
{
    use AsFake;
    use AsObject;

    /** @return list<ExtensionHealthAlertData> */
    public function handle(): array
    {
        return array_values(collect(array_values(BuildExtensionOperationsSummaryAction::run()->packages))
            ->flatMap(fn (ExtensionOperationPackageData $package): array => $package->healthAlerts)
            ->sortBy(fn (ExtensionHealthAlertData $alert): int => match ($alert->severity) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            })
            ->values()
            ->all());
    }
}
