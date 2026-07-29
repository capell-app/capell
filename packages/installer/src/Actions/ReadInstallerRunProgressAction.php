<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Installer\Data\InstallerRunProgressData;
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
        $status = $this->sessions->status($installId, 'running');

        if (in_array($status, ['complete', 'failed', 'cancelled'], true)) {
            $this->sessions->clearActiveLock($installId);
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            $this->sessions->forgetSuccessSummary($installId);
        }

        return new InstallerRunProgressData(
            installId: $installId,
            status: $status,
            lines: $this->sessions->lines($installId),
            shouldRedirectToSuccess: $status === 'complete',
        );
    }
}
