<?php

declare(strict_types=1);

use Capell\Admin\Actions\ContentGraph\ValidateContentDeleteImpactAction;
use Capell\Admin\Actions\ValidateForceDeleteAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\Validate\LayoutValidation;
use Capell\Admin\Filament\Concerns\Validate\PageValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

it('builds each graph preview once when validating a large permanent-deletion selection', function (string $type): void {
    test()->actingAsUser();
    $records = $type === 'page' ? Page::factory()->count(100)->create() : Layout::factory()->count(100)->create();
    $records->each->delete();

    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = Site::query()->pluck('id');

    $resource = $type === 'page' ? ResourceEnum::Page : ResourceEnum::Layout;
    $actor->givePermissionTo(Permission::findOrCreate($resource->permission('force_delete'), 'web'));
    expect($actor->isGlobalAdmin())->toBeFalse();
    $validator = $type === 'page' ? new class implements ValidatesDelete
    {
        use PageValidation;
    } : new class implements ValidatesDelete
    {
        use LayoutValidation;
    };
    $previews = 0;
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$previews, &$queries): void {
        $queries++;
        if (str_contains($query->sql, 'content_graph_edges') && ! str_contains($query->sql, 'strength')) {
            $previews++;
        }
    });
    $started = hrtime(true);
    foreach ($records as $record) {
        expect(ValidateForceDeleteAction::run($record, $validator))->toBeTrue();
    }

    $metrics = ['type' => $type, 'records' => $records->count(), 'previews' => $previews, 'queries' => $queries, 'elapsed_ms' => (hrtime(true) - $started) / 1_000_000];
    $output = getenv('CAPELL_FORCE_DELETE_BENCHMARK_OUTPUT');
    if (is_string($output) && $output !== '') {
        file_put_contents($output, json_encode($metrics, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    expect($previews)->toBe($records->count());
    $first = $records->firstOrFail();
    expect(ValidateForceDeleteAction::run($first, $validator))->toBeTrue();
    expect($previews)->toBe($records->count() + 1);
    expect(ValidateContentDeleteImpactAction::run($first)->allowed)->toBeTrue();
    expect($previews)->toBe($records->count() + 2);
})->with(['page', 'layout']);
