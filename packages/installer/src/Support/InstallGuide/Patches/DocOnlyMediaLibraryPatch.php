<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use RuntimeException;

class DocOnlyMediaLibraryPatch implements Patch
{
    public function id(): string
    {
        return 'doc_only_media_library';
    }

    public function group(): string
    {
        return 'Manual steps';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.doc_only_media_library_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.doc_only_media_library_description');
    }

    public function docUrl(): ?string
    {
        return 'https://docs.capell.app/packages/#media-backends';
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    public function probe(): PatchStatus
    {
        return PatchStatus::Unsupported;
    }

    public function reason(): ?string
    {
        return __('capell-installer::install-guide.doc_only_media_library_reason');
    }

    public function apply(): void
    {
        throw new RuntimeException(
            'DocOnlyMediaLibraryPatch cannot be applied. Install capell-app/media-library package and run the migration command.',
        );
    }
}
