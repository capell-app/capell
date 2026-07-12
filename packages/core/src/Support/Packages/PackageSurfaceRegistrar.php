<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use BackedEnum;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Contracts\SettingsSchema;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\Support\Subscriber\SubscriberManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Canonical entry point for a package contributing core surfaces.
 *
 * This is the primary extension module: a package's service provider should
 * reach for {@see PackageSurfaceRegistrar} (core surfaces), the
 * AdminBridgeRegistrar (admin surfaces), and the FrontendHookRegistrar
 * (render hooks) rather than calling facades, container tags, or
 * `afterResolving` hooks by hand. Keeping the surface here gives the platform
 * one place to validate against the package manifest and one mental model for
 * package authors.
 *
 * Admin and frontend surfaces are intentionally *not* delegated from here:
 * core must never depend on the admin or frontend packages. Those surfaces
 * have their own registrars that live in the packages that own them.
 */
final class PackageSurfaceRegistrar
{
    public function __construct(
        private readonly CapellCoreManager $core,
        private readonly SettingsSchemaRegistry $settings,
    ) {}

    public function pageType(PageTypeData $type): self
    {
        $this->core->registerPageType($type);

        return $this;
    }

    public function component(string|BackedEnum $type, string|BackedEnum $name, string $component): self
    {
        $this->core->registerComponent($type, $name, $component);

        return $this;
    }

    /**
     * @param  array<string, string>  $components
     */
    public function components(string|BackedEnum $type, array $components): self
    {
        $this->core->registerComponents($type, $components);

        return $this;
    }

    /**
     * @param  array<int|string, BackedEnum|class-string<Model>>  $models
     */
    public function models(array $models): self
    {
        $this->core->registerModels($models);

        return $this;
    }

    /**
     * @param  class-string  $model
     * @param  class-string<object>  $interceptorClass
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum|null  $key
     */
    public function modelInterceptor(
        string $model,
        string $interceptorClass,
        null|array|string|BackedEnum $key = null,
        int $priority = 0,
    ): self {
        $this->core->registerModelInterceptor($model, $interceptorClass, $key, $priority);

        return $this;
    }

    /**
     * @param  class-string  $subscriber
     */
    public function subscriber(string $subscriber): self
    {
        $this->core->subscriberManager()->subscribe($subscriber);

        return $this;
    }

    /**
     * @return SubscriberManager<object>
     */
    public function subscriberManager(): SubscriberManager
    {
        return $this->core->subscriberManager();
    }

    /**
     * @param  class-string<SettingsSchema>  $schemaClass
     */
    public function settingsSchema(string $group, string $schemaClass, ?string $key = null): self
    {
        $this->settings->register($group, $schemaClass, $key);

        return $this;
    }

    /**
     * @param  class-string<SettingsContract>  $settingsClass
     */
    public function settingsClass(string $group, string $settingsClass): self
    {
        $this->settings->registerSettingsClass($group, $settingsClass);

        return $this;
    }

    public function settingsMetadata(SettingsGroupMetadata $metadata): self
    {
        $this->settings->registerMetadata($metadata);

        return $this;
    }
}
