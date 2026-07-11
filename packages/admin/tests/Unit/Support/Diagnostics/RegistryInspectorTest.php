<?php

declare(strict_types=1);

use Capell\Admin\Data\Diagnostics\RegistrySourceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Configurators\Languages\DefaultLanguageConfigurator;
use Capell\Admin\Filament\Widgets\ContentFilamentWidget;
use Capell\Admin\Support\Diagnostics\RegistryInspector;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Core\Facades\CapellCore;

it('inspects registered configurators with source and flow metadata', function (): void {
    CapellAdmin::shouldReceive('getConfigurators')
        ->with('Languages')
        ->andReturn(['Default' => DefaultLanguageConfigurator::class]);
    CapellAdmin::shouldReceive('getConfiguratorCachePath')
        ->andReturn(base_path('bootstrap/cache/capell-configurators.php'));

    $source = resolve(RegistryInspector::class)->configurators('Languages')->first();

    expect($source->key)->toBe('Default')
        ->and($source->kind)->toBe('configurator')
        ->and($source->class)->toBe(DefaultLanguageConfigurator::class)
        ->and($source->path)->toBe(new ReflectionClass(DefaultLanguageConfigurator::class)->getFileName())
        ->and($source->sourceMode)->toBe('registered')
        ->and($source->cachePath)->toBe(base_path('bootstrap/cache/capell-configurators.php'))
        ->and($source->statePath)->toBe('Languages')
        ->and($source->flow->pluck('label')->all())->toContain('Configurator type', 'Class', 'Path');
});

it('inspects registered components and reports unresolved view warnings', function (): void {
    CapellCore::shouldReceive('getComponents')
        ->with('page')
        ->andReturn(['Hero' => 'capell-page.hero']);
    CapellCore::shouldReceive('getComponentCachePath')
        ->andReturn(base_path('bootstrap/cache/capell-components.php'));

    $source = resolve(RegistryInspector::class)->components('page')->first();

    expect($source->key)->toBe('capell-page.hero')
        ->and($source->kind)->toBe('component')
        ->and($source->label)->toBe('Hero')
        ->and($source->view)->toBe('capell-page.hero')
        ->and($source->cachePath)->toBe(base_path('bootstrap/cache/capell-components.php'))
        ->and($source->flow->last()->status)->toBe('warning');
});

it('inspects admin widgets from the internal registry', function (): void {
    CapellAdmin::shouldReceive('getFilamentWidgets')->andReturn([]);
    CapellAdmin::shouldReceive('getWidgetCachePath')
        ->andReturn(base_path('bootstrap/cache/capell-widgets.php'));

    resolve(WidgetDiscovery::class)->register(ContentFilamentWidget::class);

    $source = resolve(RegistryInspector::class)->widgets()
        ->first(fn (RegistrySourceData $registeredSource): bool => $registeredSource->key === 'content');

    expect($source->key)->toBe('content')
        ->and($source->kind)->toBe('widget')
        ->and($source->class)->toBe(ContentFilamentWidget::class)
        ->and($source->path)->toBe(new ReflectionClass(ContentFilamentWidget::class)->getFileName())
        ->and($source->cachePath)->toBe(base_path('bootstrap/cache/capell-widgets.php'))
        ->and($source->statePath)->toBe('admin-filament')
        ->and($source->flow->pluck('label')->all())->toContain('Target', 'Class');
});
