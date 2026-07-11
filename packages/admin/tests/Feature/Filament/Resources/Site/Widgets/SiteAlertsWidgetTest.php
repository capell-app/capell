<?php

declare(strict_types=1);

use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Sites\Widgets\SiteAlertsWidget;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('see livewire component', function (): void {
    $site = Site::factory()->createOne();

    get(SiteResource::getUrl('edit', ['record' => $site->id]))
        ->assertSeeLivewire(SiteAlertsWidget::class);
});

test('no alerts when all site languages have domains', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations()->create();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'language_id' => $language->id,
    ]);

    Livewire::test(SiteAlertsWidget::class, ['record' => $site])
        ->assertSet('alerts', fn (Collection $alerts): bool => $alerts->isEmpty());
});

test('alerts when site languages are missing domains', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne(['name' => 'French']);
    Translation::factory()->language($language)->translatable($site)->create();

    $component = Livewire::test(SiteAlertsWidget::class, ['record' => $site])
        ->instance();

    assert($component instanceof SiteAlertsWidget);

    $alerts = $component->alerts();

    $alert = $alerts['missingLanguage'] ?? null;
    $alert = expectPresent($alert);

    expect($alert)->not->toBeNull()
        ->and($alert->type->value)->toBe(AlertTypeEnum::Warning->value)
        ->and($alert->message)->toContain($language->name)
        ->and($alert->icon)->toBe('heroicon-o-exclamation-triangle');
});
