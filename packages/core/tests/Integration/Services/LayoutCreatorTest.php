<?php

declare(strict_types=1);

use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Enums\LayoutGroupEnum;
use Capell\Core\Models\Layout;
use Capell\Core\Support\Creator\LayoutCreator;

it('creates all layouts for each LayoutEnum case via setup', function (): void {
    $creator = resolve(LayoutCreator::class);
    $creator->setup();

    $layoutKeys = LayoutEnum::cases();
    $layouts = Layout::query()->pluck('key')->all();

    $expectedKeys = array_map(fn (LayoutEnum $case) => $case->value, $layoutKeys);

    expect($layouts)->toMatchArray($expectedKeys);

    foreach ($layoutKeys as $case) {
        $layout = expectPresent(Layout::query()->where('key', $case->value)->first());
        expect($layout)->not()->toBeNull()
            ->and($layout->key)->toBe($case->value)
            ->and($layout->name)->not()->toBeEmpty();
    }
});

it('creates layouts with translated names via setup', function (): void {
    $creator = resolve(LayoutCreator::class);
    $creator->setup();

    expect(Layout::query()->pluck('name', 'key')->all())->toMatchArray([
        'default' => 'Default',
        'home' => 'Home',
        'results' => 'Results',
    ]);
});

it('creates layouts with descriptions for admin and docs', function (): void {
    $creator = resolve(LayoutCreator::class);
    $creator->setup();

    expect(Layout::query()->pluck('meta', 'key')->all())->toMatchArray([
        'default' => [
            'description' => 'A general-purpose layout for standard pages and content-led views.',
        ],
        'home' => [
            'description' => 'A homepage layout for the main site entry point and high-level content.',
        ],
        'results' => [
            'description' => 'A listing layout for search results, indexes, and grouped content.',
        ],
        'system' => [
            'description' => 'A locked layout for fixed system pages that should not use the page builder.',
        ],
    ]);
});

it('does not write missing translation keys as custom layout descriptions', function (): void {
    $layout = resolve(LayoutCreator::class)->create('article');

    expect($layout->meta)->toBe([])
        ->and($layout->group)->toBe(LayoutGroupEnum::Default);
});

it('creates the home layout without theme-owned element state', function (): void {
    $creator = resolve(LayoutCreator::class);
    $creator->setup();

    $homeLayout = Layout::query()->where('key', LayoutEnum::Home->value)->firstOrFail();

    expect($homeLayout->containers)->toBeNull()
        ->and($homeLayout->elements)->toBeEmpty();
});
