<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Installer\Data\InstallerRunReportData;
use Capell\Installer\Support\InstallerRemediation;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildInstallerRunReportAction
{
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly InstallerRemediation $remediation,
    ) {}

    public function handle(string $installId): InstallerRunReportData
    {
        $inputArray = $this->sessions->input($installId);
        $inputData = is_array($inputArray) ? InstallInputData::from($inputArray) : null;
        $preflight = $this->sessions->preflightReport($installId);

        if (! is_array($preflight)) {
            $preflight = resolve(InstallerPreflight::class)->run($inputData);
        }

        $lines = $this->sessions->lines($installId);

        return new InstallerRunReportData(
            installId: $installId,
            status: $this->sessions->status($installId),
            environment: is_array($preflight['environment'] ?? null) ? $preflight['environment'] : [],
            preflight: $preflight,
            plan: $this->sessions->plan($installId),
            diagnostics: ['steps' => $this->sessions->stepDiagnostics($installId)],
            selected: [
                'packages' => $inputData->packages ?? [],
                'extraPackages' => $inputData->extraPackages ?? [],
                'languages' => $inputData->languages ?? [],
                'seedDefaultData' => $inputData->seedDefaultData ?? null,
                'demoContent' => $inputData->demoContent ?? null,
                'generateSitemap' => $inputData->generateSitemap ?? null,
                'generateStaticSite' => $inputData->generateStaticSite ?? null,
                'installFilamentPanel' => $inputData->installFilamentPanel ?? null,
                'integrateAdminPanel' => $inputData->integrateAdminPanel ?? null,
                'rebuildResources' => $inputData->rebuildResources ?? null,
                'installDeveloperTooling' => $inputData->installDeveloperTooling ?? null,
                'configureBoostDeveloperTooling' => $inputData->configureBoostDeveloperTooling ?? null,
                'additionalUsers' => collect($inputData->additionalUsers ?? [])
                    ->map(fn (NewUserData $user): array => [
                        'name' => $user->name,
                        'email' => $user->email,
                        'roleName' => $user->roleName,
                    ])
                    ->all(),
            ],
            lines: $lines,
            remediations: $this->remediation->remediationsForLines($lines),
        );
    }
}
