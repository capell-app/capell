<?php

declare(strict_types=1);

use Capell\Admin\Actions\ValidateForceDeleteAction;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Pages\RecentlyDeletedPage;
use Capell\Core\Actions\HasRetainedDeletionDependenciesAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

it('protects retained dependencies even when a component validator allows deletion', function (string $type): void {
    test()->actingAsAdmin();
    [$record, $dependent] = match ($type) {
        'layout' => [$layout = Layout::factory()->createOne(), Page::factory()->createOne(['layout_id' => $layout->id])],
        'theme' => [$theme = Theme::factory()->createOne(), Site::factory()->createOne(['theme_id' => $theme->id])],
        'blueprint' => [$blueprint = Blueprint::factory()->page()->createOne(), Page::factory()->createOne(['blueprint_id' => $blueprint->id])],
        'language' => [$language = Language::factory()->createOne(), Site::factory()->createOne(['language_id' => $language->id])],
        'page' => [$page = Page::factory()->createOne(), Page::factory()->canonicalPage($page)->createOne()],
        default => throw new InvalidArgumentException('Unknown deletion dependency fixture: ' . $type),
    };
    $dependent->delete();
    $record->delete();

    $validator = new class implements ValidatesDelete
    {
        #[Override]
        public function validateDelete(Model $record): bool
        {
            return true;
        }
    };

    expect(ValidateForceDeleteAction::run($record, $validator))->toBeFalse();
})->with(['layout', 'theme', 'blueprint', 'language', 'page']);

it('allows deleting an empty site with a non-expiring orphan page draft', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $draft = EditorScratchDraft::query()->create([
        'site_id' => $site->id, 'user_id' => test()->authenticatedUser()->id,
        'locale' => 'en', 'record_type' => (new Page)->getMorphClass(), 'record_id' => 999999,
        'context' => 'page-editor', 'payload' => [], 'content_hash' => hash('sha256', 'orphan'),
        'saved_at' => now(), 'expires_at' => null,
    ]);

    expect($site->pages()->withTrashed()->exists())->toBeFalse()
        ->and($draft->expires_at)->toBeNull()
        ->and(HasRetainedDeletionDependenciesAction::run($site))->toBeFalse()
        ->and(ValidateForceDeleteAction::run($site, null))->toBeTrue();
});

it('retains drafts referencing an existing page including trash', function (bool $trashed): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    // A legacy draft can retain a site after its page has moved to another site.
    $page = Page::factory()->createOne();
    if ($trashed) {
        $page->delete();
    }

    EditorScratchDraft::query()->create([
        'site_id' => $site->id, 'user_id' => test()->authenticatedUser()->id,
        'locale' => 'en', 'record_type' => $page->getMorphClass(), 'record_id' => $page->id,
        'context' => 'page-editor', 'payload' => [], 'content_hash' => hash('sha256', 'retained'),
        'saved_at' => now(), 'expires_at' => null,
    ]);

    expect($site->pages()->withTrashed()->exists())->toBeFalse()
        ->and(HasRetainedDeletionDependenciesAction::run($site))->toBeTrue()
        ->and(ValidateForceDeleteAction::run($site, null))->toBeFalse();
})->with(['active' => false, 'trashed' => true]);

it('explains that descendants must be removed before permanently deleting their parent', function (): void {
    test()->actingAsAdmin();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $parent->delete();

    Livewire::test(RecentlyDeletedPage::class)
        ->call('forceDeleteRecord', 'page', (int) $parent->id)
        ->assertNotified(Notification::make('page_descendants_not_deletable')->warning()
            ->title(__('capell-admin::message.page_not_deletable'))
            ->body(__('capell-admin::message.page_descendants_not_deletable_info')));

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id])->count())->toBe(2)
        ->and(__('capell-admin::message.page_descendants_not_deletable_info'))->toBe('This page has children. Permanently delete the children first.');
});
