<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

final class ManifestContractsCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.manifest-v3.valid';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $results = AuditExtensionContractsAction::run();
        $errors = array_filter($results, static fn (array $result): bool => $result['severity'] === 'error');

        if ($errors !== []) {
            return new DoctorCheckResultData('Manifest v3 contracts', false, sprintf('%d manifest contract error(s).', count($errors)), 'Run php artisan capell:extension-audit.');
        }

        $warnings = array_filter($results, static fn (array $result): bool => $result['severity'] === 'warning');

        if ($warnings !== []) {
            return new DoctorCheckResultData('Manifest v3 contracts', true, sprintf('%d manifest contract warning(s).', count($warnings)), 'Run php artisan capell:extension-audit.');
        }

        return new DoctorCheckResultData('Manifest v3 contracts', true, 'No extension manifest contract errors found.');
    }
}
