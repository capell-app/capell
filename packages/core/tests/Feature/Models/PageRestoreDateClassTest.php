<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    Date::useDefault();
});

it('restores recorded page descendants with the configured date class', function (bool $immutable, int $seconds): void {
    $dateClass = $immutable ? CarbonImmutable::class : Carbon::class;
    Date::use($dateClass);
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $grandchild = Page::factory()->parent($child)->createOne();
    $sibling = Page::factory()->parent($parent)->createOne();
    $independent = Page::factory()->parent($parent)->createOne();
    $independent->delete();
    $this->travel($seconds)->seconds();
    $parent->refresh()->delete();
    $ids = [$parent->id, $child->id, $grandchild->id, $sibling->id];
    $restoring = [];
    $restored = [];
    Event::listen('eloquent.restoring: ' . Page::class, static function (Page $page) use (&$restoring, $dateClass): void {
        expect($page->deleted_at)->toBeInstanceOf($dateClass);
        $restoring[] = $page->id;
    });
    Event::listen('eloquent.restored: ' . Page::class, static function (Page $page) use (&$restored): void {
        $restored[] = $page->id;
    });

    expect($parent->fresh()->deleted_at)->toBeInstanceOf($dateClass)
        ->and(Page::onlyTrashed()->whereKey([...$ids, $independent->id])->count())->toBe(5)
        ->and($parent->restore())->toBeTrue()
        ->and(Page::onlyTrashed()->whereKey($ids)->count())->toBe(0)
        ->and($independent->fresh()->trashed())->toBeTrue()
        ->and($parent->fresh()->created_at)->toBeInstanceOf($dateClass)
        ->and($restoring)->toEqualCanonicalizing($ids)
        ->and($restored)->toEqualCanonicalizing($ids)
        ->and(Page::isBroken())->toBeFalse();
})->with([
    'mutable dates' => false,
    'immutable dates' => true,
])->with([
    'separate deletion in the same second' => 0,
    'separate deletion in an earlier second' => 1,
]);
