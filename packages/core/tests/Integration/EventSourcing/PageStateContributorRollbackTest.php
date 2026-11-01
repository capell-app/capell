<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Contracts\PageStateContributor;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\Actions\BuildRollbackPreviewAction;
use Capell\Core\EventSourcing\Rollback\RollbackChangeType;
use Capell\Core\EventSourcing\Rollback\RollbackFieldChangeData;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Term;
use Capell\Core\Models\Translation;
use Capell\Core\Tests\Support\PageNoteStateContributor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create(PageNoteStateContributor::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('page_id')->index();
        $table->string('body');
        $table->unsignedInteger('position');
    });

    app()->tag([PageNoteStateContributor::class], PageStateContributor::TAG);
});

afterEach(function (): void {
    Schema::dropIfExists(PageNoteStateContributor::TABLE);
});

function contributorTestPage(): Page
{
    $page = Page::factory()->create();
    Translation::factory()->translatable($page)->language(Language::factory()->create())
        ->create(['title' => 'Title', 'content' => '<p>content</p>']);

    return $page;
}

function replacePageNotes(Page $page, array $bodies): void
{
    DB::table(PageNoteStateContributor::TABLE)->where('page_id', $page->id)->delete();

    foreach ($bodies as $position => $body) {
        DB::table(PageNoteStateContributor::TABLE)->insert(['page_id' => $page->id, 'body' => $body, 'position' => $position]);
    }

    $page->load(['translations', 'pageUrls']);
    $page->save();
}

function pageNotes(Page $page): array
{
    return DB::table(PageNoteStateContributor::TABLE)->where('page_id', $page->id)->orderBy('position')->pluck('body')->all();
}

it('rolls back package-owned page state contributed to the page snapshot', function (): void {
    $page = contributorTestPage();
    replacePageNotes($page, ['Hero', 'Features']);
    $targetVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    replacePageNotes($page->fresh(), ['Pricing']);

    $preview = BuildRollbackPreviewAction::run($page->fresh(), $targetVersion);
    $notesChange = collect($preview->fields)->first(fn (RollbackFieldChangeData $change): bool => $change->path === 'fixtureNotes');

    expect($notesChange)->not->toBeNull()
        ->and($notesChange->changeType)->toBe(RollbackChangeType::Modified);

    ApplyRollbackAction::run($page->fresh(), $targetVersion);

    expect(pageNotes($page))->toBe(['Hero', 'Features']);
});

it('leaves contributed state untouched when restoring a snapshot captured before the contributor existed', function (): void {
    $page = contributorTestPage();
    replacePageNotes($page, ['Current']);

    $legacyState = resolve(PageStateSerializer::class)->capture($page->fresh());
    unset($legacyState['fixtureNotes']);

    resolve(PageStateSerializer::class)->restore($page->fresh(), $legacyState);

    expect(pageNotes($page))->toBe(['Current']);
});

it('does not report contributed state as removed when previewing a legacy snapshot', function (): void {
    $page = contributorTestPage();
    DB::table(PageNoteStateContributor::TABLE)->insert(['page_id' => $page->id, 'body' => 'Current', 'position' => 0]);

    $current = resolve(PageStateSerializer::class)->capture($page->fresh());
    $legacyTarget = $current;
    unset($legacyTarget['fixtureNotes']);

    $paths = array_map(
        static fn (RollbackFieldChangeData $change): string => $change->path,
        resolve(RollbackService::class)->diffStates($current, $legacyTarget),
    );

    expect($paths)->not->toContain('fixtureNotes');
});

it('refuses a contributor whose key collides with a core snapshot section', function (): void {
    $contributor = new class implements PageStateContributor
    {
        public function key(): string
        {
            return 'translations';
        }

        public function capture(Page $page): array
        {
            return [];
        }

        public function restore(Page $page, array $state): void {}
    };

    app()->instance('colliding-contributor', $contributor);
    app()->tag(['colliding-contributor'], PageStateContributor::TAG);

    resolve(PageStateSerializer::class)->capture(contributorTestPage());
})->throws(LogicException::class);

it('rolls back the taxonomy terms assigned to a page', function (): void {
    $page = contributorTestPage();
    [$news, $guides, $events] = Term::factory()->count(3)->create()->all();

    $page->terms()->sync([$news->id => ['position' => 0], $guides->id => ['position' => 1]]);
    replacePageNotes($page, []);
    $targetVersion = resolve(RollbackService::class)->currentVersion((string) $page->uuid);

    $page->terms()->sync([$events->id => ['position' => 0]]);
    replacePageNotes($page->refresh(), []);

    ApplyRollbackAction::run($page->refresh(), $targetVersion);

    expect($page->terms()->pluck('terms.id')->all())->toBe([$news->id, $guides->id]);
});

it('keeps current property values and terms when a revision predates their capture', function (): void {
    $page = contributorTestPage();
    $term = Term::factory()->create();
    $page->terms()->sync([$term->id => ['position' => 0]]);

    $legacyState = resolve(PageStateSerializer::class)->capture($page->refresh());
    unset($legacyState['terms'], $legacyState['propertyValues']);

    resolve(PageStateSerializer::class)->restore($page->refresh(), $legacyState);

    expect($page->terms()->pluck('terms.id')->all())->toBe([$term->id]);
});

it('warns when a revision assigns terms that no longer exist', function (): void {
    $page = contributorTestPage();
    $term = Term::factory()->create();
    $page->terms()->sync([$term->id => ['position' => 0]]);
    replacePageNotes($page, []);
    $targetVersion = resolve(RollbackService::class)->currentVersion((string) $page->uuid);

    $term->delete();

    $preview = BuildRollbackPreviewAction::run($page->refresh(), $targetVersion);

    expect(collect($preview->issues)->pluck('code')->all())->toContain('missing_terms')
        ->and($preview->isBlocked())->toBeFalse();
});
