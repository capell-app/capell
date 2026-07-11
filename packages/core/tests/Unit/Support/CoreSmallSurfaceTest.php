<?php

declare(strict_types=1);

use Capell\Core\Actions\BladeComponentFacadeResolver;
use Capell\Core\Actions\CreateDefaultLanguageAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Data\DefaultPageData;
use Capell\Core\Data\Makers\MakerDatabaseRecordData;
use Capell\Core\Enums\CacheTime;
use Capell\Core\Enums\DefaultColorEnum;
use Capell\Core\Enums\HeaderPositionEnum;
use Capell\Core\Enums\MenuAlignmentEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Support\Media\BackendResolver;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;

it('maps configured default colors to option payloads', function (): void {
    config(['capell.default_colors' => [
        'primary' => '#123456',
        'dark_gray' => '#222222',
    ]]);

    expect(DefaultColorEnum::Primary->getColor())->toBe('#123456')
        ->and(DefaultColorEnum::DarkGray->getColor())->toBe('#222222')
        ->and(DefaultColorEnum::Warning->getColor())->toBe('')
        ->and(DefaultColorEnum::getKeyValues())->toHaveKey('primary', '#123456')
        ->and(DefaultColorEnum::getValues())->toContain([
            'name' => 'primary',
            'color' => '#123456',
        ])
        ->and(DefaultColorEnum::Primary->getLabel())->toBe(__('capell-core::generic.primary'));
});

it('exposes labels and presentation metadata for core enums', function (): void {
    expect(UrlTypeEnum::Alias->getLabel())->toBe(__('capell::generic.alias'))
        ->and(UrlTypeEnum::Alias->getDescription())->toBe(__('capell::generic.alias_description'))
        ->and(UrlTypeEnum::Alias->getColor())->toBe('secondary')
        ->and(UrlTypeEnum::Alias->getIcon())->toBe(Heroicon::Link)
        ->and(UrlTypeEnum::Redirect->getColor())->toBe('info')
        ->and(CacheTime::Never->getLabel())->toBe(__('capell::generic.never'))
        ->and(CacheTime::Yearly->getLabel())->toBe(__('capell::generic.yearly'))
        ->and(HeaderPositionEnum::Static_->getLabel())->toBe(__('capell-admin::form.header_position_disabled'))
        ->and(HeaderPositionEnum::ScrollUp->getLabel())->toBe(__('capell-admin::form.header_position_scroll_up'))
        ->and(MenuAlignmentEnum::Left->getLabel())->toBe(__('capell-admin::generic.left'))
        ->and(MenuAlignmentEnum::Right->getLabel())->toBe(__('capell-admin::generic.right'))
        ->and(PageTypeEnum::Home->defaultLayoutEnum()->value)->toBe('default');
});

it('hydrates small data boundaries used by defaults and maker previews', function (): void {
    $callback = fn (): string => 'created';
    $page = new DefaultPageData(key: 'home', label: 'Home', callback: $callback);
    $record = new MakerDatabaseRecordData(
        model: 'App\\Models\\Example',
        operation: 'update',
        attributes: ['name' => 'Example'],
        snippet: 'Example::query()->update([...]);',
    );
    $pageCallback = expectPresent($page->callback);

    expect($page->key)->toBe('home')
        ->and($page->label)->toBe('Home')
        ->and($pageCallback())->toBe('created')
        ->and($record->model)->toBe('App\\Models\\Example')
        ->and($record->operation)->toBe('update')
        ->and($record->attributes)->toBe(['name' => 'Example'])
        ->and($record->snippet)->toBe('Example::query()->update([...]);');
});

it('resolves settings metadata labels and navigation groups', function (): void {
    $plain = new SettingsGroupMetadata(group: 'plain', label: 'Plain settings', navigationGroup: 'Tools');
    $translated = new SettingsGroupMetadata(
        group: 'translated',
        label: 'capell-admin::navigation.settings',
        navigationGroup: 'capell-admin::navigation.group_system',
    );

    expect($plain->getLabel())->toBe('Plain settings')
        ->and($plain->getNavigationGroup())->toBe('Tools')
        ->and(new SettingsGroupMetadata(group: 'none', label: 'None')->getNavigationGroup())->toBeNull()
        ->and($translated->getLabel())->toBe(__('capell-admin::navigation.settings'))
        ->and($translated->getNavigationGroup())->toBe(__('capell-admin::navigation.group_system'));
});

it('resolves the configured media backend with spatie as the default', function (): void {
    config(['capell.media.backend' => null]);
    $resolver = new BackendResolver;

    expect($resolver->name())->toBe('spatie')
        ->and($resolver->isSpatie())->toBeTrue()
        ->and($resolver->isCurator())->toBeFalse();

    config(['capell.media.backend' => 'curator']);

    expect($resolver->name())->toBe('curator')
        ->and($resolver->isSpatie())->toBeFalse()
        ->and($resolver->isCurator())->toBeTrue();
});

it('creates default languages and preserves existing records', function (): void {
    $english = CreateDefaultLanguageAction::run();

    expect($english->code)->toBe('en')
        ->and($english->default)->toBeTrue()
        ->and($english->locale)->toBe('en_GB');

    $languages = CreateDefaultLanguagesAction::run(['fr', 'en', 'de']);
    $french = expectPresent($languages->firstWhere('code', 'fr'));
    $englishFromCollection = expectPresent($languages->firstWhere('code', 'en'));

    expect($languages->pluck('code')->all())->toBe(['fr', 'en', 'de'])
        ->and($french->default)->toBeFalse()
        ->and($french->name)->toBe('French')
        ->and($englishFromCollection->is($english))->toBeTrue()
        ->and(CreateDefaultLanguageAction::run(default: false)->is($english))->toBeTrue();
});

it('reads Blade class component aliases and namespaces from the facade', function (): void {
    Blade::component('App\\View\\Components\\HeroCard', 'hero-card');
    Blade::componentNamespace('App\\View\\Components', 'app');

    $resolver = new BladeComponentFacadeResolver;

    expect($resolver->getClassComponentAliases())->toHaveKey('hero-card', 'App\\View\\Components\\HeroCard')
        ->and($resolver->getClassComponentNamespaces())->toHaveKey('app', 'App\\View\\Components');
});
