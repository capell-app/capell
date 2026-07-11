<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Data\PageVariationData;
use Capell\Core\Exceptions\InvalidPageModelException;
use Illuminate\Database\Eloquent\Model;

trait HasPageVariation
{
    /**
     * @var array<string, PageVariationData>
     */
    protected array $pageVariations = [];

    /**
     * Register a page type and model.
     */
    public function registerPageVariation(PageVariationData $pageData): static
    {
        $invalid = ! class_exists($pageData->model) || ! is_subclass_of($pageData->model, Model::class);

        throw_if($invalid, InvalidPageModelException::class, sprintf('Invalid page class: %s', $pageData->model));

        $this->pageVariations[$pageData->name] = $pageData;

        return $this;
    }

    public function getPageVariation(string $name): ?PageVariationData
    {
        return $this->pageVariations[$name] ?? null;
    }

    public function hasPageVariation(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        return isset($this->pageVariations[$name]);
    }

    /**
     * @return array<string, PageVariationData>
     */
    public function getPageVariations(): array
    {
        return $this->pageVariations;
    }

    /**
     * @return array<int, string>
     */
    public function getPageVariationNames(): array
    {
        return array_keys($this->pageVariations);
    }

    /**
     * @return array<int, class-string<Model>>
     */
    public function getPageVariationModels(): array
    {
        return array_values(array_map(fn (PageVariationData $page): string => $page->model, $this->pageVariations));
    }
}
