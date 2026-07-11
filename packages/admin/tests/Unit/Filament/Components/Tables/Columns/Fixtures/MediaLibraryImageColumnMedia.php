<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures;

use Capell\Core\Contracts\Media\MediaContract;

final class MediaLibraryImageColumnMedia implements MediaContract
{
    public function __construct(private readonly string $url) {}

    public function getUrl(string $conversion = ''): string
    {
        return $this->url . '?conversion=' . $conversion;
    }

    public function getFullUrl(string $conversion = ''): string
    {
        return $this->getUrl($conversion);
    }

    public function getAvailableFullUrl(array $conversions): string
    {
        return $this->getFullUrl($conversions[0] ?? '');
    }

    public function getSrcset(): string
    {
        return '';
    }

    public function hasResponsiveImages(): bool
    {
        return false;
    }

    public function hasConversion(string $conversion): bool
    {
        return $conversion !== '';
    }

    public function getName(): string
    {
        return basename($this->url);
    }

    public function getPath(): string
    {
        return $this->url;
    }

    public function getMimeType(): string
    {
        return 'image/jpeg';
    }

    public function getWidth(): int
    {
        return 800;
    }

    public function getHeight(): int
    {
        return 600;
    }

    public function getCustomProperty(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
