<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\InstallHandoffData;
use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildInstallHandoffAction
{
    use AsFake;
    use AsObject;

    private const string FIRST_PAGE_DOCS_URL = 'https://docs.capell.app/getting-started/create-your-first-page/';

    private const string INSTALL_DOCS_URL = 'https://docs.capell.app/getting-started/install/';

    /**
     * @param  list<string>  $warnings
     */
    public function handle(
        InstallInputData $inputData,
        InstallRunResultData $result,
        ?string $adminUrl,
        string $firstPageStatus,
        array $warnings,
    ): InstallHandoffData {
        $doctorStatus = in_array($result->doctorStatus, ['passed', 'qualified_warning'], true)
            ? $result->doctorStatus
            : 'unknown';
        $migrationsCompleted = in_array(InstallPlan::STEP_RUN_MIGRATIONS_POST, $result->completedSteps, true);
        $setupCompleted = in_array(InstallPlan::STEP_MARK_CORE_INSTALLED, $result->completedSteps, true);
        $completed = $migrationsCompleted && $setupCompleted && $doctorStatus !== 'unknown';

        return new InstallHandoffData(
            schemaVersion: 1,
            status: $completed ? 'completed' : 'incomplete',
            selectedPackages: array_values(collect($result->selectedPackages)
                ->filter(fn (mixed $package): bool => is_string($package) && $package !== '')
                ->unique()
                ->sort()
                ->values()
                ->all()),
            outcomes: [
                'migrations' => $migrationsCompleted ? 'completed' : 'incomplete',
                'setup' => $setupCompleted ? 'completed' : 'incomplete',
                'doctor' => $doctorStatus,
            ],
            urls: [
                'admin' => $this->safeHttpUrl($adminUrl),
                'public' => $this->safeHttpUrl($inputData->siteUrl) ?? '',
            ],
            firstPage: [
                'status' => in_array($firstPageStatus, ['editable', 'present_unverified', 'missing'], true)
                    ? $firstPageStatus
                    : 'unavailable',
            ],
            warnings: array_values(collect($warnings)
                ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
                ->map(fn (string $warning): string => $this->sanitizeWarning($warning))
                ->unique()
                ->values()
                ->all()),
            nextAction: $completed
                ? [
                    'label' => 'Create and verify your first editable public page',
                    'url' => self::FIRST_PAGE_DOCS_URL,
                ]
                : [
                    'label' => 'Review installation and resolve incomplete checks',
                    'url' => self::INSTALL_DOCS_URL,
                ],
            publicImpact: [
                'summary' => $completed
                    ? 'Capell completed the selected foundation and extension setup. Public rendering remains application-owned.'
                    : 'Capell installation has not produced a complete verified handoff.',
                'accountConnection' => 'not_required',
                'telemetrySubmission' => 'not_performed',
            ],
        );
    }

    private function safeHttpUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
        ) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = is_int($parts['port'] ?? null) ? ':' . $parts['port'] : '';
        $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';

        return sprintf('%s://%s%s%s', $scheme, strtolower($parts['host']), $port, $path);
    }

    private function sanitizeWarning(string $warning): string
    {
        $sanitized = str_replace(
            array_values(array_filter([
                rtrim(base_path(), DIRECTORY_SEPARATOR),
                rtrim(storage_path(), DIRECTORY_SEPARATOR),
                rtrim(config_path(), DIRECTORY_SEPARATOR),
            ])),
            '<application>',
            trim($warning),
        );
        $sanitized = preg_replace(
            '/\b(password|token|secret|api[_-]?key)=([^\s]+)/i',
            '$1=[REDACTED]',
            $sanitized,
        );

        return mb_substr(is_string($sanitized) ? $sanitized : 'Install warning was redacted.', 0, 500);
    }
}
