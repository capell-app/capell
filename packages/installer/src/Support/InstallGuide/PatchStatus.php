<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide;

use Filament\Support\Contracts\HasLabel;

enum PatchStatus: string implements HasLabel
{
    case Applicable = 'applicable';
    case AlreadyApplied = 'already_applied';
    case Customised = 'customised';
    case Unsupported = 'unsupported';

    public function getLabel(): string
    {
        return match ($this) {
            self::Applicable => __('capell-installer::install-guide.status_applicable'),
            self::AlreadyApplied => __('capell-installer::install-guide.status_already_applied'),
            self::Customised => __('capell-installer::install-guide.status_customised'),
            self::Unsupported => __('capell-installer::install-guide.status_unsupported'),
        };
    }
}
