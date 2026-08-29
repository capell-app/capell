<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Pages\SitemapPage;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Tests\Fixtures\Autoload\TestSchemaExtenderForRegistrar;
use Capell\Core\Support\Extensions\ExtensionPosition;

it('creates page contribution data', function (): void {
    $contribution = AdminSurfaceContributionData::page(SettingsPage::class);

    expect($contribution->type)->toBe(AdminSurfaceContributionType::Page)
        ->and($contribution->class)->toBe(SettingsPage::class)
        ->and($contribution->key)->toBe(SettingsPage::class)
        ->and($contribution->group)->toBeNull()
        ->and($contribution->name)->toBe('default')
        ->and($contribution->tag)->toBeNull();
});

it('creates resource contribution data with group and name', function (): void {
    $contribution = AdminSurfaceContributionData::resource(
        class: SiteResource::class,
        group: 'Site',
        name: 'default',
    );

    expect($contribution->type)->toBe(AdminSurfaceContributionType::Resource)
        ->and($contribution->key)->toBe('resource:Site:default')
        ->and($contribution->group)->toBe('Site')
        ->and($contribution->name)->toBe('default');
});

it('creates configurator and schema extender contribution data', function (): void {
    $configurator = AdminSurfaceContributionData::configurator(
        class: DefaultPageConfigurator::class,
        group: 'page',
        name: 'default',
    );

    $schemaExtender = AdminSurfaceContributionData::schemaExtender(
        class: PageSchemaExtender::class,
        tag: PageSchemaExtender::TAG,
    );

    expect($configurator->type)->toBe(AdminSurfaceContributionType::Configurator)
        ->and($configurator->key)->toBe('configurator:page:default')
        ->and($schemaExtender->type)->toBe(AdminSurfaceContributionType::SchemaExtender)
        ->and($schemaExtender->tag)->toBe(PageSchemaExtender::TAG);
});

it('deduplicates contributions by type and key', function (): void {
    $registry = new AdminSurfaceContributionRegistry;

    $registry->register(AdminSurfaceContributionData::page(SettingsPage::class));
    $registry->register(AdminSurfaceContributionData::page(SettingsPage::class));

    expect($registry->pages())->toBe([SettingsPage::class]);
});

it('ignores stale page contributions for classes that no longer exist', function (): void {
    $registry = new AdminSurfaceContributionRegistry;

    $registry->register(AdminSurfaceContributionData::page('Capell\\Installer\\Filament\\Pages\\DeletedInstallerPage'));
    $registry->register(AdminSurfaceContributionData::page(SettingsPage::class));

    expect($registry->pages())->toBe([SettingsPage::class]);
});

it('returns named resources and configurators by group', function (): void {
    $registry = new AdminSurfaceContributionRegistry;

    $registry->register(AdminSurfaceContributionData::resource(SiteResource::class, 'Site'));
    $registry->register(AdminSurfaceContributionData::configurator(DefaultPageConfigurator::class, 'page', 'default'));

    expect($registry->resources())->toBe([SiteResource::class])
        ->and($registry->resourcesForGroup('Site'))->toBe(['default' => SiteResource::class])
        ->and($registry->configuratorsForGroup('page'))->toBe(['default' => DefaultPageConfigurator::class]);
});

it('returns tagged schema extenders and clears contributions', function (): void {
    $registry = new AdminSurfaceContributionRegistry;

    $registry->register(AdminSurfaceContributionData::schemaExtender(TestSchemaExtenderForRegistrar::class, PageSchemaExtender::TAG));

    expect($registry->schemaExtendersForTag(PageSchemaExtender::TAG))->toBe([TestSchemaExtenderForRegistrar::class]);

    $registry->clear();

    expect($registry->all())->toBe([]);
});

it('orders admin contributions and rejects implicit owner collisions', function (): void {
    $registry = new AdminSurfaceContributionRegistry;
    $registry->register(new AdminSurfaceContributionData(AdminSurfaceContributionType::Page, SettingsPage::class, 'settings', owner: 'vendor/a', position: ExtensionPosition::priority(20), source: 'Vendor\\Provider'));
    $registry->register(new AdminSurfaceContributionData(AdminSurfaceContributionType::Page, SitemapPage::class, 'sitemap', owner: 'vendor/b', position: ExtensionPosition::first(), source: 'Other\\Provider'));

    expect($registry->pages())->toBe([SitemapPage::class, SettingsPage::class]);

    expect(function () use ($registry): void {
        $registry->register(new AdminSurfaceContributionData(AdminSurfaceContributionType::Page, SettingsPage::class, 'settings', owner: 'vendor/c', source: 'Other\\Provider'));
    })
        ->toThrow(LogicException::class, 'vendor/a');
});

it('allows explicit replacement and rejects registrations after freeze', function (): void {
    $registry = new AdminSurfaceContributionRegistry;
    $registry->register(AdminSurfaceContributionData::page(SettingsPage::class));
    $registry->replace(new AdminSurfaceContributionData(AdminSurfaceContributionType::Page, SitemapPage::class, SettingsPage::class));

    expect($registry->pages())->toBe([SitemapPage::class]);

    $registry->freeze();

    expect(function () use ($registry): void {
        $registry->register(AdminSurfaceContributionData::page(SettingsPage::class));
    })
        ->toThrow(LogicException::class, 'frozen');
});
