<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\PageCreationRecoveryAction;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
    Language::factory()->createOne();
    Blueprint::factory()->page()->default()->createOne();
});

it('recovers incomplete creation data after a reload without creating a page', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $first = Livewire::test(CreatePage::class)->set('data.site_id', $site->id)->set('data.name', 'Unfinished');
    $draft = EditorScratchDraft::query()->where('context', $first->instance()->creationRecoveryContext)->sole();
    expect($draft->payload['name'])->toBe('Unfinished')
        ->and($draft->expires_at->diffInHours($draft->saved_at, true))->toBe(168.0)
        ->and(Page::query()->count())->toBe(0);
    $second = Livewire::test(CreatePage::class)->set('data.site_id', $site->id)
        ->assertSee(__('capell-admin::scratch_drafts.restore'));
    $second->call('restoreCreationRecovery', $draft->id, $draft->revision, $draft->content_hash)
        ->assertSet('data.name', 'Unfinished');
    expect($draft->fresh())->not->toBeNull();
    $second->call('discardCreationRecovery', $draft->id, $draft->revision, $draft->content_hash);
    expect($draft->fresh())->toBeNull();
});

it('does not expose or mutate creation recoveries to another user or site', function (): void {
    $user = test()->authenticatedUser();
    $site = Site::factory()->withTranslations()->createOne();
    $otherSite = Site::factory()->withTranslations()->createOne();
    $store = resolve(PageCreationRecoveryAction::class);
    $revision = $store->save($user, $site->id, 'en', 'page-create:privacy', null, ['name' => 'Private']);
    $draft = EditorScratchDraft::query()->where('context', 'page-create:privacy')->sole();
    $other = test()->createUser();
    expect($revision)->not->toBeNull()
        ->and($store->find($other, $site->id, 'en', 'different'))->toBeNull()
        ->and($store->find($user, $otherSite->id, 'en', 'different'))->toBeNull()
        ->and($store->discard($other, $site->id, 'en', $draft->id, $draft->revision, $draft->content_hash))->toBeFalse()
        ->and($draft->refresh()->payload)->toBe(['name' => 'Private']);
});

it('ignores expired creation recovery and refuses stale discard', function (): void {
    $user = test()->authenticatedUser();
    $site = Site::factory()->withTranslations()->createOne();
    $store = resolve(PageCreationRecoveryAction::class);
    $first = $store->save($user, $site->id, 'en', 'page-create:stale', null, ['name' => 'Before']);
    $draft = EditorScratchDraft::query()->where('context', 'page-create:stale')->sole();
    $hash = $draft->content_hash;
    $second = $store->save($user, $site->id, 'en', 'page-create:stale', $first, ['name' => 'After']);
    expect($store->discard($user, $site->id, 'en', $draft->id, $first, $hash))->toBeFalse()
        ->and($draft->refresh()->payload)->toBe(['name' => 'After'])
        ->and($second)->not->toBe($first);
    $draft->refresh()->update(['expires_at' => now()->subSecond()]);
    expect($store->find($user, $site->id, 'en', 'different'))->toBeNull();
});

it('warns and shows the current creation recovery when a displayed version is stale', function (string $action): void {
    $user = test()->authenticatedUser();
    $site = Site::factory()->withTranslations()->createOne();
    $store = resolve(PageCreationRecoveryAction::class);
    $revision = $store->save($user, $site->id, app()->getLocale(), 'page-create:other', null, ['name' => 'Before']);
    $draft = EditorScratchDraft::query()->where('context', 'page-create:other')->sole();
    $hash = $draft->content_hash;
    $component = Livewire::test(CreatePage::class)->set('data.site_id', $site->id)->set('data.name', 'Current form')
        ->assertSee($revision);

    $newRevision = $store->save($user, $site->id, app()->getLocale(), 'page-create:other', $revision, ['name' => 'After']);
    $component->call($action, $draft->id, $revision, $hash)
        ->assertNotified(__('capell-admin::scratch_drafts.recovery_changed'))
        ->assertSet('data.name', 'Current form')
        ->assertSee($newRevision);

    expect($draft->refresh()->payload)->toBe(['name' => 'After']);
})->with(['restoreCreationRecovery', 'discardCreationRecovery']);

it('denies a page creator access to a recovery for a site outside their assignment', function (): void {
    $owner = test()->authenticatedUser();
    $site = Site::factory()->withTranslations()->createOne();
    $otherSite = Site::factory()->withTranslations()->createOne();
    $store = resolve(PageCreationRecoveryAction::class);
    $store->save($owner, $site->id, 'en', 'page-create:scoped', null, ['name' => 'Private site']);

    $draft = EditorScratchDraft::query()->where('context', 'page-create:scoped')->sole();
    $scoped = ScopedAdminUser::make(collect([$otherSite->id]));
    $scoped->setAttribute('id', $owner->getAuthIdentifier());
    expect(Gate::forUser($scoped)->allows('create', Page::class))->toBeTrue()
        ->and($store->find($scoped, $site->id, 'en', 'other'))->toBeNull()
        ->and($store->discard($scoped, $site->id, 'en', $draft->id, $draft->revision, $draft->content_hash))->toBeFalse()
        ->and($draft->fresh())->not->toBeNull();
});

