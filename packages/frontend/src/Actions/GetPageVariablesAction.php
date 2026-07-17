<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Site;
use Capell\Frontend\Facades\Frontend;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class GetPageVariablesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Pageable<Model>|null  $page
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function handle(?Pageable $page = null, ?Site $site = null, array $variables = []): array
    {
        $variables = $this->normalizeVariables($variables);
        $page ??= Frontend::page();
        $site ??= Frontend::site();

        try {
            $params = Frontend::params();
        } catch (Throwable) {
            $params = [];
        }

        $pageTitle = $this->pageTitle($page);
        $pageLabel = strip_tags($this->pageLabel($page));
        $parent = $this->parent($page);

        return [
            'site' => $this->siteTitle($site),
            'title' => $pageTitle,
            'label' => $pageLabel,
            'page' => [
                'title' => $pageTitle,
                'label' => $pageLabel,
                'translation' => [
                    'title' => $pageTitle,
                    'label' => $pageLabel,
                ],
                ...$parent,
            ],
            ...$parent,
            ...$params,
            ...$this->archiveVariables($params),
            ...$variables,
        ];
    }

    /**
     * @param  Pageable<Model>|null  $page
     * @return array<string, string>
     */
    private function parent(?Pageable $page): array
    {
        if ($page?->hasPageHierarchy() === false) {
            return [];
        }

        if (! $page instanceof Model || ! $page->relationLoaded('parent') || ! $page->parent instanceof Pageable) {
            return [];
        }

        $parent = $page->parent;

        return [
            'parent' => Str::plural(strip_tags($this->pageLabel($parent))),
        ];
    }

    private function siteTitle(?Site $site): string
    {
        $translation = $site instanceof Model && $site->relationLoaded('translation')
            ? $site->translation
            : null;

        return $this->text($translation->title ?? $site->name ?? '');
    }

    /** @param Pageable<Model>|null $page */
    private function pageTitle(?Pageable $page): string
    {
        $translation = $page instanceof Model && $page->relationLoaded('translation')
            ? $page->translation
            : null;

        return $this->text($translation->title ?? '');
    }

    /** @param Pageable<Model>|null $page */
    private function pageLabel(?Pageable $page): string
    {
        $translation = $page instanceof Model && $page->relationLoaded('translation')
            ? $page->translation
            : null;

        return $this->text($translation->label ?? $translation->title ?? '');
    }

    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $candidate) {
                if (is_string($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function normalizeVariables(array $variables): array
    {
        foreach (['site', 'title', 'label'] as $key) {
            if (array_key_exists($key, $variables)) {
                $variables[$key] = $this->text($variables[$key]);
            }
        }

        if (is_array($variables['page'] ?? null)) {
            foreach (['title', 'label'] as $key) {
                if (array_key_exists($key, $variables['page'])) {
                    $variables['page'][$key] = $this->text($variables['page'][$key]);
                }
            }
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function archiveVariables(array $params): array
    {
        if (isset($params['archive_month'], $params['archive_year']) || ! is_string($params['date'] ?? null)) {
            return [];
        }

        $parts = explode('-', $params['date']);

        if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return [];
        }

        $year = (int) $parts[0];
        $month = (int) $parts[1];

        if ($year < 1 || $month < 1 || $month > 12) {
            return [];
        }

        $date = Date::create()->day(1)->month($month)->year($year);

        return [
            'archive_date' => $date,
            'archive_month' => $date->format('F'),
            'archive_year' => $year,
        ];
    }
}
