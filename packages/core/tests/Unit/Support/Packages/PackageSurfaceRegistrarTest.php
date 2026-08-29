<?php

declare(strict_types=1);

use Capell\Core\Data\PageTypeData;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Capell\Core\Support\OutboundEventRegistry;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\Support\Subscriber\SubscriberRegistry;

it('delegates core surfaces to the core manager and returns itself for chaining', function (): void {
    $pageType = new PageTypeData(name: 'widget', model: stdClass::class);
    $models = [PackageSurfaceRegistrarTestModel::Example];
    $receipts = new ExtensionContributionReceiptRegistry;

    $core = Mockery::mock(CapellCoreManager::class);
    $settings = Mockery::mock(SettingsSchemaRegistry::class);
    $subscribers = Mockery::mock(SubscriberRegistry::class);
    $metricCollectors = new MetricCollectorRegistry(app());

    $core->shouldReceive('registerPageType')->once()->with($pageType);
    $core->shouldReceive('registerComponent')->once()->with('page', 'hero', 'hero-component');
    $core->shouldReceive('registerModels')->once()->with($models);
    $core->shouldReceive('subscriberManager')->once()->andReturn($subscribers);
    $subscribers->shouldReceive('subscribe')->once()->with('App\\Subscriber');
    $settings->shouldReceive('register')->once()->with('seo', 'SchemaClass', null);
    $settings->shouldReceive('registerSettingsClass')->once()->with('seo', 'SettingsClass');

    $metadata = new SettingsGroupMetadata(group: 'seo', label: 'SEO');
    $settings->shouldReceive('registerMetadata')->once()->with($metadata);

    $registrar = new PackageSurfaceRegistrar(
        $core,
        $settings,
        $metricCollectors,
        new OutboundEventRegistry,
        new BlueprintSubjectRegistry,
        $receipts,
    );

    expect($registrar->pageType($pageType))->toBe($registrar)
        ->and($registrar->component('page', 'hero', 'hero-component'))->toBe($registrar)
        ->and($registrar->models($models))->toBe($registrar)
        ->and($registrar->subscriber('App\\Subscriber'))->toBe($registrar)
        ->and($registrar->settingsSchema('seo', 'SchemaClass'))->toBe($registrar)
        ->and($registrar->settingsClass('seo', 'SettingsClass'))->toBe($registrar)
        ->and($registrar->settingsMetadata($metadata))->toBe($registrar);

    expect($receipts->all())->toHaveCount(1);
    $receipt = $receipts->all()[0];

    expect($receipt->type->value)->toBe('model')
        ->and($receipt->key)->toBe('model:' . stdClass::class)
        ->and($receipt->implementation)->toBe(stdClass::class);
});

enum PackageSurfaceRegistrarTestModel: string
{
    case Example = stdClass::class;
}
