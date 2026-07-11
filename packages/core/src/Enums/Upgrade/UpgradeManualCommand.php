<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Upgrade;

enum UpgradeManualCommand: string
{
    case DryRun = 'php artisan capell:upgrade --force --no-clear-cache --dry-run';
    case Run = 'php artisan capell:upgrade --force --no-clear-cache';

    public static function forDryRun(bool $dryRun): self
    {
        return $dryRun ? self::DryRun : self::Run;
    }
}
