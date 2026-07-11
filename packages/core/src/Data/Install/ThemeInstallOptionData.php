<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class ThemeInstallOptionData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $packageName = null,
        public readonly ?string $previewImageUrl = null,
        public readonly bool $static = false,
    ) {}

    public function consoleLabel(): string
    {
        if ($this->description === null || $this->description === '') {
            return $this->name;
        }

        return sprintf('%s - %s', $this->name, $this->description);
    }
}
