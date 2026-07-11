<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\Creator\BlueprintCreator;

it('creates core page types with editor-facing descriptions', function (): void {
    resolve(BlueprintCreator::class)->createPageTypes();

    $descriptions = Blueprint::query()
        ->where('type', BlueprintSubjectEnum::Page->value)
        ->pluck('admin', 'key')
        ->map(fn (?array $admin): ?string => $admin['notes'] ?? null)
        ->all();

    expect($descriptions)->toMatchArray([
        PageTypeEnum::Default->value => 'A flexible page for ordinary content, landing pages, and simple publishing.',
        PageTypeEnum::Home->value => 'The main entry page for a site, usually excluded from listings.',
        PageTypeEnum::Maintenance->value => 'A fixed system page shown while a site or route is unavailable.',
        PageTypeEnum::NotFound->value => 'A fixed system page for missing URLs and not-found responses.',
        PageTypeEnum::System->value => 'A protected page blueprint for internal, generated, or non-editorial output.',
    ]);
});

it('creates core site theme and navigation types with editor-facing descriptions', function (): void {
    $creator = resolve(BlueprintCreator::class);

    $siteType = $creator->createSiteType();
    $themeType = $creator->createThemeType();
    $navigationType = $creator->createNavigationType();
    $siteAdmin = $siteType->admin ?? [];
    $themeAdmin = $themeType->admin ?? [];
    $navigationAdmin = $navigationType->admin ?? [];

    expect($siteAdmin['notes'] ?? null)->toBe('The baseline site blueprint for domains, languages, pages, settings, and theme choice.')
        ->and($themeAdmin['notes'] ?? null)->toBe('The baseline visual theme record used when a site has no specialist theme.')
        ->and($navigationType->name)->toBe('Navigation')
        ->and($navigationAdmin['notes'] ?? null)->toBe('A reusable navigation structure for menus, links, and site wayfinding.');
});
