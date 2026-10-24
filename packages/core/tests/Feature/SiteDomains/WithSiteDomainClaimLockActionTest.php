<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\WithSiteDomainClaimLockAction;
use Illuminate\Support\Facades\DB;

it('runs the operation under the existing claim lock row', function (): void {
    expect(DB::table('site_domain_claim_locks')->pluck('id')->all())->toBe([1]);

    $result = WithSiteDomainClaimLockAction::run(DB::connection(), static fn (): string => 'claimed');

    expect($result)->toBe('claimed')
        ->and(DB::table('site_domain_claim_locks')->pluck('id')->all())->toBe([1]);
});

it('restores the lock row when a schema dump left the table without its seed', function (): void {
    DB::table('site_domain_claim_locks')->delete();

    $result = WithSiteDomainClaimLockAction::run(DB::connection(), static fn (): string => 'claimed');

    expect($result)->toBe('claimed')
        ->and(DB::table('site_domain_claim_locks')->pluck('id')->all())->toBe([1]);
});
