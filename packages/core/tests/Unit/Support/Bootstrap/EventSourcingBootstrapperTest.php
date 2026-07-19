<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Projectors\PageProjector;
use Capell\Core\EventSourcing\Reactors\PageWorkflowReactor;
use Capell\Core\EventSourcing\Rollback\Support\StateDiffer;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\Models\Page;
use Capell\Core\Support\Bootstrap\EventSourcingBootstrapper;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;

it('preserves cached config while applying explicit Capell event sourcing settings', function (): void {
    $app = new class(base_path()) extends Application
    {
        public function configurationIsCached(): bool
        {
            return true;
        }
    };

    $config = new Repository([
        'event-sourcing' => [
            'custom_cached_value' => 'preserved',
            'projectors' => ['App\\Projectors\\ExistingProjector'],
            'reactors' => ['App\\Reactors\\ExistingReactor'],
        ],
    ]);
    $app->instance('config', $config);

    $bootstrapper = new EventSourcingBootstrapper(
        app: $app,
        config: $config,
        events: Mockery::mock(Dispatcher::class),
    );

    $bootstrapper->register();

    $eventSourcedRegistry = $app->make(EventSourcedRegistry::class);

    expect($config->get('event-sourcing'))
        ->not->toHaveKey('stored_event_repository')
        ->and($config->get('event-sourcing.custom_cached_value'))->toBe('preserved')
        ->and($config->get('event-sourcing.projectors'))->toBe([
            'App\\Projectors\\ExistingProjector',
            PageProjector::class,
        ])
        ->and($config->get('event-sourcing.reactors'))->toBe([
            'App\\Reactors\\ExistingReactor',
            PageWorkflowReactor::class,
        ])
        ->and($config->get('event-sourcing.auto_discover_projectors_and_reactors'))->toBe([])
        ->and($config->get('event-sourcing.aggregate_event_order_column'))->toBe('aggregate_version')
        ->and($eventSourcedRegistry->registeredModels())->toContain(Page::class)
        ->and($eventSourcedRegistry->aggregateFor(Page::class))->toBe(PageAggregate::class)
        ->and($app->bound(StateDiffer::class))->toBeTrue();
});
