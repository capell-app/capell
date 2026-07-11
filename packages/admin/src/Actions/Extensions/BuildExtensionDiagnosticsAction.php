<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionHealthProvider;
use Capell\Admin\Data\Extensions\ExtensionHealthAlertData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class BuildExtensionDiagnosticsAction
{
    use AsAction;

    /** @return list<ExtensionHealthAlertData> */
    public function handle(): array
    {
        return array_values(collect(array_values(BuildExtensionOperationsSummaryAction::run()->packages))
            ->flatMap(fn (ExtensionOperationPackageData $package): array => [
                ...$package->healthAlerts,
                ...$this->providerAlerts($package),
            ])
            ->sortBy(fn (ExtensionHealthAlertData $alert): int => match ($alert->severity) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            })
            ->values()
            ->all());
    }

    /** @return list<ExtensionHealthAlertData> */
    private function providerAlerts(ExtensionOperationPackageData $package): array
    {
        return array_values(collect(app()->tagged(ExtensionHealthProvider::TAG))
            ->flatMap(function (ExtensionHealthProvider $provider) use ($package): array {
                try {
                    return $provider->alerts($package);
                } catch (Throwable $throwable) {
                    return [new ExtensionHealthAlertData(
                        id: 'provider-failed-' . hash('sha256', $provider::class . $package->packageName),
                        packageName: $package->packageName,
                        severity: 'warning',
                        category: 'provider',
                        title: __('capell-admin::dashboard.extension_provider_failed_title'),
                        message: $throwable->getMessage(),
                    )];
                }
            })
            ->values()
            ->all());
    }
}
