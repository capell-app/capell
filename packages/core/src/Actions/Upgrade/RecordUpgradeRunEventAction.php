<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Models\UpgradeRunEvent;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordUpgradeRunEventAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(
        UpgradeRun $run,
        UpgradeRunEventLevel $level,
        string $message,
        ?UpgradeStage $stage = null,
        array $context = [],
        ?string $outputExcerpt = null,
    ): UpgradeRunEvent {
        return UpgradeRunEvent::query()->create([
            'upgrade_run_id' => $run->getKey(),
            'level' => $level,
            'stage' => $stage,
            'message' => $this->message($message),
            'context' => $context === [] ? null : RedactUpgradeRunContextAction::run($context),
            'output_excerpt' => $this->excerpt($outputExcerpt),
            'occurred_at' => now(),
        ]);
    }

    private function message(string $message): string
    {
        $redacted = RedactUpgradeRunContextAction::run([
            'message' => Str::limit(strip_tags($message), 255, ''),
        ]);

        return is_string($redacted['message'] ?? null) ? $redacted['message'] : Str::limit(strip_tags($message), 255, '');
    }

    private function excerpt(?string $output): ?string
    {
        $output = trim((string) $output);

        if ($output === '') {
            return null;
        }

        $redacted = RedactUpgradeRunContextAction::run([
            'output' => Str::limit($output, 4000, ''),
        ]);

        return is_string($redacted['output'] ?? null) ? $redacted['output'] : null;
    }
}
