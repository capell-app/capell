<?php

declare(strict_types=1);

namespace Capell\Core\Support\Blueprints;

use Capell\Core\Actions\CreateDefaultPageBlueprintAction;
use Capell\Core\Actions\CreateDefaultSiteBlueprintAction;
use Capell\Core\Actions\CreateDefaultThemeBlueprintAction;
use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;

/**
 * The blueprint subjects core itself contributes.
 *
 * Page, Site and Theme are declared here as ordinary descriptors and registered
 * through the same `$this->surface()->blueprintSubject()` path a package uses,
 * so core exercises the contract it publishes rather than a privileged shortcut.
 *
 * {@see BlueprintSubjectEnum} names these three keys for call sites that want a
 * typed reference to a built-in; it is a key namespace only. Labels, models and
 * seeders live here, and the registry is the single source for resolving them.
 *
 * Labels are literal strings rather than `__()` calls on purpose. Descriptors are
 * built once at boot and cached for the process lifetime, so translating here
 * would freeze every operator's label to whichever locale booted first — the
 * same Octane hazard that locale-keyed enum option caches avoid. Display surfaces
 * translate at render time instead.
 */
final class CoreBlueprintSubjects
{
    public const OWNER_PACKAGE = 'capell-app/core';

    /**
     * @return list<BlueprintSubjectDescriptorData>
     */
    public static function descriptors(): array
    {
        return [
            new BlueprintSubjectDescriptorData(
                key: BlueprintSubjectEnum::Page->getKey(),
                label: 'Page',
                modelClass: Page::class,
                ownerPackage: self::OWNER_PACKAGE,
                // Pages are the only built-in subject with system and listing
                // blueprints; site and theme blueprints are always default.
                groups: [BlueprintGroupEnum::Default, BlueprintGroupEnum::System, BlueprintGroupEnum::Results],
                defaultSchemaSeeder: CreateDefaultPageBlueprintAction::class,
            ),
            new BlueprintSubjectDescriptorData(
                key: BlueprintSubjectEnum::Site->getKey(),
                label: 'Site',
                modelClass: Site::class,
                ownerPackage: self::OWNER_PACKAGE,
                groups: [BlueprintGroupEnum::Default],
                defaultSchemaSeeder: CreateDefaultSiteBlueprintAction::class,
            ),
            new BlueprintSubjectDescriptorData(
                key: BlueprintSubjectEnum::Theme->getKey(),
                label: 'Theme',
                modelClass: Theme::class,
                ownerPackage: self::OWNER_PACKAGE,
                groups: [BlueprintGroupEnum::Default],
                defaultSchemaSeeder: CreateDefaultThemeBlueprintAction::class,
            ),
        ];
    }
}
