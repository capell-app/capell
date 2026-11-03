<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Models\ExtensionQuarantine;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Release one quarantined package, but only when it verifies now.
 *
 * @method static ExtensionVerificationReportData run(string $composerName)
 */
final class ReleaseExtensionQuarantineAction
{
    use AsFake;
    use AsObject;

    public function handle(string $composerName): ExtensionVerificationReportData
    {
        $report = VerifyInstalledExtensionAction::run($composerName);
        throw_unless($report->passed, ExtensionVerificationFailedException::class, $report);

        ExtensionQuarantine::query()->where('composer_name', $composerName)->delete();

        return $report;
    }
}
