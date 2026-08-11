<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Health\RunHealthCheckAction;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Console\Command;

final class HealthCheckCommand extends Command
{
    protected $signature = 'capell:health-check {id}';

    protected $description = 'Run one registered Capell health check.';

    protected $hidden = true;

    public function handle(RunHealthCheckAction $action): int
    {
        $this->line(JsonCodec::encode($action->handle((string) $this->argument('id'))->toArray()));

        return self::SUCCESS;
    }
}
