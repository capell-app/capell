<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

final class BackupScheduleOptions
{
    public static bool $enabled = false;

    public static mixed $cron = '0 3 * * 1';
}
