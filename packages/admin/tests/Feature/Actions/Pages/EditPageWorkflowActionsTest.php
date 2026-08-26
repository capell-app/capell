<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BuildPageEditorSessionAction;
use Capell\Admin\Actions\Pages\DiscardPageEditorScratchDraftAction;
use Capell\Admin\Actions\Pages\RecordPageUrlRedirectsAction;
use Capell\Admin\Actions\Pages\ResolvePageEditorLockAction;
use Capell\Admin\Actions\Pages\SavePageAuthoringAction;
use Capell\Admin\Actions\Pages\SavePageEditorScratchDraftAction;
use Capell\Admin\Data\Pages\PageAuthoringInputData;
use Capell\Admin\Data\Pages\PageEditorLockRequestData;
use Capell\Admin\Data\Pages\PageEditorScratchDraftInputData;
use Capell\Admin\Data\Pages\PageUrlRedirectRequestData;
use Capell\Admin\Enums\PageEditorLockOperation;
use Capell\Admin\Enums\PageEditorLockStatus;
use Capell\Admin\Enums\PageEditorScratchDraftStatus;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    Date::setTestNow();
});

it('records only confirmed page url changes with one language lookup', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();
    $otherLanguage = Language::factory()->createOne();
    $recorded = [];
    $languageQueries = 0;

    $recorder = new class implements RedirectUrlRecorder
    {
        /** @var list<array{page_id: int, language_id: int, url: string}> */
        private array $recorded = [];

        public function record(Pageable $pageable, Language $language, string $url): void
        {
            assert($pageable instanceof Page);

            $this->recorded[] = [
                'page_id' => (int) $pageable->getKey(),
                'language_id' => (int) $language->getKey(),
                'url' => $url,
            ];
        }

        /** @return list<array{page_id: int, language_id: int, url: string}> */
        public function recorded(): array
        {
            return $this->recorded;
        }
    };

    app()->instance(RedirectUrlRecorder::class, $recorder);

    DB::listen(function (QueryExecuted $query) use (&$languageQueries): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'languages')) {
            $languageQueries++;
        }
    });

    $result = RecordPageUrlRedirectsAction::run(new PageUrlRedirectRequestData(
        page: $page,
        submittedUrls: [
            $language->getKey() => '/old-page',
            $otherLanguage->getKey() => '/tampered',
        ],
        expectedUrls: [
            $language->getKey() => '/old-page',
            $otherLanguage->getKey() => '/different-page',
        ],
    ));

    expect($result)
        ->acceptedCount->toBe(1)
        ->recordedCount->toBe(1)
        ->and($recorder->recorded())->toBe([[
            'page_id' => (int) $page->getKey(),
            'language_id' => (int) $language->getKey(),
            'url' => '/old-page',
        ]])
        ->and($languageQueries)->toBe(1);
});

it('keeps page saved processing ahead of redirect recording', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();
    $recorder = new class implements RedirectUrlRecorder
    {
        /** @var list<string> */
        private array $order = [];

        public function markPageSaved(): void
        {
            $this->order[] = 'page-saved';
        }

        public function record(Pageable $pageable, Language $language, string $url): void
        {
            $this->order[] = 'redirect-recorded';
        }

        /** @return list<string> */
        public function order(): array
        {
            return $this->order;
        }
    };

    Event::listen(PageSaved::class, function () use ($recorder): void {
        $recorder->markPageSaved();
    });

    app()->instance(RedirectUrlRecorder::class, $recorder);

    $result = SavePageAuthoringAction::run(new PageAuthoringInputData(
        page: $page,
        formData: ['name' => 'Ordered page save'],
        previousUrls: [$language->getKey() => '/old-page'],
        recordRedirects: true,
    ));

    expect($result->redirectsRecorded)->toBe(1)
        ->and($recorder->order())->toBe(['page-saved', 'redirect-recorded']);
});

it('returns typed lock decisions without adding writes to the conflict path', function (): void {
    Date::setTestNow('2026-08-21 10:00:00');

    $page = Page::factory()->createOne();
    $editor = test()->actingAsAdmin()->authenticatedUser();
    $owner = test()->createUser(['name' => 'Lock owner']);

    ContentLock::query()->create([
        'user_id' => $owner->getAuthIdentifier(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => Date::now()->addMinutes(15),
    ]);

    $lockWrites = 0;

    DB::listen(function (QueryExecuted $query) use (&$lockWrites): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'content_locks') && preg_match('/^(insert|update|delete)/', $sql) === 1) {
            $lockWrites++;
        }
    });

    $result = ResolvePageEditorLockAction::run(new PageEditorLockRequestData(
        record: $page,
        user: $editor,
        operation: PageEditorLockOperation::Save,
    ));

    expect($result->status)->toBe(PageEditorLockStatus::Conflict)
        ->and($result->isBlocked())->toBeTrue()
        ->and($result->owner())->toBeInstanceOf(Authenticatable::class)
        ->and($result->owner()?->getAuthIdentifier())->toBe($owner->getAuthIdentifier())
        ->and($lockWrites)->toBe(0);
});

