<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Installer\Data\ActiveInstallData;
use Capell\Installer\Support\InstallerSessionRepository;
use Lorisleiva\Actions\Concerns\AsObject;

final class GetActiveInstallAction
{
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
    ) {}

    public function handle(): ?ActiveInstallData
    {
        return $this->sessions->activeInstallData();
    }
}