it('removes only the successful creation session and preserves earlier recoveries', function (): void {
    $language = Language::query()->firstOrFail();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $blueprint = Blueprint::query()->pageType()->default()->firstOrFail();
    $user = test()->authenticatedUser();
    $store = resolve(PageCreationRecoveryAction::class);
    $store->save($user, $site->id, app()->getLocale(), 'page-create:earlier', null, ['name' => 'Earlier']);

    session()->put('capell.current_site_id', $site->id);
    $component = Livewire::test(CreatePage::class)->set('data.translations', [])->fillForm([
        'name' => 'New page',
        'layout_id' => Layout::factory()->createOne()->id,
        'blueprint_id' => $blueprint->id,
        'translations' => [(string) Str::uuid() => ['language_id' => $language->id, 'title' => 'New page']],
    ])->set('data.name', 'New page');
    $context = $component->instance()->creationRecoveryContext;
    expect(EditorScratchDraft::query()->where('context', $context)->exists())->toBeTrue();
    $component->call('createAsDraft')->assertHasNoFormErrors();
    expect(Page::query()->where('name', 'New page')->exists())->toBeTrue()
        ->and(EditorScratchDraft::query()->where('context', $context)->exists())->toBeFalse()
        ->and(EditorScratchDraft::query()->where('context', 'page-create:earlier')->exists())->toBeTrue();
});

it('does not save a creation form to the default site after its site is cleared', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    session()->put('capell.current_site_id', $site->id);
    $component = Livewire::test(CreatePage::class)->set('data.site_id', $site->id)->set('data.name', 'Before clearing');
    $draft = EditorScratchDraft::query()->where('context', $component->instance()->creationRecoveryContext)->sole();
    $component->set('data.site_id')->set('data.name', 'Not assigned');
    expect($draft->refresh()->payload['name'])->toBe('Before clearing')
        ->and(EditorScratchDraft::query()->where('context', 'like', 'page-create:%')->count())->toBe(1);
});

it('reconciles a lost creation recovery response only for the same committed payload', function (): void {
    $user = test()->authenticatedUser();
    $site = Site::factory()->withTranslations()->createOne();
    $store = resolve(PageCreationRecoveryAction::class);
    $first = $store->save($user, $site->id, 'en', 'page-create:retry', null, ['name' => 'Saved']);
    $retry = $store->save($user, $site->id, 'en', 'page-create:retry', null, ['name' => 'Saved']);
    $different = $store->save($user, $site->id, 'en', 'page-create:retry', null, ['name' => 'Other']);
    expect($first)->not->toBeNull()->and($retry)->toBe($first)->and($different)->toBeNull();
});

it('offers the committed current creation draft when an older browser snapshot sends different edits', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $component = Livewire::test(CreatePage::class)->set('data.site_id', $site->id)->set('data.name', 'First');
    $olderSnapshot = $component->snapshot;
    $component->set('data.name', 'Committed');
    $draft = EditorScratchDraft::query()->where('context', $component->instance()->creationRecoveryContext)->sole();
    $component->snapshot = $olderSnapshot;
    $component->set('data.name', 'New unsaved')
        ->assertSet('creationRecoveryConflict', true)
        ->assertSee(__('capell-admin::scratch_drafts.restore'));
    expect($draft->refresh()->payload['name'])->toBe('Committed');
    $newContext = $component->instance()->creationRecoveryContext;
    expect(EditorScratchDraft::query()->where('context', $newContext)->sole()->payload['name'])->toBe('New unsaved');
    $component->call('restoreCreationRecovery', $draft->id, $draft->revision, $draft->content_hash)
        ->set('data.name', 'Continued');
    expect($draft->refresh()->payload['name'])->toBe('Committed')
        ->and(EditorScratchDraft::query()->where('context', $newContext)->sole()->payload['name'])->toBe('Continued');
});

it('starts a new creation recovery after its own expired row was pruned', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $component = Livewire::test(CreatePage::class)->set('data.site_id', $site->id)->set('data.name', 'First');
    $oldContext = $component->instance()->creationRecoveryContext;
    EditorScratchDraft::query()->where('context', $oldContext)->delete();
    $component->set('data.name', 'After pruning');
    $newContext = $component->instance()->creationRecoveryContext;
    expect($newContext)->not->toBe($oldContext)
        ->and(EditorScratchDraft::query()->where('context', $newContext)->sole()->payload['name'])->toBe('After pruning');
});
