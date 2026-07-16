<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Contracts\UpgradeStepContract;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class SortUpgradeStepsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, UpgradeStepContract>  $steps
     * @return list<UpgradeStepContract>
     */
    public function handle(array $steps): array
    {
        /** @var array<string, UpgradeStepContract> $stepsById */
        $stepsById = [];

        foreach ($steps as $step) {
            $stepsById[$step->id()] = $step;
        }

        if ($stepsById === []) {
            return [];
        }

        /** @var array<string, list<string>> $dependenciesById */
        $dependenciesById = [];
        /** @var array<string, list<string>> $dependentsById */
        $dependentsById = [];

        foreach ($stepsById as $stepId => $step) {
            $dependenciesById[$stepId] = array_values(array_filter(
                $step->dependsOn(),
                static fn (string $dependencyId): bool => array_key_exists($dependencyId, $stepsById),
            ));

            foreach ($dependenciesById[$stepId] as $dependencyId) {
                $dependentsById[$dependencyId] ??= [];
                $dependentsById[$dependencyId][] = $stepId;
            }
        }

        $ready = array_values(array_filter(
            array_keys($stepsById),
            static fn (string $stepId): bool => $dependenciesById[$stepId] === [],
        ));
        $this->sortStepIds($ready, $stepsById);

        $sorted = [];

        while ($ready !== []) {
            $stepId = array_shift($ready);
            $sorted[] = $stepsById[$stepId];

            foreach ($dependentsById[$stepId] ?? [] as $dependentId) {
                $dependenciesById[$dependentId] = array_values(array_diff($dependenciesById[$dependentId], [$stepId]));

                if ($dependenciesById[$dependentId] !== []) {
                    continue;
                }

                $ready[] = $dependentId;
                $this->sortStepIds($ready, $stepsById);
            }
        }

        if (count($sorted) !== count($stepsById)) {
            $remainingStepIds = array_values(array_diff(array_keys($stepsById), array_map(
                static fn (UpgradeStepContract $step): string => $step->id(),
                $sorted,
            )));

            throw new RuntimeException(sprintf(
                'Circular upgrade step dependencies detected: %s',
                implode(', ', $remainingStepIds),
            ));
        }

        return $sorted;
    }

    /**
     * @param  list<string>  $stepIds
     * @param  array<string, UpgradeStepContract>  $stepsById
     */
    private function sortStepIds(array &$stepIds, array $stepsById): void
    {
        usort(
            $stepIds,
            static function (string $firstId, string $secondId) use ($stepsById): int {
                $priority = $stepsById[$firstId]->priority() <=> $stepsById[$secondId]->priority();

                return $priority !== 0 ? $priority : $firstId <=> $secondId;
            },
        );
    }
}
