<?php

declare(strict_types=1);

namespace Capell\Admin\Data\AdminPanelIntegration;

use Spatie\LaravelData\Data;

final class AdminPanelCandidateData extends Data
{
    public function __construct(
        public readonly string $path,
        public readonly string $relativePath,
        public readonly string $className,
        public readonly ?string $panelId,
        public readonly bool $alreadyIntegrated,
        public readonly bool $registered,
    ) {}

    public function label(): string
    {
        return ($this->panelId ?? class_basename($this->className)) . ': ' . $this->relativePath;
    }
}
