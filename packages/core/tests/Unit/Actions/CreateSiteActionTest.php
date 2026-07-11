<?php

declare(strict_types=1);

use Capell\Core\Actions\CreateSiteAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;

it('creates a site with default theme type translation and domain records', function (): void {
    config(['app.url' => 'https://capell.test/base']);

    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $theme = Theme::factory()->createOne();
    $siteType = Blueprint::factory()->site()->default()->create();

    $site = CreateSiteAction::run(
        name: 'capell cms',
        url: 'https://example.test/uk',
        language: $english,
        languages: collect([$english, $french]),
        theme: $theme,
    );

    expect($site)->toBeInstanceOf(Site::class)
        ->and($site->name)->toBe('capell cms')
        ->and($site->default)->toBeTrue()
        ->and($site->language_id)->toBe($english->id)
        ->and($site->theme_id)->toBe($theme->id)
        ->and($site->blueprint_id)->toBe($siteType->id)
        ->and($site->meta)->toHaveKey('email', config('mail.from.address'))
        ->and(data_get($site->meta, 'mail.use_site_logo'))->toBeTrue()
        ->and($site->admin)->toHaveKey('require_translations', ['en']);

    expect($site->translations()->pluck('title', 'language_id')->all())->toBe([
        $english->id => 'capell cms',
        $french->id => 'capell cms',
    ]);

    $englishDomain = $site->siteDomains()->firstWhere('language_id', $english->id);
    $frenchDomain = $site->siteDomains()->firstWhere('language_id', $french->id);
    $englishDomain = expectPresent($englishDomain);
    $frenchDomain = expectPresent($frenchDomain);

    expect($englishDomain->domain)->toBe('example.test')
        ->and($englishDomain->getRawOriginal('scheme'))->toBe('https')
        ->and($englishDomain->path)->toBe('/uk')
        ->and($frenchDomain->domain)->toBe('example.test')
        ->and($frenchDomain->getRawOriginal('scheme'))->toBe('https')
        ->and($frenchDomain->path)->toBe('/fr/uk');
});

it('returns an existing site with the same name and completes requested language setup', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $existingSite = Site::factory()->for($english)->create(['name' => 'Existing']);

    $site = CreateSiteAction::run(
        name: 'Existing',
        url: 'https://new.test',
        language: $english,
        languages: collect([$english, $french]),
    );

    expect($site->is($existingSite))->toBeTrue()
        ->and(Site::query()->where('name', 'Existing')->count())->toBe(1)
        ->and($existingSite->translations()->pluck('title', 'language_id')->all())->toBe([
            $english->id => 'Existing',
            $french->id => 'Existing',
        ])
        ->and($existingSite->siteDomains()->where('language_id', $english->id)->exists())->toBeTrue()
        ->and($existingSite->siteDomains()->where('language_id', $french->id)->exists())->toBeTrue();
});
