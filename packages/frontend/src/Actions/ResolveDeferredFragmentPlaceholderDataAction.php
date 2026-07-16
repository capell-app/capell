<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Support\Fragments\DeferredFragmentPlaceholderData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static DeferredFragmentPlaceholderData|null run(array $meta, string $reference, string $url)
 */
final class ResolveDeferredFragmentPlaceholderDataAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(array $meta, string $reference, string $url): ?DeferredFragmentPlaceholderData
    {
        $performance = is_array($meta['performance'] ?? null) ? $meta['performance'] : [];

        if (($performance['defer'] ?? false) !== true || $reference === '' || $url === '') {
            return null;
        }

        return new DeferredFragmentPlaceholderData(
            cacheKey: hash('sha256', $reference),
            url: $url,
            strategy: $this->deferredStrategy($performance['defer_strategy'] ?? null),
            minHeight: $this->deferredMinHeight($performance['defer_min_height'] ?? null),
            variant: $this->deferredSkeletonVariant($performance['defer_skeleton'] ?? null),
        );
    }

    private function deferredStrategy(mixed $value): string
    {
        return in_array($value, ['idle', 'visible'], true) ? $value : 'visible';
    }

    private function deferredSkeletonVariant(mixed $value): string
    {
        return in_array($value, ['band', 'strip', 'grid', 'gallery', 'reviews'], true) ? $value : 'band';
    }

    private function deferredMinHeight(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d+(?:\.\d+)?(?:px|rem|vh|svh)$/', $value) === 1 ? $value : null;
    }
}
