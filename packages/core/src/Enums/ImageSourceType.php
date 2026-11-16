<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImageSourceType: string implements HasLabel
{
    case Url = 'url';

    case Upload = 'upload';

    case Media = 'media';

    case SpatieMedia = 'spatie_media';

    case CuratorMedia = 'curator_media';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        // Labels are read from the translator on every call. The translator already keeps
        // each loaded group, and a process-wide memo would keep serving labels from a
        // translator that has since been replaced or given new lines.
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
            ->all();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Url => __('capell::media.image_source.url'),
            self::Upload => __('capell::media.image_source.upload'),
            self::Media => __('capell::media.image_source.media'),
            self::SpatieMedia => __('capell::media.image_source.spatie_media'),
            self::CuratorMedia => __('capell::media.image_source.curator_media'),
        };
    }

    public function storesMediaRelation(): bool
    {
        return in_array($this, [self::Media, self::SpatieMedia, self::CuratorMedia], true);
    }
}
