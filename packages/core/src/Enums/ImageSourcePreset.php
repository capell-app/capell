<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ImageSourcePreset: string implements HasLabel
{
    use HasEnumOptions;

    case All = 'all';
    case UrlOnly = 'url_only';
    case UploadOnly = 'upload_only';
    case MediaOnly = 'media_only';
    case UrlMedia = 'url_media';
    case UploadMedia = 'upload_media';

    public function getLabel(): string
    {
        return (string) __('capell::media.image_source_preset.' . $this->value);
    }
}
