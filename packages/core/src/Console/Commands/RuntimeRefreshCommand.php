<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\RuntimeRefresh\RunRuntimeRefreshAction;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Console\Command;

final class RuntimeRefreshCommand extends Command
{
    protected $signature = 'capell:runtime-refresh';

    protected $description = 'Atomically refresh Capell deployment caches, warm critical pages, and verify runtime health';

    public function handle(RunRuntimeRefreshAction $runRuntimeRefresh): int
    {
        $this->components->info(__('capell-core::runtime-refresh.start'));

        $result = $runRuntimeRefresh->handle();

        $result->stages->each(function (RuntimeRefreshStageResultData $stage): void {
            $status = match (true) {
                $stage->skipped => __('capell-core::runtime-refresh.skipped'),
                $stage->passed => __('capell-core::runtime-refresh.passed'),
                default => __('capell-core::runtime-refresh.failed'),
            };

            $this->components->twoColumnDetail(
                sprintf('%s [%s]', $stage->label, $status),
                $stage->message,
            );
        });

        if (! $result->passed()) {
            $this->components->error(__('capell-core::runtime-refresh.failure'));

            return self::FAILURE;
        }

        $this->components->info(__('capell-core::runtime-refresh.success'));

        return self::SUCCESS;
    }
}
