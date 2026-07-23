<?php

declare(strict_types=1);

use Capell\Core\Support\Upgrade\DatabaseUpgradeLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (Schema::hasTable(DatabaseUpgradeLock::TABLE)) {
        DB::table(DatabaseUpgradeLock::TABLE)->delete();
    }
});

it('refuses a second holder while the lock is held', function (): void {
    $lock = new DatabaseUpgradeLock;

    $first = $lock->acquire('capell:upgrade', 60);
    $second = $lock->acquire('capell:upgrade', 60);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();
});

it('enforces exclusion in the database, not the cache', function (): void {
    // The reason this lock exists. On the array or file driver each node has its
    // own cache, so a cache lock excludes nobody — but the unique index still does.
    Config::set('cache.default', 'array');

    $lock = new DatabaseUpgradeLock;

    $first = $lock->acquire('capell:upgrade', 60);
    $second = $lock->acquire('capell:upgrade', 60);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        // The database is where the exclusion happened: one row, holding the
        // first token. A cache lock would leave this table empty.
        ->and(DB::table(DatabaseUpgradeLock::TABLE)->where('name', 'capell:upgrade')->count())->toBe(1)
        ->and(DB::table(DatabaseUpgradeLock::TABLE)->where('name', 'capell:upgrade')->value('token'))->toBe($first);
})->skip(fn (): bool => ! Schema::hasTable(DatabaseUpgradeLock::TABLE), 'Lock table not migrated in this suite.');

it('lets the lock be taken again after release', function (): void {
    $lock = new DatabaseUpgradeLock;

    $token = $lock->acquire('capell:upgrade', 60);
    expect($token)->not->toBeNull();

    $lock->release('capell:upgrade', (string) $token);

    expect($lock->acquire('capell:upgrade', 60))->not->toBeNull();
});

it('ignores a release from a holder that no longer owns the lock', function (): void {
    // A process that overran its expiry must not free its successor's lock.
    $lock = new DatabaseUpgradeLock;

    $token = $lock->acquire('capell:upgrade', 60);
    $lock->release('capell:upgrade', 'some-other-token');

    expect($lock->acquire('capell:upgrade', 60))->toBeNull()
        ->and($token)->not->toBeNull();
});

it('takes over a lock whose expiry has passed', function (): void {
    // A hard-killed upgrade never releases, so the lock must free itself rather
    // than blocking every future upgrade.
    $lock = new DatabaseUpgradeLock;

    $lock->acquire('capell:upgrade', 60);

    DB::table(DatabaseUpgradeLock::TABLE)
        ->where('name', 'capell:upgrade')
        ->update(['expires_at' => Date::now()->subMinute()]);

    expect($lock->acquire('capell:upgrade', 60))->not->toBeNull();
})->skip(fn (): bool => ! Schema::hasTable(DatabaseUpgradeLock::TABLE), 'Lock table not migrated in this suite.');

it('reports a held lock without acquiring it', function (): void {
    // Readiness reporting reads this. If the read took the lock it would reject
    // the very upgrade it was asked to clear.
    $lock = new DatabaseUpgradeLock;

    expect($lock->isHeld('capell:upgrade'))->toBeFalse();

    $lock->acquire('capell:upgrade', 60);

    expect($lock->isHeld('capell:upgrade'))->toBeTrue()
        // Still held after the read, and still owned by the original holder.
        ->and($lock->isHeld('capell:upgrade'))->toBeTrue();
});

it('refuses to acquire when neither database nor cache coordination is available', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with(DatabaseUpgradeLock::TABLE)
        ->andReturnFalse();
    Cache::shouldReceive('lock')
        ->once()
        ->with('capell:upgrade', 60)
        ->andThrow(new RuntimeException('Cache unavailable.'));

    expect(new DatabaseUpgradeLock()->acquire('capell:upgrade', 60))->toBeNull();
});

it('reports a held lock when fallback coordination state cannot be established', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with(DatabaseUpgradeLock::TABLE)
        ->andReturnFalse();
    Cache::shouldReceive('lock')
        ->once()
        ->with('capell:upgrade', 1)
        ->andThrow(new RuntimeException('Cache unavailable.'));

    expect(new DatabaseUpgradeLock()->isHeld('capell:upgrade'))->toBeTrue();
});

it('refuses to fall back to cache when the database schema cannot be inspected', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with(DatabaseUpgradeLock::TABLE)
        ->andThrow(new RuntimeException('Database unavailable.'));
    Cache::shouldReceive('lock')->never();

    expect(new DatabaseUpgradeLock()->acquire('capell:upgrade', 60))->toBeNull();
});

it('reports a held lock when the database schema cannot be inspected', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with(DatabaseUpgradeLock::TABLE)
        ->andThrow(new RuntimeException('Database unavailable.'));
    Cache::shouldReceive('lock')->never();

    expect(new DatabaseUpgradeLock()->isHeld('capell:upgrade'))->toBeTrue();
});
