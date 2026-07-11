<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Media;

/**
 * Backend-agnostic public API for a single media row.
 *
 * This contract is the swap point for media backends. The default Spatie-backed
 * implementation is Capell\Core\Models\Media; alternative backends (e.g. the
 * capell/media-library plugin) provide their own implementation. Consumers
 * (Blade views, Data objects, actions) should type-hint this interface instead
 * of a concrete Media model so swapping the backend is seamless.
 */
interface MediaContract
{
    public function getUrl(string $conversion = ''): string;

    public function getFullUrl(string $conversion = ''): string;

    /**
     * @param  array<int, string>  $conversions
     */
    public function getAvailableFullUrl(array $conversions): string;

    public function getSrcset(): string;

    public function hasResponsiveImages(): bool;

    public function hasConversion(string $conversion): bool;

    public function getName(): string;

    public function getPath(): string;

    public function getMimeType(): string;

    public function getWidth(): ?int;

    public function getHeight(): ?int;

    public function getCustomProperty(string $key, mixed $default = null): mixed;
}
