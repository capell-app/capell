<?php

declare(strict_types=1);

namespace Capell\Installer\Data;

use Spatie\LaravelData\Data;

final class InstallerPageData extends Data
{
    /**
     * @param  array<string, mixed>  $viewData
     */
    public function __construct(
        public readonly array $viewData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toViewData(): array
    {
        return $this->viewData;
    }
}
