<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Interceptors\Blueprints\Pages;

use Capell\Admin\Filament\Configurators\Blueprints\PageBlueprintConfigurator;
use Capell\Admin\Filament\Configurators\Pages\LandingPageConfigurator;
use Capell\Core\Contracts\ModelInterceptors\BlueprintInterceptorInterface;
use Capell\Core\Models\Blueprint;

class HomePageBlueprintInterceptor implements BlueprintInterceptorInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array
    {
        $data['admin'] = [
            'type_configurator' => PageBlueprintConfigurator::getKey(),
            'icon' => 'heroicon-o-home',
            'configurator' => LandingPageConfigurator::getKey(),
            'without_parent_page' => true,
            'required_fields' => ['title'],
        ];

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function afterCreated(Blueprint $blueprint, array $data): void {}
}
