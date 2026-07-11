<?php

declare(strict_types=1);

use Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendRequest;
use Illuminate\Http\Request;

function makeReservedFrontendRequest(
    ReservedFrontendDomainRegistry $domains,
    ReservedFrontendPathRegistry $paths,
): ReservedFrontendRequest {
    return new ReservedFrontendRequest($domains, $paths);
}

it('matches a request on a reserved admin path', function (): void {
    $paths = new ReservedFrontendPathRegistry;
    $paths->reservePrefix('admin');

    $predicate = makeReservedFrontendRequest(new ReservedFrontendDomainRegistry, $paths);

    expect($predicate->matches(Request::create('http://capell.test/admin/pages/125/edit')))->toBeTrue();
});

it('matches a request on a reserved admin host', function (): void {
    $domains = new ReservedFrontendDomainRegistry;
    $domains->reserve('admin.capell.test');

    $predicate = makeReservedFrontendRequest($domains, new ReservedFrontendPathRegistry);

    expect($predicate->matches(Request::create('http://admin.capell.test/pages/125/edit')))->toBeTrue();
});

it('does not match a normal public page request', function (): void {
    $paths = new ReservedFrontendPathRegistry;
    $paths->reservePrefix('admin');

    $domains = new ReservedFrontendDomainRegistry;
    $domains->reserve('admin.capell.test');

    $predicate = makeReservedFrontendRequest($domains, $paths);

    expect($predicate->matches(Request::create('http://capell.test/about')))->toBeFalse();
});
