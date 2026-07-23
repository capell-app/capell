<?php

declare(strict_types=1);

namespace Capell\Core\Support\Metrics;

use Capell\Core\Actions\Metrics\StoreMetricEventAction;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Carbon\CarbonImmutable;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Lottery;
use InvalidArgumentException;
use Throwable;

final class MetricsManager
{
    public function __construct(
        private readonly MetricEventRegistry $registry,
        private readonly StoreMetricEventAction $storeMetricEvent,
    ) {}

    public function register(MetricDefinitionData $definition): self
    {
        $this->registry->register($definition);

        return $this;
    }

    public function increment(
        string $metric,
        int $sampleEvery = 1,
        ?MetricScopeData $scope = null,
        ?CarbonImmutable $occurredAt = null,
    ): void {
        $this->record($metric, 1, $sampleEvery, $scope, $occurredAt);
    }

    public function record(
        string $metric,
        int $value,
        int $sampleEvery = 1,
        ?MetricScopeData $scope = null,
        ?CarbonImmutable $occurredAt = null,
    ): void {
        try {
            if ($value < 1 || $sampleEvery < 1) {
                throw new InvalidArgumentException('Metric event value and sampling interval must be positive integers.');
            }

            $definition = $this->registry->definition($metric);

            if (! Lottery::odds(1, $sampleEvery)->choose()) {
                return;
            }

            $resolvedScope = $scope ?? MetricScopeData::global('UTC');
            $resolvedOccurrence = ($occurredAt ?? CarbonImmutable::now('UTC'))->utc();

            defer(function () use ($definition, $value, $sampleEvery, $resolvedScope, $resolvedOccurrence): void {
                try {
                    $this->storeMetricEvent->execute(
                        $definition,
                        $value,
                        $sampleEvery,
                        $resolvedScope,
                        $resolvedOccurrence,
                    );
                } catch (Throwable $throwable) {
                    $this->logFailure($throwable);
                }
            });
        } catch (Throwable $throwable) {
            $this->logFailure($throwable);
        }
    }

    private function logFailure(Throwable $throwable): void
    {
        Log::warning('Metric event could not be recorded.', [
            'exception_class' => $throwable::class,
        ]);
    }
}
