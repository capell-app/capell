<?php

declare(strict_types=1);

use Capell\Core\Support\Security\LockdownStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    config()->set('capell.lockdown.file', storage_path('framework/testing/lockdown-store.json'));
    File::delete(lockdownStoreTestFile());
});

afterEach(function (): void {
    File::delete(lockdownStoreTestFile());
});

it('fails closed when the lockdown file is malformed', function (): void {
    $lockdownFile = lockdownStoreTestFile();

    File::ensureDirectoryExists(dirname($lockdownFile));
    File::put($lockdownFile, '{not-json');

    $store = new LockdownStore(new Filesystem);

    expect($store->active())->toBeTrue()
        ->and($store->canAccessAdmin(lockdownStoreTestUser(1, 'owner@example.com')))->toBeFalse();
});

it('fails closed when the lockdown file is partial', function (): void {
    $lockdownFile = lockdownStoreTestFile();

    File::ensureDirectoryExists(dirname($lockdownFile));
    File::put($lockdownFile, '{}');

    $store = new LockdownStore(new Filesystem);

    expect($store->active())->toBeTrue()
        ->and($store->canAccessAdmin(lockdownStoreTestUser(1, 'owner@example.com')))->toBeFalse();
});

it('allows configured break glass user ids from comma separated config', function (): void {
    config()->set('capell.lockdown.break_glass_user_ids', '10, 20');

    $store = new LockdownStore(new Filesystem);
    $store->activateFor(lockdownStoreTestUser(1, 'owner@example.com'));

    expect($store->canAccessAdmin(lockdownStoreTestUser(20, 'other@example.com')))->toBeTrue()
        ->and($store->canAccessAdmin(lockdownStoreTestUser(30, 'blocked@example.com')))->toBeFalse();
});

it('reloads lockdown state after an Octane reset', function (): void {
    $store = new LockdownStore(new Filesystem);

    expect($store->active())->toBeFalse();

    File::ensureDirectoryExists(dirname(lockdownStoreTestFile()));
    File::put(lockdownStoreTestFile(), json_encode(['active' => true], JSON_THROW_ON_ERROR));

    expect($store->active())->toBeFalse();

    $store->flushOctaneState();

    expect($store->active())->toBeTrue();
});

it('shares multi-node lockdown across stores with different local files', function (): void {
    $cache = new Repository(new ArrayStore);
    config(['capell.multi_node' => true, 'cache.default' => 'shared-test', 'cache.stores.shared-test.driver' => 'redis']);
    Cache::shouldReceive('store')->with('shared-test')->andReturn($cache);
    $first = new LockdownStore(new Filesystem);
    $second = new LockdownStore(new Filesystem);
    $first->activateFor(lockdownStoreTestUser(1, 'owner@example.com'));
    config(['capell.lockdown.file' => storage_path('framework/testing/another-node.json')]);
    expect($second->canAccessAdmin(lockdownStoreTestUser(2, 'blocked@example.com')))->toBeFalse()
        ->and($second->canAccessAdmin(lockdownStoreTestUser(1, 'owner@example.com')))->toBeTrue();
    $first->deactivate();
    $second->flushOctaneState();
    expect($second->active())->toBeFalse();
    File::delete(storage_path('framework/testing/lockdown-store.json'));
});

it('refuses node-local cache stores for multi-node lockdown', function (string $driver): void {
    config(['capell.multi_node' => true, 'cache.default' => 'local-test', 'cache.stores.local-test.driver' => $driver]);
    expect(fn (): array => new LockdownStore(new Filesystem)->activateFor(lockdownStoreTestUser(1, 'owner@example.com')))
        ->toThrow(RuntimeException::class, 'Multi-node lockdown requires a shared cache store.');
})->with(['array', 'file']);

function lockdownStoreTestUser(int $id, string $email): Authenticatable
{
    return new readonly class($id, $email) implements Authenticatable
    {
        public function __construct(
            private int $id,
            public string $email,
        ) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

function lockdownStoreTestFile(): string
{
    $lockdownFile = config('capell.lockdown.file');

    throw_unless(is_string($lockdownFile), RuntimeException::class, 'The lockdown test file path must be a string.');

    return $lockdownFile;
}