it('owns the lock for open save and takeover operations', function (string $operationValue): void {
    Date::setTestNow('2026-08-21 10:00:00');

    $page = Page::factory()->createOne();
    $editor = test()->actingAsAdmin()->authenticatedUser();
    $operation = PageEditorLockOperation::from($operationValue);

    if ($operation === PageEditorLockOperation::TakeOver) {
        $owner = test()->createUser();

        ContentLock::query()->create([
            'user_id' => $owner->getAuthIdentifier(),
            'model_type' => $page->getMorphClass(),
            'model_id' => $page->getKey(),
            'expires_at' => Date::now()->addMinutes(15),
        ]);
    }

    $result = ResolvePageEditorLockAction::run(new PageEditorLockRequestData(
        record: $page,
        user: $editor,
        operation: $operation,
    ));

    expect($result->status)->toBe(PageEditorLockStatus::Owned)
        ->and($result->isBlocked())->toBeFalse()
        ->and($result->lock?->user_id)->toBe($editor->getAuthIdentifier());
})->with([
    'open' => 'open',
    'save' => 'save',
    'takeover' => 'takeover',
]);

it('builds raw editor session configuration without translated presentation', function (): void {
    $page = Page::factory()->createOne();
    $editor = test()->actingAsAdmin()->authenticatedUser();

    $session = BuildPageEditorSessionAction::run(
        page: $page,
        user: $editor,
        locale: 'fr',
        heartbeatUrl: '/admin/pages/1/content-lock/heartbeat',
        releaseUrl: '/admin/pages/1/content-lock/release',
        logoutUrl: '/admin/logout',
        csrfToken: 'csrf-token',
        initialConflict: true,
    );

    expect($session->configuration())->toBe([
        'heartbeatUrl' => '/admin/pages/1/content-lock/heartbeat',
        'releaseUrl' => '/admin/pages/1/content-lock/release',
        'logoutUrl' => '/admin/logout',
        'csrfToken' => 'csrf-token',
        'intervalMs' => 30000,
        'initialConflict' => true,
        'pageId' => (int) $page->getKey(),
        'storageKey' => sprintf('capell:page-editor:%s:%s:fr', $editor->getAuthIdentifier(), $page->getKey()),
        'formSelector' => '#form',
        'localDraftDebounceMs' => 750,
        'localDraftTtlMs' => 86_400_000,
        'localDraftVersion' => 1,
    ]);
});

it('saves and discards editor scratch drafts through typed outcomes', function (): void {
    $page = Page::factory()->createOne();
    $editor = test()->actingAsAdmin()->authenticatedUser();

    $saved = SavePageEditorScratchDraftAction::run(new PageEditorScratchDraftInputData(
        page: $page,
        user: $editor,
        locale: 'en',
        payload: ['name' => 'Recovered page name'],
    ));

    expect($saved->status)->toBe(PageEditorScratchDraftStatus::Saved)
        ->and($saved->affectedRows)->toBe(1)
        ->and(EditorScratchDraft::query()->sole()->payload)->toBe(['name' => 'Recovered page name']);

    $discarded = DiscardPageEditorScratchDraftAction::run(
        page: $page,
        user: $editor,
        locale: 'en',
    );

    expect($discarded->status)->toBe(PageEditorScratchDraftStatus::Discarded)
        ->and($discarded->affectedRows)->toBe(1)
        ->and(EditorScratchDraft::query()->count())->toBe(0);
});

it('does not write a scratch draft while another editor owns the page lock', function (): void {
    Date::setTestNow('2026-08-21 10:00:00');

    $page = Page::factory()->createOne();
    $editor = test()->actingAsAdmin()->authenticatedUser();
    $owner = test()->createUser();

    ContentLock::query()->create([
        'user_id' => $owner->getAuthIdentifier(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => Date::now()->addMinutes(15),
    ]);

    $result = SavePageEditorScratchDraftAction::run(new PageEditorScratchDraftInputData(
        page: $page,
        user: $editor,
        locale: 'en',
        payload: ['name' => 'Blocked draft'],
    ));

    expect($result->status)->toBe(PageEditorScratchDraftStatus::Locked)
        ->and($result->affectedRows)->toBe(0)
        ->and(EditorScratchDraft::query()->count())->toBe(0);
});
