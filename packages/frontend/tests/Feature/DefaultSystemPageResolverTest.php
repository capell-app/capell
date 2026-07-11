<?php

declare(strict_types=1);

use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ResolveSystemPageAction;
use Capell\Frontend\Settings\FrontendSettings;

it('auto creates a missing 404 page when configured', function (): void {
    config()->set('capell-frontend.system_pages.auto_create_missing', true);

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();

    $page = expectPresent(ResolveSystemPageAction::run(PageTypeEnum::NotFound->value, $site, $language));

    expect($page)->toBeInstanceOf(Page::class)
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeTrue()
        ->and(PageUrl::query()->where('pageable_id', $page->getKey())->where('url', '/not-found')->exists())->toBeTrue();
});

it('does not auto create a missing 404 page when disabled by config', function (): void {
    config()->set('capell-frontend.system_pages.auto_create_missing', false);

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();

    expect(ResolveSystemPageAction::run(PageTypeEnum::NotFound->value, $site, $language))->toBeNull()
        ->and(Page::query()->count())->toBe(0);
});

it('does not resolve custom error pages when disabled in frontend settings', function (): void {
    $settings = resolve(FrontendSettings::class);
    $settings->custom_error_page_enabled = false;
    $settings->save();

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();

    expect(ResolveSystemPageAction::run(PageTypeEnum::NotFound->value, $site, $language))->toBeNull()
        ->and(Page::query()->count())->toBe(0);
});

it('does not resolve custom maintenance pages when disabled in frontend settings', function (): void {
    $settings = resolve(FrontendSettings::class);
    $settings->custom_maintenance_page_enabled = false;
    $settings->save();

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();

    expect(ResolveSystemPageAction::run(PageTypeEnum::Maintenance->value, $site, $language))->toBeNull()
        ->and(Page::query()->count())->toBe(0);
});

it('keeps custom error pages enabled when only maintenance pages are disabled', function (): void {
    $settings = resolve(FrontendSettings::class);
    $settings->custom_maintenance_page_enabled = false;
    $settings->save();

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();

    $page = ResolveSystemPageAction::run(PageTypeEnum::NotFound->value, $site, $language);

    expect($page)->toBeInstanceOf(Page::class);
});
