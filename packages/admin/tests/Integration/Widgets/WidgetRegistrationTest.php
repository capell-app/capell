<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Widgets\CardsFilamentWidget;
use Capell\Admin\Filament\Widgets\ContentFilamentWidget;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Admin\Tests\Fixtures\Widgets\AlternateHeroWidget;
use Capell\Admin\Tests\Fixtures\Widgets\HeroWidget;
use Filament\Forms\Components\Builder\Block;

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

it('registerDiscoverableWidgets silently skips a non-existent directory', function (): void {
    CapellAdmin::registerDiscoverableWidgets('/non/existent/path', 'App\\Widgets');

    expect(fn (): array => CapellAdmin::getFilamentWidgets())->not->toThrow(Throwable::class);
});
