<?php

declare(strict_types=1);

use Capell\Installer\Support\InstallGuide\PatchStatus;

it('exposes translated labels for install guide patch statuses', function (): void {
    expect(PatchStatus::Applicable->getLabel())->toBe(__('capell-installer::install-guide.status_applicable'))
        ->and(PatchStatus::AlreadyApplied->getLabel())->toBe(__('capell-installer::install-guide.status_already_applied'))
        ->and(PatchStatus::Customised->getLabel())->toBe(__('capell-installer::install-guide.status_customised'))
        ->and(PatchStatus::Unsupported->getLabel())->toBe(__('capell-installer::install-guide.status_unsupported'));
});
