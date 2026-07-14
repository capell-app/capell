<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

/**
 * Optional interactive confirmation attached to a registered install patch.
 * When present, the install command asks before applying the patch and reports
 * the skipped message when the user declines.
 */
final readonly class InstallPatchConfirmation
{
    public function __construct(
        public string $label,
        public ?string $hint = null,
        public bool $default = true,
        public ?string $skippedMessage = null,
    ) {}
}
