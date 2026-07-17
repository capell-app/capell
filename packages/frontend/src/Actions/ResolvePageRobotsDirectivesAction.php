<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<int, string> run(Pageable $page, ?Language $language = null)
 */
final class ResolvePageRobotsDirectivesAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<int, string>
     */
    public function handle(Pageable $page, ?Language $language = null): array
    {
        $translation = $page->relationLoaded('translation') ? $page->translation : null;

        if ($language instanceof Language && $translation?->language_id !== $language->id) {
            $translation = $page->relationLoaded('translations')
                ? $page->translations->firstWhere('language_id', $language->id)
                : null;
        }

        return $this->normalize([
            ...$this->rawDirectives(data_get($page->meta, 'robots', [])),
            ...$this->rawDirectives($translation?->getMeta('robots') ?? []),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function rawDirectives(mixed $directives): array
    {
        if (is_string($directives) && trim($directives) !== '') {
            return array_values(array_filter(
                array_map(trim(...), explode(',', $directives)),
                fn (string $directive): bool => $directive !== '',
            ));
        }

        if (! is_array($directives)) {
            return [];
        }

        $normalized = [];

        foreach ($directives as $key => $value) {
            if (is_string($key)) {
                if ($value === true) {
                    $normalized[] = $key;
                }

                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $normalized[] = trim($value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $directives
     * @return array<int, string>
     */
    private function normalize(array $directives): array
    {
        return collect($directives)
            ->map(fn (string $directive): string => strtolower(trim($directive)))
            ->filter(fn (string $directive): bool => $directive !== '' && $directive !== '0')
            ->unique()
            ->values()
            ->all();
    }
}
