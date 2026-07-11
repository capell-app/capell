<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Enums\Upgrade\UpgradeStage;

interface UpgradeReporter
{
    public function stage(UpgradeStage $stage, string $message): void;

    public function line(string $message): void;

    public function info(string $message): void;

    public function warn(string $message): void;

    public function error(string $message): void;

    public function newLine(): void;

    public function confirm(string $message, bool $default = true): bool;

    /**
     * @param  array<string, string>  $options
     * @return list<string>
     */
    public function multiselect(string $label, array $options): array;

    public function commandExists(string $command): bool;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function callCommand(string $command, array $parameters = []): int;
}
