<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Livewire\Page\Page as LivewirePage;

it('disables Livewire assets for blade only pages', function (): void {
    $page = Page::factory()->make(['meta' => null]);

    $component = new LivewirePage;
    $method = new ReflectionMethod($component, 'pageRecordRequiresLivewire');

    expect($method->invoke($component, $page))->toBeFalse();
});

it('enables Livewire assets for pages with islands', function (): void {
    $page = Page::factory()->make([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeWithIslands->value],
    ]);

    $component = new LivewirePage;
    $method = new ReflectionMethod($component, 'pageRecordRequiresLivewire');

    expect($method->invoke($component, $page))->toBeTrue();
});

it('enables Livewire assets for pages with a loaded type strategy', function (): void {
    $type = Blueprint::factory()->make([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeWithIslands->value],
    ]);
    $page = Page::factory()
        ->make(['meta' => null])
        ->setRelation('type', $type);

    $component = new LivewirePage;
    $method = new ReflectionMethod($component, 'pageRecordRequiresLivewire');

    expect($method->invoke($component, $page))->toBeTrue();
});

it('enables Livewire assets for loaded Livewire page types', function (): void {
    $type = Blueprint::factory()->make([
        'is_livewire' => true,
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeOnly->value],
    ]);
    $page = Page::factory()
        ->make(['meta' => null])
        ->setRelation('type', $type);

    $component = new LivewirePage;
    $method = new ReflectionMethod($component, 'pageRecordRequiresLivewire');

    expect($method->invoke($component, $page))->toBeTrue();
});

it('does not lazy load the page type while deciding Livewire assets', function (): void {
    $page = Page::factory()->make(['meta' => null]);

    $component = new LivewirePage;
    $method = new ReflectionMethod($component, 'pageRecordRequiresLivewire');

    expect($page->relationLoaded('type'))->toBeFalse()
        ->and($method->invoke($component, $page))->toBeFalse()
        ->and($page->relationLoaded('type'))->toBeFalse();
});
