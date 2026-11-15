<?php

declare(strict_types=1);

use Capell\Core\Enums\IdempotencyClaimState;
use Capell\Core\Exceptions\MismatchedIdempotentPayload;
use Capell\Core\Exceptions\StaleIdempotencyClaim;
use Capell\Core\Models\IdempotencyKey;
use Capell\Core\Support\Idempotency\IdempotencyClaim;
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

it("refuses to let a caller whose claim was taken over complete it, and keeps the new holder's claim", function (): void {
    $store = new IdempotencyKeyStore;
    $stale = $store->claim('order:42', 'hash-a', 60);

    $this->travel(61)->seconds();
    $current = $store->claim('order:42', 'hash-a', 60);

    expect($current->state)->toBe(IdempotencyClaimState::Acquired)
        ->and($current->token)->not->toBe($stale->token)
        ->and(fn () => $store->complete($stale, ['charge_id' => 'ch_stale']))->toThrow(StaleIdempotencyClaim::class);

    $store->release($stale);
    $store->complete($current, ['charge_id' => 'ch_current']);

    expect($store->claim('order:42', 'hash-a', 60)->result)->toBe(['charge_id' => 'ch_current']);
});

it('refuses to complete a key twice', function (): void {
    $store = new IdempotencyKeyStore;
    $claim = $store->claim('order:42', 'hash-a', 600);
    $store->complete($claim, ['charge_id' => 'ch_1']);

    expect(fn () => $store->complete($claim, ['charge_id' => 'ch_2']))->toThrow(StaleIdempotencyClaim::class);
});

it('keeps a permanently completed result through expiry and pruning', function (): void {
    $store = new IdempotencyKeyStore;
    $store->completePermanently($store->claim('payment:7', 'hash-a', 60), ['reference' => 'pay_7']);
    $store->complete($store->claim('order:1', 'hash-a', 60), ['charge_id' => 'ch_1']);

    $this->travel(400)->days();
    Artisan::call('model:prune', ['--model' => [IdempotencyKey::class]]);

    expect(IdempotencyKey::query()->pluck('key')->all())->toBe(['payment:7'])
        ->and($store->claim('payment:7', 'hash-a', 60)->result)->toBe(['reference' => 'pay_7'])
        ->and(fn (): IdempotencyClaim => $store->claim('payment:7', 'hash-b', 60))->toThrow(MismatchedIdempotentPayload::class);
});

it('retains a completed result for the requested period, not the claim lifetime', function (): void {
    $store = new IdempotencyKeyStore;
    $store->complete($store->claim('order:42', 'hash-a', 60), ['charge_id' => 'ch_1'], retainSeconds: 3600);

    $this->travel(30)->minutes();

    expect($store->claim('order:42', 'hash-a', 60)->state)->toBe(IdempotencyClaimState::Completed);
});

it('clears the claim token once the result is stored', function (): void {
    $store = new IdempotencyKeyStore;
    $store->complete($store->claim('order:42', 'hash-a', 600), ['charge_id' => 'ch_1']);

    expect(DB::table((new IdempotencyKey)->getTable())->where('key', 'order:42')->value('claim_token'))->toBeNull();
});
