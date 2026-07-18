<?php

declare(strict_types=1);

namespace Capell\Core\Support\Bootstrap;

use Capell\Core\Events\PageSaved;
use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Listeners\RecordPageRevision;
use Capell\Core\EventSourcing\Projectors\PageProjector;
use Capell\Core\EventSourcing\Reactors\PageWorkflowReactor;
use Capell\Core\EventSourcing\Rollback\RollbackValidatorRegistry;
use Capell\Core\EventSourcing\Rollback\Support\StateDiffer;
use Capell\Core\EventSourcing\Rollback\Validators\PageReferentialIntegrityRollbackValidator;
use Capell\Core\EventSourcing\Rollback\Validators\PageUrlUniquenessRollbackValidator;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use Spatie\EventSourcing\EventSourcingServiceProvider;

final readonly class EventSourcingBootstrapper
{
    public function __construct(
        private Application $app,
        private Repository $config,
        private Dispatcher $events,
    ) {}

    public function register(): void
    {
        $this->app->singleton(EventSourcedRegistry::class);
        $this->app->singleton(RollbackValidatorRegistry::class);
        $this->app->singleton(StateDiffer::class);

        $providerFile = new ReflectionClass(EventSourcingServiceProvider::class)->getFileName();

        if ($providerFile !== false) {
            $defaults = require dirname($providerFile, 2) . '/config/event-sourcing.php';
            $this->config->set('event-sourcing', array_merge($defaults, $this->config->get('event-sourcing', [])));
        }

        $this->config->set([
            'event-sourcing.projectors' => array_values(array_unique([
                ...(array) $this->config->get('event-sourcing.projectors', []),
                PageProjector::class,
            ])),
            'event-sourcing.reactors' => array_values(array_unique([
                ...(array) $this->config->get('event-sourcing.reactors', []),
                PageWorkflowReactor::class,
            ])),
            'event-sourcing.auto_discover_projectors_and_reactors' => [],
            'event-sourcing.aggregate_event_order_column' => 'aggregate_version',
        ]);

        $this->app->make(EventSourcedRegistry::class)->register(
            Page::class,
            PageAggregate::class,
            PageStateSerializer::class,
        );

        $validators = $this->app->make(RollbackValidatorRegistry::class);
        $validators->register(Page::class, PageUrlUniquenessRollbackValidator::class);
        $validators->register(Page::class, PageReferentialIntegrityRollbackValidator::class);
    }

    public function boot(): void
    {
        $this->events->listen(PageSaved::class, [RecordPageRevision::class, 'handle']);
    }
}
