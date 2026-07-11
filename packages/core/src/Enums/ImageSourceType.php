<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ImageSourceType: string implements HasLabel
{
    use HasEnumOptions;

    case Url = 'url';

    case Upload = 'upload';

    case Media = 'media';

    case SpatieMedia = 'spatie_media';

    case CuratorMedia = 'curator_media';

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
