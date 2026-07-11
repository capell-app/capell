<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionHealthAlertData;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildExtensionRiskScoreAction
{
    use AsAction;

    /**
     * @param  list<ExtensionHealthAlertData>  $alerts
     * @param  list<string>  $missingRequiredTables
     */
    public function handle(
        bool $runtimeAllowed,
        array $alerts,
        array $missingRequiredTables,
        bool $premiumMissingMarketplaceAccount,
        bool $updateAvailable,
        bool $blocked,
        bool $canUninstall,
    ): int {
        $score = 0;

        foreach ($alerts as $alert) {
            $score += match ($alert->severity) {
                'critical' => 40,
                'warning' => 20,
                default => 5,
            };
        }

        if (! $runtimeAllowed || $blocked) {
            $score += 45;
        }

        if ($missingRequiredTables !== []) {
            $score += 25;
        }

        if ($premiumMissingMarketplaceAccount) {
            $score += 20;
        }

        if ($updateAvailable) {
            $score += 10;
        }

        if (! $canUninstall) {
            $score += 5;
        }

        return min($score, 100);
    }
}
