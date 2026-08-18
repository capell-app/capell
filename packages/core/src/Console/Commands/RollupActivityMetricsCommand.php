<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Metrics\RollupDailyMetricsAction;
use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Metrics\ActivityBucketsDailyMetricsCollector;
use Capell\Core\Support\Metrics\ActivityVisitorsDailyMetricsCollector;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class RollupActivityMetricsCommand extends Command
{
    protected $signature = 'capell:activity:rollup {--day= : Site-local day to roll up in Y-m-d format}';

    protected $description = 'Roll up privacy-safe activity buckets into daily metrics.';

    public function handle(
        ActivitySettingsReader $settings,
        MetricCollectorRegistry $collectors,
        RollupDailyMetricsAction $rollup,
    ): int {
        try {
            if (! $settings->collectionEnabled()) {
                $this->info('Activity collection is disabled; no buckets were rolled up.');

                return self::SUCCESS;
            }

            $collectors->register(ActivityBucketsDailyMetricsCollector::class);
            $collectors->register(ActivityVisitorsDailyMetricsCollector::class);
            $day = $this->option('day');
            $day = is_string($day) && $day !== ''
                ? $day
                : CarbonImmutable::now('UTC')->subDay()->toDateString();
            $scopes = $this->scopes();

            $written = $rollup->execute(
                day: $day,
                scopes: $scopes,
                collectorKeys: [
                    ActivityBucketsDailyMetricsCollector::OWNER_PACKAGE . ':' . ActivityBucketsDailyMetricsCollector::COLLECTOR_KEY,
                    ActivityVisitorsDailyMetricsCollector::OWNER_PACKAGE . ':' . ActivityVisitorsDailyMetricsCollector::COLLECTOR_KEY,
                ],
            );
            $this->info(sprintf('Rolled up %d activity metric point(s).', $written));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<MetricScopeData> */
    private function scopes(): array
    {
        $sites = Site::query()
            ->enabled()
            ->with(['language', 'siteDomains.language'])
            ->get();

        /** @var list<MetricScopeData> $scopes */
        $scopes = [];

        foreach ($sites as $site) {
            foreach ($site->getAllLanguages() as $language) {
                if (! $language instanceof Language) {
                    continue;
                }

                $scopes[] = MetricScopeData::siteLanguage($site->uuid, $language->code, 'UTC');
            }
        }

        return $scopes;
    }
}
