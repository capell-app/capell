<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands\Concerns;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Console\Commands\Concerns\CallsRequiredCommands;

trait MigratesAdminSchema
{
    use CallsRequiredCommands;

    private function migrateAdminSchema(): bool
    {
        if (! $this->callRequired('capell:publish-migrations', [
            '--items' => CapellAdmin::getMigrations(),
            '--path' => __DIR__ . '/../../../../database/migrations',
        ])) {
            return false;
        }

        return $this->callRequired('migrate', [
            '--path' => array_map(static fn (string $migration): string => database_path('migrations/' . $migration . '.php'), CapellAdmin::getMigrations()),
            '--realpath' => true,
            '--force' => true,
        ]);
    }
}
