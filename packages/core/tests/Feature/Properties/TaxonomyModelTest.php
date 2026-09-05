<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Illuminate\Database\QueryException;

it('enforces a site-scoped unique taxonomy key', function (): void {
    $site = Site::factory()->create();
    Taxonomy::factory()->for($site)->create(['key' => 'brand']);

    expect(fn () => Taxonomy::factory()->for($site)->create(['key' => 'brand']))
        ->toThrow(QueryException::class);

    $otherSite = Site::factory()->create();
    $sameKeyOtherSite = Taxonomy::factory()->for($otherSite)->create(['key' => 'brand']);

    expect($sameKeyOtherSite->exists)->toBeTrue();
});

it('supports a hierarchy of terms via parent/children', function (): void {
    $taxonomy = Taxonomy::factory()->create(['hierarchical' => true]);
    $parent = Term::factory()->for($taxonomy)->create(['slug' => 'shoes']);
    $child = Term::factory()->for($taxonomy)->create(['slug' => 'running-shoes', 'parent_id' => $parent->id]);

    expect($parent->children()->pluck('id')->all())->toBe([$child->id])
        ->and($child->parent->id)->toBe($parent->id);
});

it('assigns terms to a page in a deterministic, position-ordered way', function (): void {
    $taxonomy = Taxonomy::factory()->create();
    $termA = Term::factory()->for($taxonomy)->create(['slug' => 'a']);
    $termB = Term::factory()->for($taxonomy)->create(['slug' => 'b']);
    $page = Page::factory()->create();

    $page->terms()->attach([
        $termB->id => ['position' => 1],
        $termA->id => ['position' => 0],
    ]);

    expect($page->terms()->pluck('slug')->all())->toBe(['a', 'b']);
});

it('enforces a unique taxonomy+slug pair', function (): void {
    $taxonomy = Taxonomy::factory()->create();
    Term::factory()->for($taxonomy)->create(['slug' => 'red']);

    expect(fn () => Term::factory()->for($taxonomy)->create(['slug' => 'red']))
        ->toThrow(QueryException::class);
});
