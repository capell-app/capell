<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Support\Migration\TableShape;
use Capell\Core\Support\TableName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class AdoptTableCommand extends Command
{
    protected $signature = 'capell:tables:adopt
        {table : The Capell table, by base name or configured name}
        {--confirm : Record the creating migration when the table matches}
    ';

    protected $description = 'Record the creating migration of a Capell table whose migrations row was lost, after checking its columns match.';

    public function handle(): int
    {
        $given = (string) $this->argument('table');
        $base = TableName::base($given);
        $migration = TableShape::migrationFor($base);

        if ($migration === null) {
            $this->error((string) __('capell-core::install.tables.adopt_unknown', ['table' => $given]));

            return CommandAlias::FAILURE;
        }

        $physical = TableName::resolve($base);

        if (! Schema::hasTable($physical)) {
            $this->error((string) __('capell-core::install.tables.adopt_missing', ['table' => $physical]));

            return CommandAlias::FAILURE;
        }

        if (TableName::migrationRecorded($migration)) {
            $this->info((string) __('capell-core::install.tables.adopt_recorded', ['migration' => $migration]));

            return CommandAlias::SUCCESS;
        }

        if (! TableShape::matches($base, $migration)) {
            $differences = implode(', ', TableShape::differences($base, $migration));
            $this->error(__('capell-core::install.tables.adopt_mismatch', ['table' => $physical, 'migration' => $migration]) . ' (' . $differences . ')');

            return CommandAlias::FAILURE;
        }

        $arguments = ['table' => $physical, 'migration' => $migration];

        if (! $this->option('confirm')) {
            $this->warn((string) __('capell-core::install.tables.adopt_confirm', $arguments));

            return CommandAlias::FAILURE;
        }

        $repository = resolve('migrator')->getRepository();
        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $repository->log($migration, $repository->getNextBatchNumber());
        $this->info((string) __('capell-core::install.tables.adopt_done', $arguments));

        return CommandAlias::SUCCESS;
    }
}
