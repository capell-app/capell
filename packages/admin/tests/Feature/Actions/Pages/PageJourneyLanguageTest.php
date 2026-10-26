<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\ResolvePageJourneyLanguageAction;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(CreatesAdminUser::class)->group('page');

it('prepares two language membership once per site for fifty row and edit callbacks', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $sites = collect(range(1, 2))->map(fn () => Site::factory()->language($english)->withTranslations([$english, $french])->createOne());

    test()->actingAs(ScopedAdminUser::make($sites->pluck('id')));
    DB::enableQueryLog();
    DB::flushQueryLog();
    foreach ($sites as $site) {
        for ($callback = 0; $callback < 50; $callback++) {
            expect(ResolvePageJourneyLanguageAction::run($site->id, $callback % 2 === 0 ? $english->id : $french->id)?->id)
                ->toBe($callback % 2 === 0 ? $english->id : $french->id);
        }
    }

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(8);
});

it('keeps cached membership inside the current actor site and team boundary', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $first = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $second = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $actor = ScopedAdminUser::make(collect([$first->id]));
    test()->actingAs($actor);

    expect(ResolvePageJourneyLanguageAction::run($first->id, $french->id)?->id)->toBe($french->id)
        ->and(ResolvePageJourneyLanguageAction::run($second->id, $french->id))->toBeNull();

    test()->actingAs(ScopedAdminUser::make(collect([$second->id])));
    expect(ResolvePageJourneyLanguageAction::run($first->id, $french->id))->toBeNull()
        ->and(ResolvePageJourneyLanguageAction::run($second->id, $french->id)?->id)->toBe($french->id);

    test()->actingAs($actor);
    expect(ResolvePageJourneyLanguageAction::run($first->id, $french->id)?->id)->toBe($french->id);
    $actor->assignedSiteIds = collect();
    expect(ResolvePageJourneyLanguageAction::run($first->id, $french->id))->toBeNull();

    $actor->assignedSiteIds = collect([$first->id]);
    expect(ResolvePageJourneyLanguageAction::run($first->id, $french->id)?->id)->toBe($french->id);
    $first->siteDomains()->where('language_id', $french->id)->delete();
    $registrar = resolve(PermissionRegistrar::class);
    $previousTeam = $registrar->getPermissionsTeamId();
    try {
        $registrar->setPermissionsTeamId(456);
        expect(ResolvePageJourneyLanguageAction::run($first->id, $french->id))->toBeNull();
    } finally {
        $registrar->setPermissionsTeamId($previousTeam);
    }
});

it('reloads site language membership on the next request', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    expect(ResolvePageJourneyLanguageAction::run($site->id, $french->id)?->id)->toBe($french->id);
    $site->siteDomains()->where('language_id', $french->id)->delete();

    $previousRequest = request();
    try {
        app()->instance('request', Request::create('/admin/pages'));
        expect(ResolvePageJourneyLanguageAction::run($site->id, $french->id))->toBeNull();
    } finally {
        app()->instance('request', $previousRequest);
    }
});
