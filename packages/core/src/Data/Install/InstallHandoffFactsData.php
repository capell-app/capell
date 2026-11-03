<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

/**
 * The install facts the handoff tips are derived from, without the install
 * input itself, so a surface that only kept a summary (the browser installer's
 * completion screen) can build the same tips as the console handoff.
 */
final class InstallHandoffFactsData extends Data
{
    /**
     * @param  list<string>  $selectedPackages
     */
    public function __construct(
        public readonly bool $completed,
        public readonly bool $needsFrontendBuild,
        public readonly ?string $adminUrl,
        public readonly ?string $adminEmail,
        public readonly string $publicUrl,
        public readonly string $firstPageStatus,
        public readonly bool $demoContent,
        public readonly bool $existingAccount,
        public readonly ?string $selectedThemeKey,
        public readonly array $selectedPackages,
    ) {}
}
