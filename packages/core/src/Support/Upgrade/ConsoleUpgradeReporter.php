<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Contracts\UpgradeReporter;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

final readonly class ConsoleUpgradeReporter implements UpgradeReporter
{
    public function __construct(
        private Command $command,
    ) {}

    public function stage(UpgradeStage $stage, string $message): void
    {
        //
    }

    public function line(string $message): void
    {
        $this->command->line($message);
    }

    public function info(string $message): void
    {
        $this->command->info($message);
    }

    public function warn(string $message): void
    {
        $this->command->warn($message);
    }

    public function error(string $message): void
    {
        $this->command->error($message);
    }

    public function newLine(): void
    {
        $this->command->newLine();
    }

    public function confirm(string $message, bool $default = true): bool
    {
        return confirm($message, default: $default);
    }

    public function multiselect(string $label, array $options): array
    {
        return array_values(array_map(
            static fn (int|string $value): string => (string) $value,
            multiselect(label: $label, options: $options),
        ));
    }

    public function commandExists(string $command): bool
    {
        return $this->command->getApplication()?->has($command) === true;
    }

    public function callCommand(string $command, array $parameters = []): int
    {
        return $this->command->call($command, $parameters);
    }
}
