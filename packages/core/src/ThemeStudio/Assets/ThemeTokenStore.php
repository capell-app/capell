<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Assets;

use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ThemeTokenStore
{
    public function __construct(private readonly ?string $directory = null) {}

    public function put(string $themeKey, string $presetKey, BrandProfileData $brand): string
    {
        $directory = $this->directory ?? storage_path('app/public/capell-theme/tokens');

        File::ensureDirectoryExists($directory);

        $filename = str_replace(':', '-', ThemeAssetKey::make($themeKey, $presetKey, $brand)) . '.css';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;

        if (File::exists($path)) {
            return $path;
        }

        $renderer = new ThemeTokenRenderer;
        $contrastIssues = $this->issues($brand);

        if ($contrastIssues !== []) {
            throw new InvalidArgumentException(implode(' ', $contrastIssues));
        }

        $css = $renderer->css($brand);

        if (File::exists($path) && File::get($path) === $css) {
            return $path;
        }

        $this->publish($path, $css);

        return $path;
    }

    public function publicUrl(string $path): string
    {
        return asset('storage/capell-theme/tokens/' . basename($path));
    }

    /**
     * @return array<int, string>
     */
    public function issues(BrandProfileData $brand): array
    {
        return (new ThemeTokenRenderer)->contrastIssues($brand);
    }

    private function publish(string $path, string $css): void
    {
        $temporaryPath = $path . '.' . Str::random(12) . '.tmp';

        try {
            File::put($temporaryPath, $css, true);

            throw_unless(File::move($temporaryPath, $path), RuntimeException::class, 'Unable to publish theme token CSS.');
        } finally {
            File::delete($temporaryPath);
        }
    }
}
