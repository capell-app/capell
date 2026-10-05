<?php

declare(strict_types=1);

use Capell\Admin\Actions\RestorePageCascadeAction;
use Capell\Core\Models\Page;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

afterEach(function (): void {
    Date::use(Carbon::class);
});

it('restores the authorised page cascade with the configured date class', function (bool $immutable): void {
    $dateClass = $immutable ? CarbonImmutable::class : Carbon::class;
    Date::use($dateClass);
    $this->freezeTime();
    $this->actingAsAdmin();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $grandchild = Page::factory()->parent($child)->createOne();
    $sibling = Page::factory()->parent($parent)->createOne();
    $independent = Page::factory()->parent($parent)->createOne();
    $independent->delete();
    $this->travel(1)->seconds();
    $parent->refresh()->delete();
    $ids = [$parent->id, $child->id, $grandchild->id, $sibling->id];

    expect($parent->fresh()->deleted_at)->toBeInstanceOf($dateClass)
        ->and(Page::onlyTrashed()->whereKey([...$ids, $independent->id])->count())->toBe(5)
        ->and(RestorePageCascadeAction::run($parent))->toBeTrue()
        ->and(Page::onlyTrashed()->whereKey($ids)->count())->toBe(0)
        ->and($independent->fresh()->trashed())->toBeTrue()
        ->and(Page::isBroken())->toBeFalse();
})->with([
    'mutable dates' => false,
    'immutable dates' => true,
]);
