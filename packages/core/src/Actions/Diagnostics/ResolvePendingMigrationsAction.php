<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Diagnostics;

use Illuminate\Database\Migrations\Migrator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePendingMigrationsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Migrator $migrator) {}

    /**
     * Names of the migrations `php artisan migrate` would run now.
     *
     * @return list<string>
     */
    public function handle(): array
    {
        $files = $this->migrator->getMigrationFiles([...$this->migrator->paths(), database_path('migrations')]);
        $repository = $this->migrator->getRepository();
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];

        return array_values(array_diff(array_keys($files), $ran));
    }
}
