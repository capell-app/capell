<?php

declare(strict_types=1);

use Capell\Core\Enums\IdempotencyClaimState;
use Capell\Core\Exceptions\MismatchedIdempotentPayload;
use Capell\Core\Models\IdempotencyKey;
use Capell\Core\Support\Idempotency\IdempotencyKeyStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('acquires a new key, reports it in progress to a second caller and replays the stored result once completed', function (): void {
    $store = new IdempotencyKeyStore;

    $first = $store->claim('order:42', 'hash-a', 600);
    expect($first->state)->toBe(IdempotencyClaimState::Acquired);

    expect($store->claim('order:42', 'hash-a', 600)->state)->toBe(IdempotencyClaimState::InProgress);

    $store->complete($first, ['charge_id' => 'ch_1']);
    $replay = $store->claim('order:42', 'hash-a', 600);

    expect($replay->state)->toBe(IdempotencyClaimState::Completed)
        ->and($replay->result)->toBe(['charge_id' => 'ch_1']);
});

it('refuses a live key reused with a different payload', function (): void {
    $store = new IdempotencyKeyStore;
    $store->claim('order:42', 'hash-a', 600);

    $store->claim('order:42', 'hash-b', 600);
})->throws(MismatchedIdempotentPayload::class);

it('lets a retry claim the key again after the first attempt is released', function (): void {
    $store = new IdempotencyKeyStore;
    $store->release($store->claim('order:42', 'hash-a', 600));

    expect($store->claim('order:42', 'hash-a', 600)->state)->toBe(IdempotencyClaimState::Acquired);
});

it('treats an expired key as free, whatever payload it held', function (): void {
    $store = new IdempotencyKeyStore;
    $store->complete($store->claim('order:42', 'hash-a', 60), ['charge_id' => 'ch_1']);

    $this->travel(2)->minutes();

    $claim = $store->claim('order:42', 'hash-b', 60);

    expect($claim->state)->toBe(IdempotencyClaimState::Acquired)
        ->and(IdempotencyKey::query()->sole()->result)->toBeNull();
});

it('stores the replayed result encrypted', function (): void {
    $store = new IdempotencyKeyStore;
    $store->complete($store->claim('order:42', 'hash-a', 600), ['card_holder' => 'Ada Lovelace']);

    $raw = (string) DB::table((new IdempotencyKey)->getTable())->value('result');

    expect($raw)->not->toContain('Ada Lovelace')
        ->and(IdempotencyKey::query()->sole()->result)->toBe(['card_holder' => 'Ada Lovelace']);
});

it('prunes only expired keys', function (): void {
    $store = new IdempotencyKeyStore;
    $store->claim('short', 'hash', 60);
    $store->claim('long', 'hash', 86_400);

    $this->travel(2)->minutes();
    expect(Artisan::call('model:prune', ['--model' => [IdempotencyKey::class]]))->toBe(0);

    expect(IdempotencyKey::query()->pluck('key')->all())->toBe(['long']);
});

it('builds stable provider call keys and refuses an empty execution id', function (): void {
    expect(IdempotencyKeyStore::forProviderCall('exec-9'))->toBe('provider-call:exec-9');

    IdempotencyKeyStore::forProviderCall(' ');
})->throws(InvalidArgumentException::class);

it('only completes or releases a claim this caller acquired', function (): void {
    $store = new IdempotencyKeyStore;
    $store->claim('order:42', 'hash-a', 600);

    $store->complete($store->claim('order:42', 'hash-a', 600), ['charge_id' => 'ch_1']);
})->throws(InvalidArgumentException::class);
