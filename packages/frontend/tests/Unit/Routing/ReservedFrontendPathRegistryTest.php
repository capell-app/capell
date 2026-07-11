<?php

declare(strict_types=1);

use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;

it('matches exact reserved frontend paths', function (): void {
    $registry = new ReservedFrontendPathRegistry;

    $registry->reserveExact('/.well-known/capell/marketplace/attestation/');

    expect($registry->isReserved('.well-known/capell/marketplace/attestation'))->toBeTrue()
        ->and($registry->isReserved('.well-known/capell/marketplace/attestation/extra'))->toBeFalse();
});

it('matches reserved frontend prefixes without matching partial segments', function (): void {
    $registry = new ReservedFrontendPathRegistry;

    $registry->reservePrefix('/admin/');

    expect($registry->isReserved('admin'))->toBeTrue()
        ->and($registry->isReserved('admin/extensions/marketplace'))->toBeTrue()
        ->and($registry->isReserved('administrator/extensions'))->toBeFalse();
});
