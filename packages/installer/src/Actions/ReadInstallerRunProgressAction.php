<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Installer\Data\InstallerRunProgressData;
use Capell\Installer\Enums\InstallerRunStatus;
use Capell\Installer\Support\InstallerSessionRepository;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReadInstallerRunProgressAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
    ) {}

    public function handle(string $installId): InstallerRunProgressData
    {
        $status = $this->sessions->status($installId, InstallerRunStatus::Running->value);

        if (InstallerRunStatus::tryFrom($status)?->isTerminal() === true) {
            $this->sessions->run($installId)->releaseLock();
        }

        if (in_array($status, [InstallerRunStatus::Failed->value, InstallerRunStatus::Cancelled->value], true)) {
            $this->sessions->forgetSuccessSummary($installId);
        }

        return new InstallerRunProgressData(
            installId: $installId,
            status: $status,
            lines: $this->sessions->lines($installId),
            shouldRedirectToSuccess: $status === InstallerRunStatus::Complete->value,
            resumableStep: $this->resumableBrowserStep($installId, $status),
        );
    }

    private function resumableBrowserStep(string $installId, string $status): ?string
    {
        if (! in_array($status, [InstallerRunStatus::Pending->value, InstallerRunStatus::Running->value], true)
            || ! $this->sessions->ownsRun($installId)
            || $this->sessions->input($installId) === null) {
            return null;
        }

        // Only browser-driven runs store step input and a plan for the next request.
        return $this->sessions->expectedStepKey($installId, $this->sessions->plan($installId));
    }
}
