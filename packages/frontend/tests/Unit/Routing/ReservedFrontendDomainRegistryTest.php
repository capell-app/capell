<?php

declare(strict_types=1);

use Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry;

it('matches reserved hosts case-insensitively and ignores ports', function (): void {
    $registry = new ReservedFrontendDomainRegistry;

    $registry->reserve('Admin.Test');

    expect($registry->isReserved('admin.test'))->toBeTrue()
        ->and($registry->isReserved('ADMIN.TEST'))->toBeTrue()
        ->and($registry->isReserved('admin.test:8080'))->toBeTrue()
        ->and($registry->isReserved('site.test'))->toBeFalse();
});

it('does not reserve empty or whitespace-only hosts', function (): void {
    $registry = new ReservedFrontendDomainRegistry;

    $registry->reserve('');
    $registry->reserve('   ');

    expect($registry->reservedDomains())->toBe([])
        ->and($registry->isReserved(''))->toBeFalse();
});

it('exposes the normalized reserved hosts', function (): void {
    $registry = new ReservedFrontendDomainRegistry;

    $registry->reserve('Admin.Test:443');
    $registry->reserve('.internal.test.');

    expect($registry->reservedDomains())->toBe(['admin.test', 'internal.test']);
});

it('preserves first-registration order when a normalized host is reserved again', function (): void {
    $registry = new ReservedFrontendDomainRegistry;

    $registry->reserve('Admin.Test');
    $registry->reserve('internal.test');
    $registry->reserve('admin.test:443');

    expect($registry->reservedDomains())->toBe(['admin.test', 'internal.test']);
});

it('is resolved from the container as a singleton', function (): void {
    expect(resolve(ReservedFrontendDomainRegistry::class))
        ->toBe(resolve(ReservedFrontendDomainRegistry::class));
});
