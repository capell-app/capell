<?php

declare(strict_types=1);

use Capell\Core\Support\Security\LockdownStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;
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
