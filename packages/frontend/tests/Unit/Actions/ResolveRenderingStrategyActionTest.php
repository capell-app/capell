<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Frontend\Actions\ResolveRenderingStrategyAction;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Illuminate\Support\Facades\DB;

it('defaults a missing page to blade rendering', function (): void {
    expect(ResolveRenderingStrategyAction::run(null))->toBe(RenderingStrategyEnum::BladeOnly);
});

/**
 * @param  array<string, mixed>|null  $pageMeta
 * @param  array<string, mixed>|null  $blueprintMeta
 */
it('falls back through invalid and absent strategy metadata', function (
    ?array $pageMeta,
    ?array $blueprintMeta,
    RenderingStrategyEnum $expected,
): void {
    $page = new Page(['meta' => $pageMeta])->setRelation(
        'blueprint',
        new Blueprint(['is_livewire' => false, 'meta' => $blueprintMeta]),
    );

    expect(ResolveRenderingStrategyAction::run($page))->toBe($expected);
})->with([
    'absent metadata' => [null, null, RenderingStrategyEnum::BladeOnly],
    'invalid metadata' => [['rendering_strategy' => 'unknown'], ['rendering_strategy' => 'unknown'], RenderingStrategyEnum::BladeOnly],
    'invalid page metadata falls back to blueprint' => [['rendering_strategy' => 'unknown'], ['rendering_strategy' => 'livewire'], RenderingStrategyEnum::FullLivewire],
    'page metadata overrides invalid blueprint metadata' => [['rendering_strategy' => 'blade-islands'], ['rendering_strategy' => 'unknown'], RenderingStrategyEnum::BladeWithIslands],
]);

it('does not query a missing blueprint again', function (): void {
    $page = new Page(['blueprint_id' => null, 'meta' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect(ResolveRenderingStrategyAction::run($page))->toBe(RenderingStrategyEnum::BladeOnly)
            ->and(ResolveRenderingStrategyAction::run($page))->toBe(RenderingStrategyEnum::BladeOnly)
            ->and($page->relationLoaded('blueprint'))->toBeTrue()
            ->and($page->getRelation('blueprint'))->toBeNull()
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});
