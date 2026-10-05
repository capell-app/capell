<?php

declare(strict_types=1);

namespace Capell\Core\Support\Publishing;

use Capell\Core\Contracts\Publishing\PublicationReadinessContributor;
use Capell\Core\Data\Publishing\PublicationReadinessCheckData;
use Capell\Core\Data\Publishing\PublicationReadinessContextData;
use Capell\Core\Models\Contracts\Publishable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class PublicationReadinessRegistry
{
    /** @var list<PublicationReadinessContributor> */
    private array $contributors = [];

    private int $taggedContributorCount = 0;

    /** @var list<int> */
    private array $taggedContributorIndexes = [];

    private readonly Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new \Illuminate\Container\Container;
    }

    public function register(PublicationReadinessContributor $contributor): self
    {
        $this->contributors[] = $contributor;

        return $this;
    }

    /** @return list<PublicationReadinessContributor> */
    public function contributors(): array
    {
        $this->discoverTaggedContributors();

        return $this->contributors;
    }

    /** @return list<PublicationReadinessCheckData> */
    public function checks(Model&Publishable $record, PublicationReadinessContextData $context): array
    {
        $this->discoverTaggedContributors();
        $checks = [];
        $ids = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor->supports($record)) {
                continue;
            }

            foreach ($contributor->checks($record, $context) as $check) {
                throw_if($check->id === '' || isset($ids[$check->id]), InvalidArgumentException::class, 'Publication readiness check IDs must be non-empty and unique.');

                $ids[$check->id] = true;
                $checks[] = $check;
            }
        }

        return $checks;
    }

    /** @return list<string> */
    public function blockingCheckIds(Model&Publishable $record, PublicationReadinessContextData $context): array
    {
        return array_values(array_map(
            static fn (PublicationReadinessCheckData $check): string => $check->id,
            array_filter($this->checks($record, $context), static fn (PublicationReadinessCheckData $check): bool => $check->blocking),
        ));
    }

    public function clear(): void
    {
        $this->contributors = [];
        $this->taggedContributorCount = 0;
        $this->taggedContributorIndexes = [];
    }

    private function discoverTaggedContributors(): void
    {
        $all = iterator_to_array($this->container->tagged(PublicationReadinessContributor::TAG));
        $contributors = array_slice($all, $this->taggedContributorCount);
        if ($contributors === []) {
            return;
        }

        $validatedContributors = [];

        foreach ($contributors as $contributor) {
            throw_unless($contributor instanceof PublicationReadinessContributor, LogicException::class, 'Tagged publication readiness contributors must implement the publication readiness contributor contract.');
            $validatedContributors[] = $contributor;
        }

        $tagged = array_map(fn (int $index): PublicationReadinessContributor => $this->contributors[$index], $this->taggedContributorIndexes);
        foreach ($validatedContributors as $contributor) {
            $this->taggedContributorIndexes[] = count($this->contributors);
            $this->contributors[] = $contributor;
            $tagged[] = $contributor;
        }

        usort($tagged, static fn (PublicationReadinessContributor $left, PublicationReadinessContributor $right): int => $left::class <=> $right::class);
        $ordered = $this->contributors;
        foreach ($this->taggedContributorIndexes as $position => $index) {
            $ordered[$index] = $tagged[$position];
        }

        $this->contributors = array_values($ordered);
        $this->taggedContributorCount = count($all);
    }
}
