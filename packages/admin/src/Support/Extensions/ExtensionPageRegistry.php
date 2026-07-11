<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Filament\Pages\Page;

final class ExtensionPageRegistry
{
    /** @var array<string, list<class-string<Page>>> */
    private array $pages = [];

    /**
     * @param  class-string<Page>  $page
     */
    public function register(string $packageName, string $page): void
    {
        if (! in_array($page, $this->pages[$packageName] ?? [], true)) {
            $this->pages[$packageName][] = $page;
        }
    }

    /**
     * @return class-string<Page>|null
     */
    public function get(string $packageName): ?string
    {
        return $this->pages[$packageName][0] ?? null;
    }

    /**
     * @return list<class-string<Page>>
     */
    public function pagesForPackage(string $packageName): array
    {
        return $this->pages[$packageName] ?? [];
    }

    /**
     * @param  class-string<Page>  $page
     */
    public function packageNameForPage(string $page): ?string
    {
        foreach ($this->pages as $packageName => $packagePages) {
            if (in_array($page, $packagePages, true)) {
                return $packageName;
            }
        }

        return null;
    }

    /**
     * @return array<string, class-string<Page>>
     */
    public function all(): array
    {
        $pages = [];

        foreach ($this->pages as $packageName => $packagePages) {
            foreach ($packagePages as $page) {
                $pages[$packageName . ':' . $page] = $page;
            }
        }

        return $pages;
    }

    /**
     * @return list<array{packageName: string, page: class-string<Page>}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->pages as $packageName => $packagePages) {
            foreach ($packagePages as $page) {
                $entries[] = [
                    'packageName' => $packageName,
                    'page' => $page,
                ];
            }
        }

        return $entries;
    }
}
