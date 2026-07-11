<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Interceptors\Blueprints\Pages;

use Capell\Admin\Filament\Configurators\Blueprints\PageBlueprintConfigurator;
use Capell\Core\Contracts\ModelInterceptors\BlueprintInterceptorInterface;
use Capell\Core\Models\Blueprint;

class DefaultPageBlueprintInterceptor implements BlueprintInterceptorInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array
    {
        $data['admin'] = [
            'type_configurator' => PageBlueprintConfigurator::getKey(),
            'required_fields' => ['title', 'content'],
        ];

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function afterCreated(Blueprint $blueprint, array $data): void {}
}
