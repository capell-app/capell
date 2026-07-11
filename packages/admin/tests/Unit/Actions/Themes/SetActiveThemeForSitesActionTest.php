<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\SetActiveThemeForSitesAction;
use Capell\Admin\Data\Themes\SetActiveThemeForSitesData;
use Capell\Admin\Enums\Themes\ThemeActivationScope;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Event;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
    Blueprint::factory()->theme()->default()->create();
});

it('sets a theme active globally', function (): void {
    $activeTheme = Theme::factory()->createOne([
        'default' => true,
        'status' => true,
    ]);

    $selectedTheme = Theme::factory()->createOne([
        'default' => false,
        'status' => false,
    ]);

    $otherTheme = Theme::factory()->createOne([
        'default' => false,
        'status' => true,
    ]);

    $theme = SetActiveThemeForSitesAction::run(new SetActiveThemeForSitesData(
        themeId: $selectedTheme->getKey(),
        scope: ThemeActivationScope::Global,
    ));

    expect($theme)
        ->is($selectedTheme)->toBeTrue()
        ->default->toBeTrue()
        ->status->toBeTrue()
        ->and($activeTheme->refresh()->default)->toBeFalse()
        ->and($otherTheme->refresh()->default)->toBeFalse()
        ->and(Theme::query()->default()->count())->toBe(1);
});

it('sets a theme active for selected sites only', function (): void {
    $globalTheme = Theme::factory()->createOne([
        'default' => true,
        'status' => true,
    ]);

    $selectedTheme = Theme::factory()->createOne([
        'default' => false,
        'status' => false,
    ]);

    $unchangedTheme = Theme::factory()->createOne([
        'default' => false,
        'status' => true,
    ]);

    $selectedSite = Site::factory()->theme($globalTheme)->create();
    $anotherSelectedSite = Site::factory()->theme($globalTheme)->create();
    $unselectedSite = Site::factory()->theme($unchangedTheme)->create();

    Event::fake([FrontendSurrogateKeysInvalidated::class]);

    $theme = SetActiveThemeForSitesAction::run(new SetActiveThemeForSitesData(
        themeId: $selectedTheme->getKey(),
        scope: ThemeActivationScope::SelectedSites,
        siteIds: [
            $selectedSite->getKey(),
            $anotherSelectedSite->getKey(),
        ],
    ));

    expect($theme)
        ->is($selectedTheme)->toBeTrue()
        ->default->toBeFalse()
        ->status->toBeTrue()
        ->and($globalTheme->refresh()->default)->toBeTrue()
        ->and($selectedSite->refresh()->theme_id)->toBe($selectedTheme->getKey())
        ->and($anotherSelectedSite->refresh()->theme_id)->toBe($selectedTheme->getKey())
        ->and($unselectedSite->refresh()->theme_id)->toBe($unchangedTheme->getKey());

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === [
            'site-' . $selectedSite->getKey(),
            'site-' . $anotherSelectedSite->getKey(),
        ],
    );
});
