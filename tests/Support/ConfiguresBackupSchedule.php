<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Override;

trait ConfiguresBackupSchedule
{
    /**
     * Scheduling configuration is read during provider registration, before environment setup.
     *
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $app['config']->set('backup.prune_schedule_enabled', BackupScheduleOptions::$enabled);
        $app['config']->set('backup.prune_schedule_cron', BackupScheduleOptions::$cron);

        return parent::getPackageProviders($app);
    }
}
