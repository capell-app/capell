<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\PageCreationRecoveryAction;
use Capell\Admin\Actions\Pages\SavePageEditorScratchDraftAction;
use Capell\Admin\Data\Pages\PageEditorScratchDraftInputData;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(CreatesAdminUser::class)->group('page');

it('serialises editor scratch saves behind the agent page lock', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'capell_editor_race_test') {
        $this->markTestSkipped('Requires the isolated MariaDB concurrency database.');
    }

    // Trigger test migrations before switching to separately committed connections.
    DB::connection()->getSchemaBuilder()->hasTable('pages');
    $previous = DB::getDefaultConnection();
    $config = config('database.connections.' . $previous);
    config()->set('database.connections.recovery_writer', $config);
    config()->set('database.connections.recovery_agent', $config);
    DB::setDefaultConnection('recovery_writer');
    $agent = DB::connection('recovery_agent');
    try {
        $user = test()->actingAsUser()->authenticatedUser();
        Gate::before(static fn (): bool => true);
        $page = Page::factory()->withTranslations()->createOne();
        $context = 'page-editor:' . Str::uuid();
        $input = new PageEditorScratchDraftInputData($page, $user, app()->getLocale(), ['name' => 'Concurrent'], $context);
        DB::connection()->statement('SET SESSION innodb_lock_wait_timeout=1');
        $agent->beginTransaction();
        $agent->table('pages')->where('id', $page->id)->lockForUpdate()->first();
        expect(fn (): mixed => SavePageEditorScratchDraftAction::run($input))->toThrow(QueryException::class)
            ->and(EditorScratchDraft::query()->forEditor($user, $page, app()->getLocale(), $context)->exists())->toBeFalse();
        $agent->rollBack();
        expect(SavePageEditorScratchDraftAction::run($input)->wasSaved())->toBeTrue()
            ->and(EditorScratchDraft::query()->forEditor($user, $page, app()->getLocale(), $context)->sole()->payload)->toBe(['name' => 'Concurrent']);
    } finally {
        if ($agent->transactionLevel() > 0) {
            $agent->rollBack();
        }

        DB::setDefaultConnection($previous);
        DB::purge('recovery_writer');
        DB::purge('recovery_agent');
    }
});

it('serialises first-page recovery writes behind the site deletion lock', function (bool $deleteSite): void {
    if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'capell_editor_race_test') {
        $this->markTestSkipped('Requires the isolated MariaDB concurrency database.');
    }

    DB::connection()->getSchemaBuilder()->hasTable('sites');
    $previous = DB::getDefaultConnection();
    $config = config('database.connections.' . $previous);
    config()->set('database.connections.recovery_site_writer', $config);
    config()->set('database.connections.recovery_site_deleter', $config);
    DB::setDefaultConnection('recovery_site_writer');
    $deleter = DB::connection('recovery_site_deleter');
    try {
        $user = test()->actingAsUser()->authenticatedUser();
        Gate::before(static fn (): bool => true);
        $site = Site::factory()->createOne();
        $user->assignedSiteIds = collect([(int) $site->id]);
        $context = 'page-create:' . Str::uuid();
        DB::connection()->statement('SET SESSION innodb_lock_wait_timeout=1');
        $deleter->beginTransaction();
        $deleter->table('sites')->where('id', $site->id)->lockForUpdate()->first();
        $store = resolve(PageCreationRecoveryAction::class);
        expect(fn () => $store->save($user, $site->id, 'en', $context, null, ['name' => 'New']))->toThrow(QueryException::class)
            ->and(EditorScratchDraft::query()->where('context', $context)->exists())->toBeFalse();
        if ($deleteSite) {
            $deleter->table('sites')->where('id', $site->id)->delete();
            $deleter->commit();
            expect($store->save($user, $site->id, 'en', $context, null, ['name' => 'New']))->toBeNull()
                ->and(EditorScratchDraft::query()->where('context', $context)->exists())->toBeFalse();
        } else {
            $deleter->rollBack();
            expect($store->save($user, $site->id, 'en', $context, null, ['name' => 'New']))->not->toBeNull()
                ->and(EditorScratchDraft::query()->where('context', $context)->sole()->payload)->toBe(['name' => 'New']);
        }
    } finally {
        if ($deleter->transactionLevel() > 0) {
            $deleter->rollBack();
        }

        DB::setDefaultConnection($previous);
        DB::purge('recovery_site_writer');
        DB::purge('recovery_site_deleter');
    }
})->with([false, true]);
