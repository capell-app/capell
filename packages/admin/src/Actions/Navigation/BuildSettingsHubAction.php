<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Navigation;

use Capell\Admin\Data\Navigation\SettingsHubEntryData;
use Capell\Admin\Data\Navigation\SettingsHubGroupData;
use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Filament\Pages\AbstractPackageSettingsPage;
use Filament\Facades\Filament;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Collects the package settings pages the person can open, grouped under the
 * same headings as the sidebar, for the Settings hub.
 */
final class BuildSettingsHubAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<class-string>|null  $pageClasses  defaults to the current panel's pages
     * @return list<SettingsHubGroupData>
     */
    public function handle(?array $pageClasses = null): array
    {
        $pageClasses ??= array_values(Filament::getCurrentOrDefaultPanel()?->getPages() ?? []);
        $entries = [];

        foreach ($pageClasses as $pageClass) {
            if (! is_subclass_of($pageClass, AbstractPackageSettingsPage::class)) {
                continue;
            }

            if (! $pageClass::canAccess()) {
                continue;
            }

            $group = $pageClass::getNavigationGroup();
            $group = $group instanceof NavigationGroupEnum ? $group : NavigationGroupEnum::Settings;

            $entries[$group->value][] = new SettingsHubEntryData(
                label: $pageClass::getNavigationLabel(),
                url: $pageClass::getUrl(),
                description: $pageClass::getHubDescription(),
                configured: $pageClass::isConfigured(),
            );
        }

        $groups = [];
        foreach (NavigationGroupEnum::cases() as $case) {
            $caseEntries = $entries[$case->value] ?? [];
            if ($caseEntries === []) {
                continue;
            }

            usort($caseEntries, fn (SettingsHubEntryData $first, SettingsHubEntryData $second): int => strnatcasecmp($first->label, $second->label));
            $groups[] = new SettingsHubGroupData(group: $case, entries: $caseEntries);
        }

        return $groups;
    }
}
