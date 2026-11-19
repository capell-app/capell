<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Contracts\SiteSpec\SiteSpecExporter;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Contracts\Container\Container;
use LogicException;

/** @extends AbstractKeyedRegistry<SiteSpecExporter> */
final class SiteSpecExporterRegistry extends AbstractKeyedRegistry
{
    private int $taggedExporterCount = 0;

    public function __construct(private readonly Container $container) {}

    public function register(SiteSpecExporter $exporter): void
    {
        $key = $exporter->key();

        throw_if($key === '', LogicException::class, 'Site spec exporter keys must not be empty.');
        throw_if($this->hasItem($key), LogicException::class, sprintf('A site spec exporter is already registered for [%s].', $key));

        $this->setItem($key, $exporter);
    }

    /** @return list<SiteSpecExporter> Exporters in key order, so an export does not depend on the order packages booted. */
    public function all(): array
    {
        $this->discoverTaggedExporters();

        $exporters = $this->allItems();
        ksort($exporters);

        return array_values($exporters);
    }

    private function discoverTaggedExporters(): void
    {
        $tagged = iterator_to_array($this->container->tagged(SiteSpecExporter::TAG), false);

        foreach (array_slice($tagged, $this->taggedExporterCount) as $exporter) {
            if ($exporter instanceof SiteSpecExporter) {
                $this->register($exporter);
            }

            $this->taggedExporterCount++;
        }
    }
}
