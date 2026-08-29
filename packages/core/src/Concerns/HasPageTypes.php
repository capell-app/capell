<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\ExtensionContributionType;
use Illuminate\Support\Collection;
use InvalidArgumentException;

trait HasPageTypes
{
    /**
     * @var array<string, PageTypeData>
     */
    protected array $types = [];

    public function registerPageType(PageTypeData $type): static
    {
        $this->types[$type->name] = $type;
        if (! app()->bound(RecordsExtensionContributionReceipt::class)) {
            return $this;
        }

        resolve(RecordsExtensionContributionReceipt::class)->recordContribution(
            ExtensionContributionType::PageType,
            $type->name,
            $type->model,
            self::class,
            'runtime',
        );

        return $this;
    }

    /**
     * @return Collection<string, PageTypeData>
     */
    public function getPageTypes(): Collection
    {
        return collect($this->types);
    }

    public function getPageType(string|BlueprintSubjectEnum $name): PageTypeData
    {
        if ($name instanceof BlueprintSubjectEnum) {
            $name = $name->value;
        }

        throw_unless(isset($this->types[$name]), InvalidArgumentException::class, sprintf("Type with name '%s' does not exist.", $name));

        return $this->types[$name];
    }

    public function hasPageType(string|BlueprintSubjectEnum $name): bool
    {
        if ($name instanceof BlueprintSubjectEnum) {
            $name = $name->value;
        }

        return isset($this->types[$name]);
    }
}
