<?php

declare(strict_types=1);

namespace Capell\Core\Support\Metrics;

use Capell\Core\Contracts\Metrics\CollectsDailyMetrics;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class MetricCollectorRegistry
{
    /**
     * @var array<string, array{
     *     class: class-string<CollectsDailyMetrics>,
     *     definitions: array<string, MetricDefinitionData>,
     *     hashes: array<string, string>
     * }>
     */
    private array $collectors = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string $collectorClass */
    public function register(string $collectorClass): void
    {
        throw_unless(is_a($collectorClass, CollectsDailyMetrics::class, true), InvalidArgumentException::class, sprintf(
            'Metric collector [%s] must implement [%s].',
            $collectorClass,
            CollectsDailyMetrics::class,
        ));

        /** @var CollectsDailyMetrics $collector */
        $collector = $this->container->make($collectorClass);
        $definitions = $this->validatedDefinitions($collector);
        $firstDefinition = reset($definitions);

        throw_if($firstDefinition === false, InvalidArgumentException::class, sprintf(
            'Metric collector [%s] must declare at least one definition.',
            $collectorClass,
        ));

        $collectorIdentity = $this->collectorIdentity($firstDefinition);
        $hashes = array_map(
            static fn (MetricDefinitionData $definition): string => $definition->semanticHash(),
            $definitions,
        );

        if (isset($this->collectors[$collectorIdentity])) {
            throw_if($this->collectors[$collectorIdentity]['class'] !== $collectorClass, InvalidArgumentException::class, sprintf(
                'Metric collector identity [%s] is already owned by [%s].',
                $collectorIdentity,
                $this->collectors[$collectorIdentity]['class'],
            ));
            throw_if($this->collectors[$collectorIdentity]['hashes'] !== $hashes, InvalidArgumentException::class, sprintf(
                'Metric collector [%s] is already registered with different definitions.',
                $collectorIdentity,
            ));
        }

        $this->collectors[$collectorIdentity] = [
            'class' => $collectorClass,
            'definitions' => $definitions,
            'hashes' => $hashes,
        ];
    }

    /** @return list<CollectsDailyMetrics> */
    public function collectors(): array
    {
        return array_map(
            fn (array $entry): CollectsDailyMetrics => $this->container->make($entry['class']),
            array_values($this->collectors),
        );
    }

    /** @return Collection<string, MetricDefinitionData> */
    public function definitions(): Collection
    {
        return collect($this->collectors)
            ->flatMap(static fn (array $entry): array => $entry['definitions']);
    }

    /** @return array<string, MetricDefinitionData> */
    public function definitionsFor(CollectsDailyMetrics $collector): array
    {
        $definitions = $this->validatedDefinitions($collector);
        $firstDefinition = reset($definitions);

        throw_if($firstDefinition === false, InvalidArgumentException::class, 'Metric collectors must declare at least one definition.');

        $collectorIdentity = $this->collectorIdentity($firstDefinition);
        $registered = $this->collectors[$collectorIdentity] ?? null;

        throw_if($registered === null || $registered['class'] !== $collector::class, InvalidArgumentException::class, sprintf(
            'Metric collector [%s] is not the registered implementation for [%s].',
            $collector::class,
            $collectorIdentity,
        ));

        return $registered['definitions'];
    }

    /**
     * @return array<string, MetricDefinitionData>
     */
    private function validatedDefinitions(CollectsDailyMetrics $collector): array
    {
        $definitions = [];
        $collectorIdentity = null;

        foreach ($collector->definitions() as $definition) {
            throw_unless($definition instanceof MetricDefinitionData, InvalidArgumentException::class, sprintf(
                'Metric collector [%s] returned an invalid definition.',
                $collector::class,
            ));

            $definitionCollectorIdentity = $this->collectorIdentity($definition);
            $collectorIdentity ??= $definitionCollectorIdentity;

            throw_if($definitionCollectorIdentity !== $collectorIdentity, InvalidArgumentException::class, sprintf(
                'Metric collector [%s] must use one owner package and collector key.',
                $collector::class,
            ));

            $identity = $definition->identity->key();

            throw_if(isset($definitions[$identity]), InvalidArgumentException::class, sprintf(
                'Metric collector [%s] declares duplicate metric identity [%s].',
                $collector::class,
                $identity,
            ));

            $definitions[$identity] = $definition;
        }

        ksort($definitions);

        return $definitions;
    }

    private function collectorIdentity(MetricDefinitionData $definition): string
    {
        return $definition->identity->ownerPackage . ':' . $definition->identity->collectorKey;
    }
}
