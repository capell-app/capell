<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Contracts\UpgradeReporter;
use Capell\Core\Enums\Upgrade\UpgradeStage;

final readonly class AggregateUpgradeReporter implements UpgradeReporter
{
    /** @var list<UpgradeReporter> */
    private array $reporters;

    public function __construct(UpgradeReporter ...$reporters)
    {
        $this->reporters = array_values($reporters);
    }

    public function stage(UpgradeStage $stage, string $message): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->stage($stage, $message);
        }
    }

    public function line(string $message): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->line($message);
        }
    }

    public function info(string $message): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->info($message);
        }
    }

    public function warn(string $message): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->warn($message);
        }
    }

    public function error(string $message): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->error($message);
        }
    }

    public function newLine(): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->newLine();
        }
    }

    public function confirm(string $message, bool $default = true): bool
    {
        return $this->primary()->confirm($message, $default);
    }

    public function multiselect(string $label, array $options): array
    {
        return $this->primary()->multiselect($label, $options);
    }

    public function commandExists(string $command): bool
    {
        return $this->primary()->commandExists($command);
    }

    public function callCommand(string $command, array $parameters = []): int
    {
        return $this->primary()->callCommand($command, $parameters);
    }

    private function primary(): UpgradeReporter
    {
        return $this->reporters[0] ?? new NullUpgradeReporter;
    }
}
