<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Data\PageVariationData;
use Capell\Core\Exceptions\InvalidPageModelException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

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

        $this->ensureMorphAlias($pageData->model);

        return $this;
    }

    /**
     * Re-assert a morph alias for every registered page variation.
     *
     * Must run after every provider has booted. Registering an alias when the
     * variation is declared is not enough: morphMap() merges, so a package
     * booting later silently overwrites a key it happens to derive the same
     * name for, and the earlier model is left unaddressable.
     */
    public function ensurePageVariationMorphAliases(): static
    {
        foreach ($this->pageVariations as $pageVariation) {
            $this->ensureMorphAlias($pageVariation->model);
        }

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

    /**
     * Guarantee the model is addressable in the morph map.
     *
     * A page variation is by definition a `pageable` morph target and consumers
     * call getMorphClass() on it — PageUrlsTable derives its type filter
     * straight from getPageVariations(). Aliases are derived from
     * class_basename across ~90 packages, so short names collide: access-gate
     * and events both claim `event`, whichever registers last wins the key, and
     * the loser is left with no alias at all. getMorphClass() then throws
     * ClassMorphViolationException and /admin/page-urls returned HTTP 500.
     *
     * Never steal a key that already belongs to another class — those aliases
     * are persisted in morph columns. Fall back to a package-qualified alias so
     * the model is always resolvable.
     *
     * @param  class-string<Model>  $model
     */
    private function ensureMorphAlias(string $model): void
    {
        $morphMap = Relation::morphMap() ?? [];

        if (in_array($model, $morphMap, true)) {
            return;
        }

        $preferredAlias = Str::snake(class_basename($model));

        if (! array_key_exists($preferredAlias, $morphMap)) {
            Relation::morphMap([$preferredAlias => $model], merge: true);

            return;
        }

        $qualifiedAlias = Str::snake(str_replace('\\', '', Str::before(ltrim($model, '\\'), '\\Models\\')))
            . '_' . $preferredAlias;

        if (array_key_exists($qualifiedAlias, $morphMap)) {
            return;
        }

        Relation::morphMap([$qualifiedAlias => $model], merge: true);
    }
}
