<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Widgets\CardsFilamentWidget;
use Capell\Admin\Filament\Widgets\ContentFilamentWidget;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Admin\Tests\Fixtures\Widgets\AlternateHeroWidget;
use Capell\Admin\Tests\Fixtures\Widgets\HeroWidget;
use Capell\Core\Support\BlueprintBlockTypeRegistry;
use Filament\Forms\Components\Builder\Block;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;

it('built-in widgets implement FilamentWidget', function (): void {
    expect(ContentFilamentWidget::class)->toImplement(FilamentWidget::class)
        ->and(CardsFilamentWidget::class)->toImplement(FilamentWidget::class);
});

it('built-in widgets return their widget name', function (): void {
    expect(ContentFilamentWidget::getWidgetName())->toBe('content')
        ->and(CardsFilamentWidget::getWidgetName())->toBe('cards');
});

it('service provider registers built-in widgets via CapellAdmin', function (): void {
    $registeredWidgets = resolve(WidgetDiscovery::class)->registeredWidgets();

    expect($registeredWidgets['content'] ?? null)->toBe(ContentFilamentWidget::class)
        ->and($registeredWidgets['cards'] ?? null)->toBe(CardsFilamentWidget::class);
});

it('getFilamentWidgets returns Block instances for all registered widgets', function (): void {
    $widgets = CapellAdmin::getFilamentWidgets();

    expect($widgets)->toBeArray()
        ->and($widgets)->not->toBeEmpty()
        ->and($widgets[0])->toBeInstanceOf(Block::class);

    $names = array_map(fn (Block $widget): string => $widget->getName(), $widgets);

    expect($names)->toContain('content')
        ->and($names)->toContain('cards');
});

it('registerWidget adds a custom widget to the registry', function (): void {
    CapellAdmin::registerWidget(HeroWidget::class);

    $registeredWidgets = resolve(WidgetDiscovery::class)->registeredWidgets();

    expect($registeredWidgets['hero'] ?? null)->toBe(HeroWidget::class);

    $widgets = CapellAdmin::getFilamentWidgets();
    $names = array_map(fn (Block $widget): string => $widget->getName(), $widgets);

    expect($names)->toContain('hero');
});

it('exposes registered widgets to blueprint block schema generation', function (): void {
    CapellAdmin::registerWidget(HeroWidget::class);

    expect(resolve(BlueprintBlockTypeRegistry::class)->for())
        ->toContain('content', 'hero');
});

it('registerWidget throws for a class that does not implement a Filament widget contract', function (): void {
    expect(fn (): mixed => CapellAdmin::registerWidget(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});

it('lets an authoritative widget replace and lock an earlier ordinary registration', function (): void {
    $discovery = new WidgetDiscovery;

    $discovery->register(AlternateHeroWidget::class);
    $discovery->registerAuthoritative(HeroWidget::class);
    $discovery->register(AlternateHeroWidget::class);

    expect($discovery->registeredWidgets()['hero'] ?? null)->toBe(HeroWidget::class);
});

it('keeps an authoritative widget when an ordinary registration arrives later', function (): void {
    $discovery = new WidgetDiscovery;

    $discovery->registerAuthoritative(HeroWidget::class);
    $discovery->register(AlternateHeroWidget::class);

    expect($discovery->registeredWidgets()['hero'] ?? null)->toBe(HeroWidget::class);
});

it('registerDiscoverableWidgets auto-discovers widgets in a directory', function (): void {
    $fixturesDir = __DIR__ . '/../../Fixtures/Widgets';

    CapellAdmin::registerDiscoverableWidgets($fixturesDir, 'Capell\\Admin\\Tests\\Fixtures\\Widgets');

    $widgets = CapellAdmin::getFilamentWidgets();
    $names = array_map(fn (Block $widget): string => $widget->getName(), $widgets);

    expect($names)->toContain('hero');
});

it('scans discoverable widget directories once per unchanged source set', function (): void {
    $fixturesDir = __DIR__ . '/../../Fixtures/Widgets';
    $filesystem = Mockery::mock(Filesystem::class)->makePartial();
    $filesystem->shouldReceive('allFiles')
        ->once()
        ->with($fixturesDir)
        ->passthru();
    $discovery = new WidgetDiscovery($filesystem);

    $discovery->registerDiscoverableWidgets($fixturesDir, 'Capell\\Admin\\Tests\\Fixtures\\Widgets');

    expect($discovery->registeredWidgets())->toHaveKey('hero');
    expect($discovery->registeredWidgets())->toHaveKey('hero');
});

it('scans only a newly registered widget source after initial discovery', function (): void {
    $firstSource = '/fixtures/widgets/first';
    $secondSource = '/fixtures/widgets/second';
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('isDirectory')->once()->with($firstSource)->andReturnTrue();
    $filesystem->shouldReceive('isDirectory')->once()->with($secondSource)->andReturnTrue();
    $filesystem->shouldReceive('allFiles')->once()->with($firstSource)->andReturn([
        new SplFileInfo(__DIR__ . '/../../Fixtures/Widgets/HeroWidget.php', '', 'HeroWidget.php'),
    ]);
    $filesystem->shouldReceive('allFiles')->once()->with($secondSource)->andReturn([
        new SplFileInfo(__DIR__ . '/../../Fixtures/Widgets/StateIntegrityFilamentWidget.php', '', 'StateIntegrityFilamentWidget.php'),
    ]);
    $discovery = new WidgetDiscovery($filesystem);

    $discovery->registerDiscoverableWidgets($firstSource, 'Capell\\Admin\\Tests\\Fixtures\\Widgets');

    expect($discovery->registeredWidgets())->toHaveKey('hero')
        ->not->toHaveKey('capell-app.state-integrity');

    $discovery->registerDiscoverableWidgets($secondSource, 'Capell\\Admin\\Tests\\Fixtures\\Widgets');

    expect($discovery->registeredWidgets())->toHaveKey('hero')
        ->toHaveKey('capell-app.state-integrity');
});

it('registerDiscoverableWidgets silently skips a non-existent directory', function (): void {
    CapellAdmin::registerDiscoverableWidgets('/non/existent/path', 'App\\Widgets');

    expect(fn (): array => CapellAdmin::getFilamentWidgets())->not->toThrow(Throwable::class);
});
