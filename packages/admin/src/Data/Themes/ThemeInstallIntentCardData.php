<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Spatie\LaravelData\Data;

final class ThemeInstallIntentCardData extends Data
{
    public function __construct(
        public string $title,
        public string $description,
        public string $composerCommand,
        public ?string $imageUrl,
    ) {}

    public static function fromIntent(object $intent): self
    {
        $metadata = self::arrayProperty($intent, 'metadata');
        $description = self::metadataString($metadata, 'description');
        $imageUrl = self::metadataString($metadata, 'image_url');

        return new self(
            title: self::stringProperty($intent, 'extension_name') ?? '',
            description: $description ?? (string) __('capell-admin::table.theme_no_description'),
            composerCommand: self::stringProperty($intent, 'composer_command') ?? '',
            imageUrl: $imageUrl,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayProperty(object $intent, string $key): array
    {
        $value = data_get($intent, $key);

        return is_array($value) ? $value : [];
    }

    private static function stringProperty(object $intent, string $key): ?string
    {
        $value = data_get($intent, $key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function metadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
