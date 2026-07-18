<?php

declare(strict_types=1);

namespace Capell\Core\Support\Creator;

use Capell\Core\Contracts\ModelInterceptors\BlueprintInterceptorInterface;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Exception;

final class BlueprintCreator
{
    /**
     * @var class-string<Blueprint>
     */
    private readonly string $typeModel;

    public function __construct()
    {
        $this->typeModel = Blueprint::class;
    }

    public function create(string $key): void
    {
        match ($key) {
            BlueprintSubjectEnum::Page->value => $this->defaultPageType(),
            BlueprintSubjectEnum::Theme->value => $this->createThemeType(),
            BlueprintSubjectEnum::Site->value => $this->createSiteType(),
            default => throw new Exception('Invalid page type key: ' . $key),
        };
    }

    public function createPageType(string $name): Blueprint
    {
        return match ($name) {
            'default' => $this->defaultPageType(),
            'error' => $this->notFoundPageType(),
            'home' => $this->homePageType(),
            'maintenance' => $this->maintenancePageType(),
            'system' => $this->systemPageType(),
            default => throw new Exception('Invalid page type name: ' . $name),
        };
    }

    public function createPageTypes(): void
    {
        $this->defaultPageType();
        $this->notFoundPageType();
        $this->homePageType();
        $this->maintenancePageType();
        $this->systemPageType();
    }

    public function createThemeType(): Blueprint
    {
        $defaults = [
            'default' => true,
            'type' => BlueprintSubjectEnum::Theme,
            'name' => __('capell::generic.default'),
            'key' => 'default',
            'admin' => [
                'notes' => __('capell::type.default_theme_description'),
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => 'default', 'type' => BlueprintSubjectEnum::Theme],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }

    public function createSiteType(): Blueprint
    {
        $defaults = [
            'default' => true,
            'type' => BlueprintSubjectEnum::Site,
            'name' => __('capell::generic.default'),
            'key' => 'default',
            'admin' => [
                'notes' => __('capell::type.default_site_description'),
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => 'default', 'type' => BlueprintSubjectEnum::Site],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }

    public function createNavigationType(): Blueprint
    {
        $defaults = [
            'type' => 'navigation',
            'name' => __('capell::type.navigation_name'),
            'key' => 'navigation',
            'admin' => [
                'notes' => __('capell::type.navigation_description'),
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => 'navigation', 'type' => 'navigation'],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }

    public function defaultPageType(): Blueprint
    {
        $defaults = [
            'default' => true,
            'key' => PageTypeEnum::Default->value,
            'type' => BlueprintSubjectEnum::Page,
            'name' => __('capell::generic.default'),
            'admin' => [
                'notes' => __('capell::type.default_page_description'),
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => PageTypeEnum::Default->value, 'type' => BlueprintSubjectEnum::Page],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }

    public function notFoundPageType(): Blueprint
    {
        $defaults = [
            'key' => PageTypeEnum::NotFound->value,
            'type' => BlueprintSubjectEnum::Page,
            'name' => __('capell::generic.page_not_found'),
            'group' => BlueprintGroupEnum::System->value,
            'admin' => [
                'notes' => __('capell::type.error_page_description'),
            ],
            'meta' => [
                'deletable' => false,
                'accessible' => false,
                'listable' => false,
                'exclude_parent' => true,
                'layout_editable' => false,
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => PageTypeEnum::NotFound->value, 'type' => BlueprintSubjectEnum::Page],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }

    public function maintenancePageType(): Blueprint
    {
        $defaults = [
            'key' => PageTypeEnum::Maintenance->value,
            'type' => BlueprintSubjectEnum::Page,
            'name' => __('capell::generic.maintenance'),
            'group' => BlueprintGroupEnum::System->value,
            'admin' => [
                'notes' => __('capell::type.maintenance_page_description'),
            ],
            'meta' => [
                'deletable' => false,
                'accessible' => false,
                'listable' => false,
                'exclude_parent' => true,
                'layout_editable' => false,
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => PageTypeEnum::Maintenance->value, 'type' => BlueprintSubjectEnum::Page],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }

    public function homePageType(): Blueprint
    {
        $defaults = [
            'key' => PageTypeEnum::Home->value,
            'type' => BlueprintSubjectEnum::Page,
            'name' => __('capell::generic.home'),
            'admin' => [
                'notes' => __('capell::type.home_page_description'),
            ],
            'meta' => [
                'listable' => false,
                'sitemap' => true,
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => PageTypeEnum::Home->value, 'type' => BlueprintSubjectEnum::Page],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }

    public function systemPageType(): Blueprint
    {
        $defaults = [
            'key' => PageTypeEnum::System->value,
            'type' => BlueprintSubjectEnum::Page,
            'name' => __('capell::generic.system'),
            'group' => BlueprintGroupEnum::System->value,
            'admin' => [
                'notes' => __('capell::type.system_page_description'),
            ],
            'meta' => [
                'listable' => false,
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => PageTypeEnum::System->value, 'type' => BlueprintSubjectEnum::Page],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }
}
