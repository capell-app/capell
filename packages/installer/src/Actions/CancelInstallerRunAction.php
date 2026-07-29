<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Installer\Support\InstallerSessionRepository;
use Lorisleiva\Actions\Concerns\AsObject;

final class CancelInstallerRunAction
{
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
    ) {}

    public function handle(string $installId): void
    {
        $this->sessions->clearActiveLock($installId);
        $this->sessions->clearInstallSession($installId);
    }
}
