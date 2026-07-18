<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Contracts\UpgradeReporter;
use Capell\Core\Enums\Upgrade\UpgradeStage;

/** @internal */
final readonly class UpgradePipelineIo
{
    public function __construct(private UpgradeReporter $reporter) {}

    public function stage(UpgradeStage $stage, string $message): void
    {
        $this->reporter->stage($stage, $message);
    }

    public function line(string $message): void
    {
        $this->reporter->line($message);
    }

    public function info(string $message): void
    {
        $this->reporter->info($message);
    }

    public function warn(string $message): void
    {
        $this->reporter->warn($message);
    }

    public function error(string $message): void
    {
        $this->reporter->error($message);
    }

    public function newLine(): void
    {
        $this->reporter->newLine();
    }

    public function confirm(string $message, bool $default = true): bool
    {
        return $this->reporter->confirm($message, $default);
    }

    public function commandExists(string $command): bool
    {
        return $this->reporter->commandExists($command);
    }

    public function callCommand(string $command): int
    {
        return $this->reporter->callCommand($command);
    }
}
