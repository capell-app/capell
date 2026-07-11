<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Themes;

use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class ThemeFontUploadPolicy
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_EXTENSIONS = [
        'woff2',
        'woff',
        'ttf',
        'otf',
    ];

    /**
     * @var list<string>
     */
    private const array ALLOWED_MIME_TYPES = [
        'font/woff2',
        'font/woff',
        'font/ttf',
        'font/otf',
        'font/opentype',
        'application/font-woff',
        'application/x-font-ttf',
        'application/x-font-truetype',
        'application/x-font-otf',
        'application/vnd.ms-opentype',
    ];

    /**
     * @return list<string>
     */
    public static function acceptedFileTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }

    /**
     * @return list<string>
     */
    public static function validationRules(): array
    {
        return [
            'extensions:' . implode(',', self::ALLOWED_EXTENSIONS),
        ];
    }

    public static function storageFileName(TemporaryUploadedFile $file): string
    {
        return self::sanitizeFileName(
            originalName: $file->getClientOriginalName(),
            fallbackExtension: $file->getClientOriginalExtension(),
            suffix: (string) Str::ulid(),
        );
    }

    public static function sanitizeFileName(string $originalName, ?string $fallbackExtension = null, ?string $suffix = null): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: (string) $fallbackExtension);

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'woff2';
        }

        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = Str::of($baseName)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->toString();

        if ($slug === '') {
            $slug = 'font';
        }

        $slug = substr($slug, 0, 80);
        $suffix ??= (string) Str::ulid();

        return sprintf('%s-%s.%s', $slug, $suffix, $extension);
    }
}
