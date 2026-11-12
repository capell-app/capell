<?php

declare(strict_types=1);

use Capell\Core\Support\Redirects\PageUrlKey;
use Illuminate\Database\Connection;

it('uses the requested database comparison key', function (string $driver, ?string $column, ?string $table, string $expected): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('getName')->andReturn(uniqid('url-key-', true));
    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        if ($driver === 'mysql') {
            $pdo = Mockery::mock(PDO::class);
            $pdo->shouldReceive('getAttribute')->andReturn('8.0.36');
            $connection->shouldReceive('getPdo')->andReturn($pdo);
        }

        $connection->shouldReceive('getDatabaseName')->once()->andReturn('testing');
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('');
        $connection->shouldReceive('selectOne')->once()->andReturn((object) ['column_collation' => $column, 'table_collation' => $table]);
    }

    $key = new PageUrlKey($connection);

    expect($key->url('/AbOut'))->toBe($expected)
        ->and($key->url('/AbOut'))->toBe($expected);
})->with([
    ['sqlite', null, null, '/AbOut'],
    ['pgsql', null, null, '/AbOut'],
    ['mysql', 'utf8mb4_unicode_ci', null, '/about'],
    ['mysql', 'utf8mb4_0900_ai_ci', null, '/about'],
    ['mariadb', 'utf8mb4_bin', 'utf8mb4_unicode_ci', '/AbOut'],
    ['mysql', 'utf8mb4_0900_as_cs', null, '/AbOut'],
    ['mysql', null, 'utf8mb4_bin', '/AbOut'],
    ['mysql', null, null, '/about'],
]);

it('uses the safe case-insensitive fallback when collation metadata cannot be read', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('8.0.36');
    $connection->shouldReceive('getPdo')->andReturn($pdo);
    $connection->shouldReceive('getName')->andReturn(uniqid('url-key-', true));
    $connection->shouldReceive('getDatabaseName')->once()->andReturn('testing');
    $connection->shouldReceive('getTablePrefix')->once()->andReturn('');
    $connection->shouldReceive('selectOne')->once()->andThrow(new RuntimeException('metadata unavailable'));

    $key = new PageUrlKey($connection);

    expect($key->url('/AbOut'))->toBe('/about')
        ->and($key->url('/AbOut'))->toBe('/about');
});

it('uses the safe fallback when the server cannot be inspected', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getName')->andReturn(uniqid('url-key-', true));
    $connection->shouldReceive('getPdo')->andThrow(new RuntimeException('server unavailable'));

    expect(new PageUrlKey($connection)->url('/AbOut'))->toBe('/about');
});

it('collapses the accent and trailing-space variants the collation treats as equal', function (string $collation, string $left, string $right, bool $equal): void {
    $keyFor = function (string $url) use ($collation): string {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mariadb');
        $connection->shouldReceive('getName')->andReturn(uniqid('url-key-', true));
        $connection->shouldReceive('getDatabaseName')->andReturn('testing');
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('selectOne')->andReturn((object) ['column_collation' => $collation, 'table_collation' => null]);

        return new PageUrlKey($connection)->url($url);
    };

    expect($keyFor($left) === $keyFor($right))->toBe($equal);
})->with([
    'accent-insensitive folds accents' => ['utf8mb4_unicode_ci', '/café', '/cafe', true],
    'ai_ci folds accents' => ['utf8mb4_0900_ai_ci', '/Café', '/cafe', true],
    'pad space ignores trailing spaces' => ['utf8mb4_unicode_ci', '/about  ', '/about', true],
    'no pad keeps trailing spaces' => ['utf8mb4_0900_ai_ci', '/about ', '/about', false],
    'accent-sensitive keeps accents' => ['utf8mb4_0900_as_ci', '/café', '/cafe', false],
    'binary keeps case and accents' => ['utf8mb4_bin', '/Café', '/cafe', false],
    'binary pad space still ignores trailing spaces' => ['utf8mb4_bin', '/about ', '/about', true],
]);
