<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionHealthProvider;
use Capell\Admin\Data\Extensions\ExtensionHealthAlertData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Collects the alerts that tagged extension health providers raise for one package. A provider that throws is
 * reported as a warning against that package instead of hiding the rest of the page.
 *
 * @method static list<ExtensionHealthAlertData> run(ExtensionOperationPackageData $package)
 */
final class ResolveExtensionProviderAlertsAction
{
    use AsFake;
    use AsObject;

    /** @return list<ExtensionHealthAlertData> */
    public function handle(ExtensionOperationPackageData $package): array
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
                        message: (string) __('capell-admin::dashboard.extension_provider_failed_message'),
                        requiredAction: (string) __('capell-admin::dashboard.extension_provider_failed_action'),
                        developerDetail: $throwable->getMessage(),
                    )];
                }
            })
            ->values()
            ->all());
    }
}
