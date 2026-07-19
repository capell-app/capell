<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Contracts\RuntimeRefreshWarmer;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class WarmRuntimeAction
{
    use AsObject;

    public function __construct(private readonly Application $application) {}

    public function handle(): RuntimeRefreshStageResultData
    {
        $warmers = $this->application->tagged(RuntimeRefreshWarmer::TAG);
        $completed = [];
        $failures = [];

        foreach ($warmers as $warmer) {
            if (! $warmer instanceof RuntimeRefreshWarmer) {
                continue;
            }

            try {
                $warmer->warm();
                $completed[] = $warmer->label();
            } catch (Throwable $throwable) {
                $failures[] = sprintf('%s: %s', $warmer->label(), $throwable->getMessage());
            }
        }

        if ($completed === [] && $failures === []) {
            return new RuntimeRefreshStageResultData(
                key: 'warm',
                label: 'Critical runtime pages',
                passed: true,
                message: 'No runtime warmers are registered.',
                skipped: true,
            );
        }

        return new RuntimeRefreshStageResultData(
            key: 'warm',
            label: 'Critical runtime pages',
            passed: $failures === [],
            message: $failures === []
                ? sprintf('Warmed: %s.', implode(', ', $completed))
                : sprintf('Warm failures: %s', implode('; ', $failures)),
        );
    }
}
