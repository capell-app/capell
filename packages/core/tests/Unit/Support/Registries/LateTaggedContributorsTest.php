<?php

declare(strict_types=1);

use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler;
use Capell\Core\Contracts\Publishing\PublicationReadinessContributor;
use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Support\Health\HealthCheckRegistry;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;
use Capell\Core\Support\Publishing\PublicationReadinessRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Tests\Support\HealthTestCheck;
use Capell\Core\Tests\Support\LateReadinessFirstContributor;
use Capell\Core\Tests\Support\LateReadinessLastContributor;
use Capell\Core\Tests\Support\RecordingProjectBuildArtifactHandler;
use Capell\Core\Tests\Support\TaggedReadinessContributor;
use Capell\Core\Tests\Support\TaggedSiteSpecApplier;
use Illuminate\Container\Container;

it('refreshes health checks resolved before a package registers its first contributor', function (): void {
    $container = new Container;
    $registry = new HealthCheckRegistry($container);
    expect($registry->checks())->toBe([]);
    $check = new HealthTestCheck('runtime.health');
    $container->instance('late', $check);
    $container->tag(['late'], HealthCheck::TAG);
    expect($registry->checks())->toBe([$check])->and($registry->checks())->toBe([$check]);
});

it('refreshes publication contributors resolved before installation without deduplication', function (): void {
    $container = new Container;
    $registry = new PublicationReadinessRegistry($container);
    expect($registry->contributors())->toBe([]);
    $contributor = new TaggedReadinessContributor;
    $container->instance('late', $contributor);
    $container->tag(['late'], PublicationReadinessContributor::TAG);
    expect($registry->contributors())->toBe([$contributor])->and($registry->contributors())->toBe([$contributor]);
});

it('keeps late publication contributors in the same order as a fresh boot', function (): void {
    $container = new Container;
    $registry = new PublicationReadinessRegistry($container);
    $later = Mockery::mock(LateReadinessLastContributor::class);
    $earlier = Mockery::mock(LateReadinessFirstContributor::class);
    $ordered = [$earlier, $later];
    $container->instance('first', $ordered[1]);
    $container->tag(['first'], PublicationReadinessContributor::TAG);

    expect($registry->contributors())->toBe([$ordered[1]]);
    $container->instance('second', $ordered[0]);
    $container->tag(['second'], PublicationReadinessContributor::TAG);

    expect($registry->contributors())->toBe(new PublicationReadinessRegistry($container)->contributors());
});

it('composes direct and tagged readiness contributors identically after interleaved discovery', function (): void {
    $container = new Container;
    $first = Mockery::mock(LateReadinessFirstContributor::class);
    $last = Mockery::mock(LateReadinessLastContributor::class);
    $direct = new TaggedReadinessContributor;
    $container->instance('last', $last);
    $container->tag(['last'], PublicationReadinessContributor::TAG);

    $late = new PublicationReadinessRegistry($container);
    expect($late->contributors())->toBe([$last]);
    $late->register($direct)->register($direct);
    $container->instance('first', $first);
    $container->tag(['first'], PublicationReadinessContributor::TAG);

    $fresh = new PublicationReadinessRegistry($container);
    $fresh->register($direct)->register($direct);
    expect($late->contributors())->toBe($fresh->contributors())
        ->and(array_slice($late->contributors(), 0, 2))->toBe([$direct, $direct]);
});

it('refreshes SiteSpec appliers resolved before installation', function (): void {
    $container = new Container;
    $registry = new SiteSpecApplierRegistry($container);
    expect($registry->keys())->toBe([]);
    $applier = new TaggedSiteSpecApplier;
    $container->instance('late', $applier);
    $container->tag(['late'], SiteSpecApplier::TAG);
    expect($registry->keys())->toBe(['navigation'])->and($registry->keys())->toBe(['navigation']);
});

it('refreshes project build handlers resolved before installation', function (): void {
    $container = new Container;
    $registry = new ProjectBuildArtifactHandlerRegistry($container);
    expect($registry->types())->toBe([]);
    $handler = new RecordingProjectBuildArtifactHandler;
    $container->instance('late', $handler);
    $container->tag(['late'], ProjectBuildArtifactHandler::TAG);
    expect($registry->types())->toBe(['capell-theme'])->and($registry->types())->toBe(['capell-theme']);
});
