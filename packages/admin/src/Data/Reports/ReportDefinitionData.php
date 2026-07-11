<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Reports;

use Capell\Admin\Contracts\Reports\CapellReportPage;
use Spatie\LaravelData\Data;

final class ReportDefinitionData extends Data
{
    /**
     * @param  class-string<CapellReportPage>  $pageClass
     * @param  list<string>  $capabilityTags
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $package,
        public readonly string $category,
        public readonly string $pageClass,
        public readonly bool $defaultEnabled = true,
        public readonly int $navigationSort = 100,
        public readonly array $capabilityTags = [],
    ) {}

    public function settingsKey(): string
    {
        return $this->key;
    }

    public function resolvedLabel(): string
    {
        return str_contains($this->label, '::')
            ? (string) __($this->label)
            : $this->label;
    }

    public function resolvedDescription(): string
    {
        return str_contains($this->description, '::')
            ? (string) __($this->description)
            : $this->description;
    }
}
