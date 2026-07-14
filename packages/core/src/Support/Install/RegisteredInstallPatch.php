<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Support\Patching\Patch;

final readonly class RegisteredInstallPatch
{
    public function __construct(
        public Patch $patch,
        public ?InstallPatchConfirmation $confirmation = null,
    ) {}
}
