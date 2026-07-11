<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Admin\Concerns\HasConfiguratorTypes;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Filament\Configurators\Blueprints\DefaultBlueprintConfigurator;
use Capell\Admin\Filament\Configurators\Blueprints\PageBlueprintConfigurator;
use Capell\Admin\Filament\Configurators\Languages\DefaultLanguageConfigurator;
use Capell\Admin\Filament\Configurators\Layouts\DefaultLayoutConfigurator;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\LandingPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\ResultsPageConfigurator;
use Capell\Admin\Filament\Configurators\Sites\DefaultSiteConfigurator;
use Capell\Admin\Filament\Configurators\Themes\FoundationThemeConfigurator;

enum ConfiguratorTypeEnum: string implements ConfiguratorTypeEnumInterface
{
    use HasConfiguratorTypes;

    case Language = 'Languages';

    case Layout = 'Layouts';

    case Page = 'Pages';

    case Site = 'Sites';

    case Theme = 'Themes';

    case Blueprint = 'Blueprints';

    public function getConfigurators(): array
    {
        return match ($this) {
            self::Language => [
                'default' => DefaultLanguageConfigurator::class,
            ],
            self::Layout => [
                'default' => DefaultLayoutConfigurator::class,
            ],
            self::Page => [
                'default' => DefaultPageConfigurator::class,
                'landing' => LandingPageConfigurator::class,
                'results' => ResultsPageConfigurator::class,
            ],
            self::Site => [
                'default' => DefaultSiteConfigurator::class,
            ],
            self::Theme => [
                'default' => FoundationThemeConfigurator::class,
            ],
            self::Blueprint => [
                'default' => DefaultBlueprintConfigurator::class,
                'page' => PageBlueprintConfigurator::class,
            ],
        };
    }
}
