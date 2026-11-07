<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Models\ExtensionQuarantine;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static void run(string $name, ?ProgressReporter $reporter = null) */
final class EnforceExtensionVerificationAction
{
    use AsFake;
    use AsObject;

    public function handle(string $name, ?ProgressReporter $reporter = null): void
    {
        if (TrustedCorePackages::contains($name)) {
            $reporter?->report(__('capell-core::extensions.verification.skipped', ['package' => $name]));

            return;
        }

        // Quarantine is refused whatever the strict setting says: it records a
        // failure that already happened, not a policy choice about this check.
        if (ExtensionQuarantine::isQuarantined($name)) {
            $reporter?->report(__('capell-core::extensions.verification.failed', ['package' => $name]));
            $description = ExtensionVerificationIssueData::describe(ExtensionVerificationIssueData::QUARANTINED, $name);

            throw new ExtensionVerificationFailedException(new ExtensionVerificationReportData(
                $name,
                null,
                null,
                null,
                false,
                [new ExtensionVerificationIssueData(ExtensionVerificationIssueData::QUARANTINED, $description['message'], $description['next_action'])],
                [],
                CarbonImmutable::now(),
            ));
        }

        $report = VerifyInstalledExtensionAction::run($name);
        if ($report->failures === []) {
            $reporter?->report(__('capell-core::extensions.verification.passed', ['package' => $name]));

            return;
        }

        if (config('capell.extensions.verification.strict', true)) {
            $reporter?->report(__('capell-core::extensions.verification.failed', ['package' => $name]));

            throw new ExtensionVerificationFailedException($report);
        }

        Log::warning('capell-core: extension.verification_failed', [
            'package' => $name,
            'failures' => array_map(static fn (ExtensionVerificationIssueData $issue): string => $issue->code, $report->failures),
        ]);
        $reporter?->report(__('capell-core::extensions.verification.continued', ['package' => $name]));
    }
}
