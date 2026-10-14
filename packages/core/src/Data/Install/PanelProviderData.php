<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

final readonly class PanelProviderData
{
    public function __construct(
        public string $path,
        public string $relativePath,
        public string $className,
        public string $panelId,
    ) {}

    public function matches(string $selection): bool
    {
        return in_array($selection, [$this->path, $this->relativePath, $this->className, class_basename($this->className), $this->panelId], true);
    }
}
