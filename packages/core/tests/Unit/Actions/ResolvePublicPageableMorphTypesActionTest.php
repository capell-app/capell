<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolvePublicPageableMorphTypesAction;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;

final class UnavailableModel extends Model
{
    use HasFactory;

    protected $table = 'unavailable_pageables';
}

it('returns aliases and model classes only for pageable morphs', function (): void {
    expect(ResolvePublicPageableMorphTypesAction::run())
        ->toContain('page', Page::class)
        ->not->toContain('user', User::class);
});

it('detects models whose package table is unavailable', function (): void {
    $action = new ResolvePublicPageableMorphTypesAction;
    $method = new ReflectionMethod($action, 'hasBackingTable');

    expect($method->invoke($action, UnavailableModel::class))->toBeFalse();
});

/**
 * @param  callable(): mixed  $callback
 */
function countSchemaLookupsForTable(string $table, callable $callback): int
{
    $lookups = 0;

    DB::listen(function (QueryExecuted $query) use (&$lookups, $table): void {
        if (str_contains($query->sql, 'sqlite_master') && str_contains($query->sql, sprintf("'%s'", $table))) {
            $lookups++;
        }
    });

    $callback();

    return $lookups;
}

it('checks the backing table once for a model reachable through both an alias and its class name', function (): void {
    Relation::morphMap([Page::class => Page::class]);

    expect(Relation::morphMap())->toHaveKey('page', Page::class)->toHaveKey(Page::class, Page::class);

    $lookups = countSchemaLookupsForTable(
        (new Page)->getTable(),
        fn (): array => (new ResolvePublicPageableMorphTypesAction)->handle(),
    );

    expect($lookups)->toBe(1);
});

it('reuses the backing table check across calls within the same request', function (): void {
    $lookups = countSchemaLookupsForTable(
        (new Page)->getTable(),
        function (): void {
            ResolvePublicPageableMorphTypesAction::run();
            ResolvePublicPageableMorphTypesAction::run();
        },
    );

    expect($lookups)->toBe(1);
});
