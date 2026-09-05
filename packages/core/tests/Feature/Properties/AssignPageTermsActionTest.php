<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\AssignPageTermsAction;
use Capell\Core\EventSourcing\Events\PageRevisionRecorded;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('replaces assignments with deterministic pivot positions and records the page save lifecycle', function (): void {
    $page = Page::factory()->create();
    Translation::factory()->translatable($page)->language(Language::factory()->create())->create();
    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id]);
    $first = Term::factory()->for($taxonomy)->create(['slug' => 'first']);
    $second = Term::factory()->for($taxonomy)->create(['slug' => 'second']);

    AssignPageTermsAction::run($page, [$second->id, $first->id]);

    expect($page->fresh()?->terms()->pluck('terms.id')->all())->toBe([$second->id, $first->id])
        ->and(DB::table('page_term')->where('page_id', $page->id)->where('term_id', $second->id)->value('position'))->toBe(0)
        ->and(DB::table('page_term')->where('page_id', $page->id)->where('term_id', $first->id)->value('position'))->toBe(1)
        ->and(DB::table('stored_events')->where('aggregate_uuid', $page->uuid)->where('event_class', PageRevisionRecorded::class)->count())
        ->toBeGreaterThanOrEqual(1);
});

it('rejects terms from another site without changing existing assignments', function (): void {
    $page = Page::factory()->create();
    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id]);
    $existing = Term::factory()->for($taxonomy)->create();
    $foreignSite = Site::factory()->create();
    $foreignTaxonomy = Taxonomy::factory()->create(['site_id' => $foreignSite->id]);
    $foreign = Term::factory()->for($foreignTaxonomy)->create();

    AssignPageTermsAction::run($page, [$existing->id]);

    expect(fn (): mixed => AssignPageTermsAction::run($page, [$foreign->id]))
        ->toThrow(ValidationException::class)
        ->and($page->fresh()?->terms()->pluck('terms.id')->all())->toBe([$existing->id]);
});

it('allows an empty list to clear all assignments', function (): void {
    $page = Page::factory()->create();
    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id]);
    $term = Term::factory()->for($taxonomy)->create();

    AssignPageTermsAction::run($page, [$term->id]);
    AssignPageTermsAction::run($page, []);

    expect($page->fresh()?->terms()->exists())->toBeFalse();
});

it('rejects duplicate or malformed identifiers', function (mixed $termIds): void {
    $page = Page::factory()->create();

    expect(fn (): mixed => AssignPageTermsAction::run($page, $termIds))
        ->toThrow(ValidationException::class);
})->with([
    'duplicate' => [[1, 1]],
    'zero' => [[0]],
    'malformed' => [['term-id']],
]);
