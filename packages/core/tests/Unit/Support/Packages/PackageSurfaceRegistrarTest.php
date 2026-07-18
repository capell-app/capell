<?php

declare(strict_types=1);

use Capell\Core\Data\PageTypeData;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\Support\Subscriber\SubscriberRegistry;

it('delegates core surfaces to the core manager and returns itself for chaining', function (): void {
    $pageType = new PageTypeData(name: 'widget', model: stdClass::class);

    $core = Mockery::mock(CapellCoreManager::class);
    $settings = Mockery::mock(SettingsSchemaRegistry::class);
    $subscribers = Mockery::mock(SubscriberRegistry::class);

    $core->shouldReceive('registerPageType')->once()->with($pageType);
    $core->shouldReceive('registerComponent')->once()->with('page', 'hero', 'hero-component');
    $core->shouldReceive('registerModels')->once()->with([stdClass::class]);
    $core->shouldReceive('subscriberManager')->once()->andReturn($subscribers);
    $subscribers->shouldReceive('subscribe')->once()->with('App\\Subscriber');
    $settings->shouldReceive('register')->once()->with('seo', 'SchemaClass', null);

    $registrar = new PackageSurfaceRegistrar($core, $settings);

    expect($registrar->pageType($pageType))->toBe($registrar)
        ->and($registrar->component('page', 'hero', 'hero-component'))->toBe($registrar)
        ->and($registrar->models([stdClass::class]))->toBe($registrar)
        ->and($registrar->subscriber('App\\Subscriber'))->toBe($registrar)
        ->and($registrar->settingsSchema('seo', 'SchemaClass'))->toBe($registrar);
});
