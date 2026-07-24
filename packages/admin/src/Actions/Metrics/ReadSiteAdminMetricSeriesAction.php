<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Metrics;

use Capell\Admin\Data\Metrics\SiteAdminMetricSeriesData;
use Capell\Admin\Data\Metrics\SiteAdminMetricTrendPointData;
use Capell\Core\Actions\Metrics\ReadMetricSeriesAction;
use Capell\Core\Contracts\Metrics\MetricScopeAuthorizer;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricPointData;
use Capell\Core\Data\Metrics\MetricReadContextData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSeriesData;
use Capell\Core\Enums\Metrics\MetricDefinitionStatus;
use Capell\Core\Enums\Metrics\MetricReaderType;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Capell\Core\Support\Metrics\MetricEventRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReadSiteAdminMetricSeriesAction
{
    use AsObject;

    public const string Permission = 'View:SiteAdminMetricsPage';

    public function __construct(
        private readonly MetricCollectorRegistry $collectorRegistry,
        private readonly MetricEventRegistry $eventRegistry,
        private readonly ReadMetricSeriesAction $readMetricSeries,
    ) {}

    /**
     * @return list<SiteAdminMetricSeriesData>
     */
    public function handle(Authenticatable $actor): array
    {
        Gate::forUser($actor)->authorize(self::Permission);

        $to = CarbonImmutable::now('UTC')->startOfDay();
        $from = $to->subDays(29);
        $readerIdentifier = (string) $actor->getAuthIdentifier();
        $context = new MetricReadContextData(
            readerType: MetricReaderType::User,
            scope: MetricScopeData::global('UTC'),
            requestedAt: CarbonImmutable::now('UTC'),
            purpose: 'site admin metrics dashboard',
            readerIdentifier: $readerIdentifier,
        );
        $authorizer = $this->authorizer($readerIdentifier);

        return $this->definitions()
            ->map(fn (MetricDefinitionData $definition): SiteAdminMetricSeriesData => $this->toViewData(
                definition: $definition,
                series: $this->readMetricSeries->execute($definition, $from, $to, $context, $authorizer),
            ))
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, MetricDefinitionData>
     */
    private function definitions(): Collection
    {
        return $this->collectorRegistry->definitions()
            ->merge($this->eventRegistry->definitions())
            ->filter(static fn (MetricDefinitionData $definition): bool => $definition->status === MetricDefinitionStatus::Active
                && $definition->scopeType === MetricScopeType::Global
                && $definition->governance->visibility === MetricVisibility::SiteAdmin)
            ->sortBy(static fn (MetricDefinitionData $definition): string => $definition->labels['en'] ?? $definition->identity->metricKey);
    }

    private function authorizer(string $readerIdentifier): MetricScopeAuthorizer
    {
        return new readonly class($readerIdentifier) implements MetricScopeAuthorizer
        {
            public function __construct(private string $readerIdentifier) {}

            public function canRead(MetricDefinitionData $definition, MetricReadContextData $context): bool
            {
                return $context->readerType === MetricReaderType::User
                    && hash_equals($this->readerIdentifier, $context->readerIdentifier ?? '')
                    && $context->scope->type === MetricScopeType::Global
                    && $definition->governance->visibility === MetricVisibility::SiteAdmin;
            }
        };
    }

    private function toViewData(
        MetricDefinitionData $definition,
        MetricSeriesData $series,
    ): SiteAdminMetricSeriesData {
        $values = array_values(array_filter(
            array_map(static fn (MetricPointData $point): ?float => $point->numericValue(), $series->points),
            static fn (?float $value): bool => $value !== null,
        ));
        $maximum = max($values === [] ? [0.0] : $values);

        return new SiteAdminMetricSeriesData(
            label: $definition->labels[app()->getLocale()] ?? $definition->labels['en'] ?? __('capell-admin::metrics.unnamed'),
            description: $definition->descriptions[app()->getLocale()] ?? $definition->descriptions['en'] ?? '',
            latestValue: $this->formatValue($series->latest(), $definition),
            points: array_values(array_map(
                fn (MetricPointData $point): SiteAdminMetricTrendPointData => new SiteAdminMetricTrendPointData(
                    day: $point->day->isoFormat('ll'),
                    value: $this->formatValue($point->numericValue(), $definition),
                    heightClass: $this->heightClass($point->numericValue(), $maximum),
                ),
                $series->points,
            )),
        );
    }

    private function formatValue(?float $value, MetricDefinitionData $definition): string
    {
        if ($value === null) {
            return (string) __('capell-admin::metrics.no_value');
        }

        return match ($definition->representation->unit) {
            MetricUnitEnum::MinorCurrencyUnit => Number::currency(
                $value / (10 ** ($definition->representation->scale ?? 0)),
                $definition->representation->currency ?? 'USD',
            ),
            MetricUnitEnum::Percentage => Number::percentage($value, precision: $definition->representation->scale ?? 0),
            MetricUnitEnum::Milliseconds => __('capell-admin::metrics.milliseconds', ['value' => Number::format($value)]),
            MetricUnitEnum::Bytes => Number::fileSize((int) $value),
            default => Number::format($value, precision: $definition->representation->scale ?? 0),
        };
    }

    private function heightClass(?float $value, float $maximum): string
    {
        if ($value === null || $maximum <= 0) {
            return 'h-1';
        }

        $percentage = (int) round(($value / $maximum) * 100);

        return match (true) {
            $percentage >= 88 => 'h-full',
            $percentage >= 75 => 'h-10/12',
            $percentage >= 63 => 'h-8/12',
            $percentage >= 50 => 'h-6/12',
            $percentage >= 38 => 'h-5/12',
            $percentage >= 25 => 'h-4/12',
            $percentage >= 13 => 'h-2/12',
            default => 'h-1',
        };
    }
}
