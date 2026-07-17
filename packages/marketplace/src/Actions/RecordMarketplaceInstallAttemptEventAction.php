<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallAttemptEvent;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecordMarketplaceInstallAttemptEventAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptEventLevel $level,
        string $message,
        ?MarketplaceInstallFailureStage $stage = null,
        array $context = [],
        ?string $outputExcerpt = null,
    ): MarketplaceInstallAttemptEvent {
        return MarketplaceInstallAttemptEvent::query()->create([
            'marketplace_install_attempt_id' => $attempt->getKey(),
            'level' => $level,
            'stage' => $stage,
            'message' => Str::limit($message, 255, ''),
            'context' => $context === [] ? null : RedactMarketplaceDiagnosticContextAction::run($context),
            'output_excerpt' => $this->excerpt($outputExcerpt),
            'occurred_at' => now(),
        ]);
    }

    private function excerpt(?string $output): ?string
    {
        $output = trim((string) $output);

        if ($output === '') {
            return null;
        }

        $redacted = RedactMarketplaceDiagnosticContextAction::run([
            'output' => Str::limit($output, 4000, ''),
        ]);

        return is_string($redacted['output'] ?? null) ? $redacted['output'] : null;
    }
}
