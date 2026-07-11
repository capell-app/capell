<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\BlueprintSubjectEnum;
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
