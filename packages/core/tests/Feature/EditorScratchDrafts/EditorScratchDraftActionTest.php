<?php

declare(strict_types=1);

use Capell\Core\Actions\EditorScratchDrafts\DiscardEditorScratchDraftAction;
use Capell\Core\Actions\EditorScratchDrafts\PruneEditorScratchDraftsAction;
use Capell\Core\Actions\EditorScratchDrafts\SaveEditorScratchDraftAction;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Capell\Tests\Fixtures\Models\User as FixtureUser;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Date;

it('upserts a user-scoped recovery draft without changing the page', function (): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, User::class));
    assert(is_a($userModel, FixtureUser::class, true));

    $editor = $userModel::factory()->createOne();
    $page = Page::factory()->createOne(['name' => 'Published source']);

    Date::setTestNow('2026-08-13 12:00:00');

    $draft = SaveEditorScratchDraftAction::run(
        record: $page,
        user: $editor,
        locale: 'en',
        context: 'page-editor',
        payload: ['name' => 'Recovered name'],
        ttlMinutes: 60,
    );

    $updated = SaveEditorScratchDraftAction::run(
        record: $page,
        user: $editor,
        locale: 'en',
        context: 'page-editor',
        payload: ['name' => 'Recovered name 2'],
        ttlMinutes: 120,
    );

    expect($updated->getKey())->toBe($draft->getKey())
        ->and($updated->payload)->toBe(['name' => 'Recovered name 2'])
        ->and($updated->expires_at->toDateTimeString())->toBe('2026-08-13 14:00:00')
        ->and(EditorScratchDraft::query()->count())->toBe(1)
        ->and($page->refresh()->name)->toBe('Published source');

    Date::setTestNow();
});

it('isolates recovery drafts by editor and removes explicit and expired drafts', function (): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, User::class));
    assert(is_a($userModel, FixtureUser::class, true));

    $editor = $userModel::factory()->createOne();
    $otherEditor = $userModel::factory()->createOne();
    $page = Page::factory()->createOne();

    Date::setTestNow('2026-08-13 12:00:00');

    SaveEditorScratchDraftAction::run($page, $editor, 'en', 'page-editor', ['name' => 'Editor draft'], 30);
    SaveEditorScratchDraftAction::run($page, $otherEditor, 'en', 'page-editor', ['name' => 'Other draft'], 30);

    expect(EditorScratchDraft::query()->forEditor($editor, $page, 'en', 'page-editor')->count())->toBe(1)
        ->and(EditorScratchDraft::query()->forEditor($otherEditor, $page, 'en', 'page-editor')->count())->toBe(1);

    DiscardEditorScratchDraftAction::run($page, $editor, 'en', 'page-editor');

    expect(EditorScratchDraft::query()->forEditor($editor, $page, 'en', 'page-editor')->count())->toBe(0)
        ->and(EditorScratchDraft::query()->forEditor($otherEditor, $page, 'en', 'page-editor')->count())->toBe(1);

    Date::setTestNow('2026-08-13 12:31:00');
    expect(PruneEditorScratchDraftsAction::run())->toBe(1)
        ->and(EditorScratchDraft::query()->count())->toBe(0);

    Date::setTestNow();
});
