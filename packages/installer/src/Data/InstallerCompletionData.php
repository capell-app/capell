<?php

declare(strict_types=1);

namespace Capell\Installer\Data;

use Spatie\LaravelData\Data;

final class InstallerCompletionData extends Data
{
    public function __construct(
        public readonly ?string $adminUrl,
        public readonly string $siteUrl,
    ) {}

    public function destination(): string
    {
        return $this->adminUrl ?? $this->siteUrl;
    }
}
