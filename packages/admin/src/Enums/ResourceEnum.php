<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\BlockTemplates\BlockTemplateResource;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Filament\Resources\Users\UserResource;

enum ResourceEnum: string
{
    case Activity = ActivityResource::class;

    case BlockTemplate = BlockTemplateResource::class;

    case Language = LanguageResource::class;

    case Media = MediaResource::class;

    case Layout = LayoutResource::class;

    case Page = PageResource::class;

    case PageUrl = PageUrlResource::class;

    case Redirect = RedirectResource::class;

    case Site = SiteResource::class;

    case Theme = ThemeResource::class;

    case Blueprint = BlueprintResource::class;

    case User = UserResource::class;

    public function permission(string $affix): string
    {
        $shieldConfig = Utils::getConfig();
        $model = $this->value::getModel();

        return FilamentShield::defaultPermissionKeyBuilder(
            affix: $affix,
            separator: (string) data_get($shieldConfig, 'permissions.separator'),
            subject: class_basename($model),
            case: (string) data_get($shieldConfig, 'permissions.case'),
        );
    }
}
