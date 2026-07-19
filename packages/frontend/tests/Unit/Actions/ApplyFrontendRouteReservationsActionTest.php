<?php

declare(strict_types=1);

use Capell\Core\Contracts\FrontendRouteReservationContributor;
use Capell\Core\Data\FrontendRouteReservationData;
use Capell\Frontend\Actions\ApplyFrontendRouteReservationsAction;
use Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;
use Illuminate\Container\Container;

it('applies typed route reservation contributions and deduplicates normalized values', function (): void {
    $paths = new ReservedFrontendPathRegistry;
    $domains = new ReservedFrontendDomainRegistry;
    $contributor = new class implements FrontendRouteReservationContributor
    {
        public function reservations(): iterable
        {
            yield FrontendRouteReservationData::pathPrefix('/control/');
            yield FrontendRouteReservationData::pathPrefix('control');
            yield FrontendRouteReservationData::exactPath('/sign-in/');
            yield FrontendRouteReservationData::domain('Admin.Example.com:443');
            yield FrontendRouteReservationData::domain('admin.example.com');
            yield FrontendRouteReservationData::pathPrefix('');
            yield FrontendRouteReservationData::domain(' ');
        }
    };

    (new ApplyFrontendRouteReservationsAction($paths, $domains, [$contributor]))();

    expect($paths->prefixes())->toBe(['control'])
        ->and($paths->exactPaths())->toBe(['sign-in'])
        ->and($domains->reservedDomains())->toBe(['admin.example.com']);
});

it('ignores tagged services that do not implement the contribution contract', function (): void {
    $paths = new ReservedFrontendPathRegistry;
    $domains = new ReservedFrontendDomainRegistry;
    $contributor = new class implements FrontendRouteReservationContributor
    {
        public function reservations(): iterable
        {
            yield FrontendRouteReservationData::pathPrefix('admin');
        }
    };

    (new ApplyFrontendRouteReservationsAction($paths, $domains, [new stdClass, $contributor]))();

    expect($paths->prefixes())->toBe(['admin'])
        ->and($domains->reservedDomains())->toBe([]);
});

it('consumes contributions regardless of package registration order', function (bool $contributorFirst): void {
    $container = new Container;
    $contributor = new class implements FrontendRouteReservationContributor
    {
        public function reservations(): iterable
        {
            yield FrontendRouteReservationData::pathPrefix('control');
        }
    };

    $registerContributor = static function () use ($container, $contributor): void {
        $container->instance('test.frontend-route-reservation-contributor', $contributor);
        $container->tag('test.frontend-route-reservation-contributor', FrontendRouteReservationContributor::TAG);
    };
    $registerFrontend = static function () use ($container): void {
        $container->singleton(ReservedFrontendPathRegistry::class);
        $container->singleton(ReservedFrontendDomainRegistry::class);
    };

    foreach ($contributorFirst
        ? [$registerContributor, $registerFrontend]
        : [$registerFrontend, $registerContributor] as $register) {
        $register();
    }

    (new ApplyFrontendRouteReservationsAction(
        $container->make(ReservedFrontendPathRegistry::class),
        $container->make(ReservedFrontendDomainRegistry::class),
        $container->tagged(FrontendRouteReservationContributor::TAG),
    ))();

    expect($container->make(ReservedFrontendPathRegistry::class)->prefixes())->toBe(['control']);
})->with([
    'admin contributor before frontend' => true,
    'frontend before admin contributor' => false,
]);
