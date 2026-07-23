<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Metrics;

use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class RollupDailyMetricsAction
{
    public function __construct(
        private readonly MetricCollectorRegistry $collectors,
        private readonly StoreMetricCollectionRunAction $storeRun,
        private readonly StoreMetricDailyRollupAction $storeRollup,
    ) {}

    /**
     * @param  list<MetricScopeData>  $scopes
     */
    public function execute(string $day, array $scopes): int
    {
        $this->assertDay($day);
        $written = 0;

        foreach ($this->collectors->collectors() as $collector) {
            $definitions = $this->collectors->definitionsFor($collector);
            $collectorScopes = $this->scopesForDefinitions($definitions, $scopes);
            $definitionHash = $this->definitionSetHash($definitions);
            $firstDefinition = reset($definitions);

            throw_if($firstDefinition === false, InvalidArgumentException::class, 'Registered metric collectors require definitions.');

            try {
                $result = $collector->collect($day, $collectorScopes);
                $this->validateResult($result, $definitions, $collectorScopes);
            } catch (Throwable $exception) {
                $this->recordIncompleteRun(
                    $day,
                    $firstDefinition,
                    $definitionHash,
                    MetricCollectionRunStatus::Failed,
                    $exception->getMessage(),
                );

                continue;
            }

            if ($result->status !== MetricCollectionStatus::Complete) {
                $this->recordIncompleteRun(
                    $day,
                    $firstDefinition,
                    $definitionHash,
                    $result->status === MetricCollectionStatus::Unsupported
                        ? MetricCollectionRunStatus::Unsupported
                        : MetricCollectionRunStatus::Failed,
                    $result->reason ?? 'Metric collection did not complete.',
                    $result->sourceWatermark,
                );

                continue;
            }

            try {
                $written += DB::transaction(function () use ($day, $definitions, $definitionHash, $firstDefinition, $result): int {
                    $startedAt = CarbonImmutable::now('UTC');
                    $run = $this->storeRun->execute(
                        day: $day,
                        ownerPackage: $firstDefinition->identity->ownerPackage,
                        collectorKey: $firstDefinition->identity->collectorKey,
                        definitionHash: $definitionHash,
                        status: MetricCollectionRunStatus::Started,
                        startedAt: $startedAt,
                    );

                    MetricDailyRollup::query()
                        ->whereDate('day', $day)
                        ->where('owner_package', $firstDefinition->identity->ownerPackage)
                        ->where('collector_key', $firstDefinition->identity->collectorKey)
                        ->delete();

                    foreach ($result->samples as $sample) {
                        $definition = $definitions[$sample->identity->key()];

                        $this->storeRollup->execute(
                            run: $run,
                            definition: $definition,
                            day: $day,
                            scope: $sample->scope,
                            state: $this->isZero($sample) ? MetricPointState::Zero : MetricPointState::Present,
                            value: $sample->value,
                        );
                    }

                    $this->storeRun->execute(
                        day: $day,
                        ownerPackage: $firstDefinition->identity->ownerPackage,
                        collectorKey: $firstDefinition->identity->collectorKey,
                        definitionHash: $definitionHash,
                        status: MetricCollectionRunStatus::Completed,
                        startedAt: $startedAt,
                        completedAt: CarbonImmutable::now('UTC'),
                        sourceWatermark: $result->sourceWatermark,
                        sourceChecksum: $result->sourceChecksum,
                        run: $run,
                    );

                    return count($result->samples);
                });
            } catch (Throwable $exception) {
                $this->recordIncompleteRun(
                    $day,
                    $firstDefinition,
                    $definitionHash,
                    MetricCollectionRunStatus::Failed,
                    $exception->getMessage(),
                    $result->sourceWatermark,
                );
            }
        }

        return $written;
    }

    /**
     * @param  array<string, MetricDefinitionData>  $definitions
     * @param  list<MetricScopeData>  $scopes
     * @return list<MetricScopeData>
     */
    private function scopesForDefinitions(array $definitions, array $scopes): array
    {
        $supportedTypes = array_unique(array_map(
            static fn (MetricDefinitionData $definition): string => $definition->scopeType->value,
            $definitions,
        ));

        return array_values(array_filter(
            $scopes,
            static fn (MetricScopeData $scope): bool => in_array($scope->type->value, $supportedTypes, true),
        ));
    }

    /**
     * @param  array<string, MetricDefinitionData>  $definitions
     */
    private function definitionSetHash(array $definitions): string
    {
        return hash('sha256', json_encode(array_map(
            static fn (MetricDefinitionData $definition): string => $definition->semanticHash(),
            $definitions,
        ), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, MetricDefinitionData>  $definitions
     * @param  list<MetricScopeData>  $requestedScopes
     */
    private function validateResult(
        MetricCollectionResultData $result,
        array $definitions,
        array $requestedScopes,
    ): void {
        if ($result->status !== MetricCollectionStatus::Complete) {
            return;
        }

        $requestedScopeKeys = array_map(
            static fn (MetricScopeData $scope): string => $scope->key(),
            $requestedScopes,
        );
        $coveredScopeKeys = array_map(
            static fn (MetricScopeData $scope): string => $scope->key(),
            $result->coveredScopes,
        );
        sort($requestedScopeKeys);
        sort($coveredScopeKeys);

        throw_if($coveredScopeKeys !== $requestedScopeKeys, InvalidArgumentException::class, 'Complete metric collections must cover every requested scope.');

        $expectedSamples = [];

        foreach ($definitions as $definition) {
            foreach ($requestedScopes as $scope) {
                if ($scope->type === $definition->scopeType) {
                    $expectedSamples[] = $definition->identity->key() . ':' . $scope->key();
                }
            }
        }

        $actualSamples = [];

        foreach ($result->samples as $sample) {
            $definition = $definitions[$sample->identity->key()] ?? null;

            throw_unless($definition instanceof MetricDefinitionData, InvalidArgumentException::class, sprintf(
                'Metric collection returned undeclared identity [%s].',
                $sample->identity->key(),
            ));
            throw_if($sample->definitionHash !== $definition->semanticHash()
                || $sample->representation->toArray() !== $definition->representation->toArray(), InvalidArgumentException::class, sprintf(
                    'Metric sample [%s] does not match its definition.',
                    $sample->identity->key(),
                ));

            $actualSamples[] = $sample->identity->key() . ':' . $sample->scope->key();
        }

        sort($expectedSamples);
        sort($actualSamples);

        throw_if($actualSamples !== $expectedSamples, InvalidArgumentException::class, 'Complete metric collections must emit exactly one sample per definition and covered scope.');
    }

    private function recordIncompleteRun(
        string $day,
        MetricDefinitionData $definition,
        string $definitionHash,
        MetricCollectionRunStatus $status,
        string $reason,
        ?string $sourceWatermark = null,
    ): void {
        $now = CarbonImmutable::now('UTC');
        $errorSummary = trim($reason) !== '' ? $reason : 'Metric collection failed without an error message.';

        $this->storeRun->execute(
            day: $day,
            ownerPackage: $definition->identity->ownerPackage,
            collectorKey: $definition->identity->collectorKey,
            definitionHash: $definitionHash,
            status: $status,
            startedAt: $now,
            completedAt: $now,
            sourceWatermark: $status === MetricCollectionRunStatus::Failed ? $sourceWatermark : null,
            errorSummary: mb_substr($errorSummary, 0, 1000),
        );
    }

    private function assertDay(string $day): void
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');

        throw_if($parsed === null || $parsed->format('Y-m-d') !== $day, InvalidArgumentException::class, 'Metric rollup day must use YYYY-MM-DD.');
    }

    private function isZero(MetricSampleData $sample): bool
    {
        return ($sample->value->integer ?? $sample->value->minorUnits) === 0
            || ($sample->value->decimal !== null && preg_match('/\A0(?:\.0+)?\z/', $sample->value->decimal) === 1);
    }
}
