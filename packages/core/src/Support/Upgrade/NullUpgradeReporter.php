<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Contracts\UpgradeReporter;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Illuminate\Support\Facades\Artisan;

final class NullUpgradeReporter implements UpgradeReporter
{
    public function stage(UpgradeStage $stage, string $message): void {}

    public function line(string $message): void {}

    public function info(string $message): void {}

    public function warn(string $message): void {}

    public function error(string $message): void {}

    public function newLine(): void {}

    public function confirm(string $message, bool $default = true): bool
    {
        return $default;
    }

    public function multiselect(string $label, array $options): array
    {
        return [];
    }

    public function commandExists(string $command): bool
    {
        return array_key_exists($command, Artisan::all());
    }

    public function callCommand(string $command, array $parameters = []): int
    {
        return Artisan::call($command, $parameters);
    }
}
