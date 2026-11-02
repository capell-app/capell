<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static void run(string $name, ?ProgressReporter $reporter = null) */
final class EnforceExtensionVerificationAction
{
    use AsObject;

    public function handle(string $name, ?ProgressReporter $reporter = null): void
    {
        if (TrustedCorePackages::contains($name)) {
            $reporter?->report(__('capell-core::extensions.verification.skipped', ['package' => $name]));

            return;
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
