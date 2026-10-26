<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Admin\Actions\ListExtensionManagementEntriesAction;
use Capell\Admin\Data\ExtensionManagementEntryData;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Data\MarketplaceInstallProgressData;
use Capell\Marketplace\Data\MarketplaceInstallSetupData;
use Capell\Marketplace\Enums\MarketplaceRuntimePropagationStatus;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveMarketplaceInstallSetupAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallProgressData $progress): ?MarketplaceInstallSetupData
    {
        if (! $progress->succeeded() || $progress->runtimePropagationStatus === MarketplaceRuntimePropagationStatus::Pending || ! ExtensionsPage::canManageExtensions() || ! ExtensionsPage::canAccess()) {
            return null;
        }

        // The worker may have finished before this web process has refreshed.
        // Keep its existing refresh handoff ahead of any management shortcut.
        if ($progress->runtimeRefreshRequired || $progress->runtimePropagationStatus !== MarketplaceRuntimePropagationStatus::Ready) {
            return new MarketplaceInstallSetupData(
                installedUrl: null,
                documentationUrl: null,
                hasEditor: false,
                runtimeRefreshRequired: true,
                runtimeRefreshInstructions: config('capell.multi_node', false) === true
                    ? (string) __('capell-marketplace::marketplace.progress.runtime_refresh_multi_node')
                    : (string) __('capell-marketplace::marketplace.progress.runtime_refresh_single_node'),
            );
        }

        if (! CapellCore::hasPackage($progress->composerName) || ! CapellCore::isPackageInstalled($progress->composerName)) {
            return null;
        }

        $entry = collect(ListExtensionManagementEntriesAction::run())
            ->first(fn (ExtensionManagementEntryData $entry): bool => $entry->packageName === $progress->composerName && $entry->installed);

        if (! $entry instanceof ExtensionManagementEntryData) {
            return null;
        }

        return new MarketplaceInstallSetupData(
            installedUrl: ExtensionsPage::getUrl([
                'installed-extensionsFilters' => ['extension_filters' => ['composer_name' => $progress->composerName]],
            ]),
            documentationUrl: $this->documentationUrl($entry->documentationUrl),
            hasEditor: $entry->primaryUrl !== null || $entry->managementSurfaces !== [],
            runtimeRefreshRequired: false,
        );
    }

    private function documentationUrl(?string $url): ?string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
        ) {
            return null;
        }

        return $url;
    }
}
