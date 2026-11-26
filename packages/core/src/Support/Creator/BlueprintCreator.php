<?php

declare(strict_types=1);

namespace Capell\Core\Support\Creator;

use Capell\Core\Contracts\ModelInterceptors\BlueprintInterceptorInterface;
use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\CacheTime;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Exception;

final class BlueprintCreator
{
    /**
     * @var class-string<Blueprint>
     */
    private readonly string $typeModel;

    public function __construct(
        private readonly BlueprintSubjectRegistry $subjects,
    ) {
        $this->typeModel = Blueprint::class;
    }

    public function create(string $key): void
    {
        $subject = $this->subjects->descriptor($key);

        if ($subject->defaultSchemaSeeder !== null) {
            $seeder = [$subject->defaultSchemaSeeder, 'run'];

            throw_unless(is_callable($seeder), Exception::class, sprintf(
                'Blueprint subject seeder [%s] cannot be executed.',
                $subject->defaultSchemaSeeder,
            ));

            $seeder();

            return;
        }

        $this->createGenericType($subject);
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
            'type' => Blueprint::NAVIGATION_TYPE,
            'name' => __('capell::type.navigation_name'),
            'key' => Blueprint::NAVIGATION_TYPE,
            'admin' => [
                'notes' => __('capell::type.navigation_description'),
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => Blueprint::NAVIGATION_TYPE, 'type' => Blueprint::NAVIGATION_TYPE],
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

    /**
     * A page type whose pages are the shell a host route renders inside the theme
     * (an account area, a portal). Its pages carry per-user output, so the type is
     * never cached, never listed and never given critical CSS, whatever an
     * interceptor or an earlier edit set.
     *
     * @experimental
     */
    public function applicationPageType(string $key, string $name): Blueprint
    {
        $defaults = [
            'key' => $key,
            'type' => BlueprintSubjectEnum::Page,
            'name' => $name,
            'group' => BlueprintGroupEnum::System->value,
            'meta' => [
                'deletable' => false,
                'exclude_parent' => true,
            ],
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => $key, 'type' => BlueprintSubjectEnum::Page],
            fn (array $data): array => $this->forceApplicationPageMeta(CapellCore::mergeModelInterceptorData($defaults, $data)),
            BlueprintInterceptorInterface::class,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function forceApplicationPageMeta(array $data): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        $data['meta'] = [
            ...$meta,
            'cache_time' => CacheTime::Never->value,
            'listable' => false,
            'sitemap' => false,
            'critical_css' => false,
        ];

        return $data;
    }

    private function createGenericType(BlueprintSubjectDescriptorData $subject): Blueprint
    {
        $defaults = [
            'default' => true,
            'type' => $subject->key,
            'name' => __('capell::generic.default'),
            'key' => 'default',
        ];

        return CapellCore::createOrUpdateModel(
            $this->typeModel,
            ['key' => 'default', 'type' => $subject->key],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            BlueprintInterceptorInterface::class,
        );
    }
}
