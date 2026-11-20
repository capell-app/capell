<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Override;

trait RecordsBootQueries
{
    /**
     * Record queries run while the application registers or boots its providers
     * (including booted callbacks), ignoring those the test itself triggers later.
     *
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        BootQueryLog::$queries = [];

        $app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event): void {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === Application::class
                    && in_array($frame['function'], ['register', 'bootProvider', 'fireAppCallbacks'], true)) {
                    BootQueryLog::$queries[] = $event->sql;

                    return;
                }
            }
        });

        return parent::getPackageProviders($app);
    }
}
