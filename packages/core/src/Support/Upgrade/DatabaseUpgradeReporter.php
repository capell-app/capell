<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Actions\Upgrade\RecordUpgradeRunEventAction;
use Capell\Core\Contracts\UpgradeReporter;
use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Models\UpgradeRun;
use Illuminate\Support\Facades\Artisan;

final class DatabaseUpgradeReporter implements UpgradeReporter
{
    private ?UpgradeStage $stage = null;

    public function __construct(
        private readonly UpgradeRun $run,
    ) {}

    public function stage(UpgradeStage $stage, string $message): void
    {
        $this->stage = $stage;
        $this->run->forceFill(['current_stage' => $stage])->save();

        RecordUpgradeRunEventAction::run(
            run: $this->run,
            level: UpgradeRunEventLevel::Info,
            message: $message,
            stage: $stage,
        );
    }

    public function line(string $message): void
    {
        $this->record(UpgradeRunEventLevel::Info, $message);
    }

    public function info(string $message): void
    {
        $this->record(UpgradeRunEventLevel::Info, $message);
    }

    public function warn(string $message): void
    {
        $this->record(UpgradeRunEventLevel::Warning, $message);
    }

    public function error(string $message): void
    {
        $this->record(UpgradeRunEventLevel::Error, $message);
    }

    public function newLine(): void
    {
        //
    }

    public function confirm(string $message, bool $default = true): bool
    {
        return $default;
    }

    public function multiselect(string $label, array $options): array
    {
        return array_values(array_map(strval(...), array_keys($options)));
    }

    public function commandExists(string $command): bool
    {
        return array_key_exists($command, Artisan::all());
    }

    public function callCommand(string $command, array $parameters = []): int
    {
        return Artisan::call($command, $parameters);
    }

    private function record(UpgradeRunEventLevel $level, string $message): void
    {
        RecordUpgradeRunEventAction::run(
            run: $this->run,
            level: $level,
            message: strip_tags($message),
            stage: $this->stage,
            outputExcerpt: $message,
        );
    }
}
