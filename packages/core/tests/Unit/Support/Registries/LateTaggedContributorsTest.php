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
use Illuminate\Container\Container;

it('refreshes health checks resolved before a package registers its first contributor', function (): void {
    $container = new Container;
    $registry = new HealthCheckRegistry($container);
    expect($registry->checks())->toBe([]);
    $check = Mockery::mock(HealthCheck::class);
    $check->shouldReceive('id')->andReturn('runtime.health');
    $check->shouldReceive('category')->andReturn('runtime');
    $check->shouldReceive('timeoutSeconds')->andReturn(5);
    $container->instance('late', $check);
    $container->tag(['late'], HealthCheck::TAG);
    expect($registry->checks())->toBe([$check])->and($registry->checks())->toBe([$check]);
});

it('refreshes publication contributors resolved before installation without deduplication', function (): void {
    $container = new Container;
    $registry = new PublicationReadinessRegistry($container);
    expect($registry->contributors())->toBe([]);
    $contributor = Mockery::mock(PublicationReadinessContributor::class);
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
    usort($ordered, static fn (object $left, object $right): int => $left::class <=> $right::class);
    $container->instance('first', $ordered[1]);
    $container->tag(['first'], PublicationReadinessContributor::TAG);

    expect($registry->contributors())->toBe([$ordered[1]]);
    $container->instance('second', $ordered[0]);
    $container->tag(['second'], PublicationReadinessContributor::TAG);

    expect($registry->contributors())->toBe(new PublicationReadinessRegistry($container)->contributors());
});

interface LateReadinessFirstContributor extends PublicationReadinessContributor {}

interface LateReadinessLastContributor extends PublicationReadinessContributor {}

it('refreshes SiteSpec appliers resolved before installation', function (): void {
    $container = new Container;
    $registry = new SiteSpecApplierRegistry($container);
    expect($registry->keys())->toBe([]);
    $applier = Mockery::mock(SiteSpecApplier::class);
    $applier->shouldReceive('key')->andReturn('runtime.applier');
    $container->instance('late', $applier);
    $container->tag(['late'], SiteSpecApplier::TAG);
    expect($registry->keys())->toBe(['runtime.applier'])->and($registry->keys())->toBe(['runtime.applier']);
});

it('refreshes project build handlers resolved before installation', function (): void {
    $container = new Container;
    $registry = new ProjectBuildArtifactHandlerRegistry($container);
    expect($registry->types())->toBe([]);
    $handler = Mockery::mock(ProjectBuildArtifactHandler::class);
    $handler->shouldReceive('type')->andReturn('runtime-artifact');
    $container->instance('late', $handler);
    $container->tag(['late'], ProjectBuildArtifactHandler::TAG);
    expect($registry->types())->toBe(['runtime-artifact'])->and($registry->types())->toBe(['runtime-artifact']);
});
