<?php

declare(strict_types=1);

use Capell\Admin\Support\Search\AppliesNameSearchRelevance;
use Capell\Core\Models\Layout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

test('it ranks matching names by relevance while preserving query constraints and selection', function (): void {
    Layout::factory()->createOne(['name' => 'Z cap', 'key' => 'z-cap']);
    Layout::factory()->createOne(['name' => 'Y cap', 'key' => 'y-cap']);
    Layout::factory()->createOne(['name' => 'Axx cap', 'key' => 'axx-cap']);
    Layout::factory()->createOne(['name' => 'cap', 'key' => 'cap']);
    Layout::factory()->createOne(['name' => 'capable', 'key' => 'capable']);
    Layout::factory()->createOne(['name' => 'unrelated', 'key' => 'unrelated']);

    $query = Layout::query()
        ->select(['id', 'name'])
        ->where('name', 'like', '%cap%')
        ->orderByDesc('id');

    $results = (new NameSearchRelevanceApplier)->apply($query, 'cap')->get();

    expect($results)
        ->pluck('name')
        ->all()
        ->toBe(['cap', 'capable', 'Y cap', 'Z cap', 'Axx cap'])
        ->and($results->every(fn (Layout $layout): bool => array_keys($layout->getAttributes()) === ['id', 'name']))
        ->toBeTrue();
});

test('it binds user supplied search text in relevance ordering', function (): void {
    $search = "cap' THEN 0 ELSE 1 END; DROP TABLE layouts; --";
    $query = Layout::query()->where('name', 'like', '%' . $search . '%');

    (new NameSearchRelevanceApplier)->apply($query, $search);

    $bindings = $query->getQuery()->getRawBindings();
    $orderBindings = is_array($bindings['order'] ?? null) ? $bindings['order'] : [];
    $searchExpression = Str::lower($search);

    expect($query->toSql())
        ->not->toContain($search)
        ->and($orderBindings)
        ->toContain($searchExpression)
        ->and($orderBindings)
        ->toContain($searchExpression . '%');
});

test('it ranks substring matches before non-matches in broadly constrained queries', function (): void {
    Layout::factory()->createOne(['name' => 'unrelated', 'key' => 'unrelated']);
    Layout::factory()->createOne(['name' => 'Z cap', 'key' => 'z-cap']);
    Layout::factory()->createOne(['name' => 'cap', 'key' => 'cap']);

    $query = Layout::query()
        ->whereNotNull('id')
        ->orderByDesc('id');

    $names = (new NameSearchRelevanceApplier)->apply($query, 'cap')->pluck('name')->all();

    expect($names)->toBe(['cap', 'Z cap', 'unrelated']);
});

test('it ranks SQLite name matches using case-insensitive positions', function (): void {
    Layout::factory()->createOne(['name' => 'Z xxxxxx cAp', 'key' => 'case-variant']);
    Layout::factory()->createOne(['name' => 'A CAP', 'key' => 'exact-case']);

    $names = (new NameSearchRelevanceApplier)
        ->apply(Layout::query()->where('name', 'like', '%CAP%'), 'CAP')
        ->pluck('name')
        ->all();

    expect($names)->toBe(['A CAP', 'Z xxxxxx cAp']);
});

test('it generates a driver-compatible substring position expression', function (string $connection, string $expectedFunction): void {
    if ($connection === 'relevance-pgsql') {
        config()->set('database.connections.relevance-pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'capell',
            'username' => 'capell',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    $query = Layout::on($connection);

    (new NameSearchRelevanceApplier)->apply($query, 'cap');

    expect($query->toSql())->toContain($expectedFunction . '(');
})->with([
    'sqlite' => ['sqlite', 'INSTR'],
    'postgresql' => ['relevance-pgsql', 'STRPOS'],
]);

test('it normalizes PostgreSQL relevance comparisons and bindings', function (): void {
    config()->set('database.connections.relevance-pgsql', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 5432,
        'database' => 'capell',
        'username' => 'capell',
        'password' => '',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'schema' => 'public',
        'sslmode' => 'prefer',
    ]);

    $query = Layout::on('relevance-pgsql');

    (new NameSearchRelevanceApplier)->apply($query, 'CapELL');

    $bindings = $query->getQuery()->getRawBindings();
    $orderBindings = is_array($bindings['order'] ?? null) ? $bindings['order'] : [];

    expect($query->toSql())
        ->toContain('LOWER("layouts"."name")')
        ->toContain('STRPOS(LOWER("layouts"."name"), ?)')
        ->and($orderBindings)
        ->toBe(['capell', 'capell%', '%capell%', 'capell']);
});

final class NameSearchRelevanceApplier
{
    use AppliesNameSearchRelevance;

    /**
     * @param  Builder<Layout>  $query
     * @return Builder<Layout>
     */
    public function apply(Builder $query, string $search): Builder
    {
        return self::applyNameSearchRelevance($query, $search);
    }
}
