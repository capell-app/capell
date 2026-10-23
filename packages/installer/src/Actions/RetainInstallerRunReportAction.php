<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Support\Install\InstallReportRedactor;
use Capell\Installer\Support\InstallerSessionRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Lorisleiva\Actions\Concerns\AsObject;

final class RetainInstallerRunReportAction
{
    use AsObject;

    public function __construct(private readonly InstallerSessionRepository $sessions) {}

    public function handle(string $installId): void
    {
        Cache::lock($this->sessions->key($installId, 'output-lock'), 30)->block(5, function () use ($installId): void {
            if ($this->sessions->has($this->sessions->key($installId, 'terminal_report'))) {
                return;
            }

            // Do not run new preflight checks while cancelling an incomplete session.
            if ($this->sessions->preflightReport($installId) === null) {
                $this->sessions->putPreflightReport($installId, []);
            }

            $reportData = BuildInstallerRunReportAction::run($installId);
            $report = $reportData->toPayload();
            $report['status'] = 'cancelled';
            $report['diagnostics'] = [...$reportData->diagnostics, 'completedSteps' => $this->sessions->completedSteps($installId)];
            $secrets = InstallReportRedactor::secrets($this->sessions->input($installId) ?? []);
            // Only an encrypted redaction dictionary survives; executable input is purged.
            $this->sessions->put($this->sessions->key($installId, 'report_redaction'), Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR)));
            $this->sessions->put($this->sessions->key($installId, 'terminal_report'), InstallReportRedactor::redact($report, $secrets));
        });
    }
}
