<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use BackedEnum;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Contracts\SettingsSchema;
use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Capell\Core\Support\OutboundEventRegistry;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\Support\Subscriber\SubscriberRegistry;
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
        private readonly MetricCollectorRegistry $metricCollectors,
        private readonly OutboundEventRegistry $outboundEvents,
        private readonly BlueprintSubjectRegistry $blueprintSubjects,
        private readonly RecordsExtensionContributionReceipt $receipts,
    ) {}

    /**
     * Run the package-install lifecycle with the boot-frozen registries open.
     *
     * A package's surfaces only boot once it is marked installed. On a fresh
     * database that flip happens mid-install, long after `booted` froze the
     * registries, so the install lifecycle re-boots the package inside this
     * window. Re-registering an identical surface is a no-op here; a
     * conflicting one still throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function duringPackageInstallation(callable $callback): mixed
    {
        return $this->outboundEvents->duringPackageInstallation(
            fn (): mixed => $this->blueprintSubjects->duringPackageInstallation($callback),
        );
    }

    public function pageType(PageTypeData $type): self
    {
        $this->core->registerPageType($type);
        $this->receipts->recordContribution(
            ExtensionContributionType::PageType,
            $type->name,
            $type->model,
            self::class,
        );

        return $this;
    }

    public function outboundEvent(OutboundEventDefinitionData $definition): self
    {
        $this->outboundEvents->register($definition);
        $this->receipts->recordContribution(
            ExtensionContributionType::OutboundEvent,
            $definition->name,
            $definition->payloadClass,
            self::class,
        );

        return $this;
    }

    public function blueprintSubject(BlueprintSubjectDescriptorData $subject): self
    {
        $this->blueprintSubjects->register($subject);
        $this->receipts->recordContribution(
            ExtensionContributionType::BlueprintSubject,
            $subject->key,
            $subject->modelClass,
            self::class,
        );
        $this->core->registerPageType(new PageTypeData(
            name: $subject->key,
            model: $subject->modelClass,
            label: $subject->label,
        ));

        return $this;
    }

    public function component(string|BackedEnum $type, string|BackedEnum $name, string $component): self
    {
        $this->core->registerComponent($type, $name, $component);
        $this->receipts->recordContribution(
            ExtensionContributionType::ContentWidget,
            'component:' . $this->stringValue($type) . ':' . $this->stringValue($name),
            $component,
            self::class,
        );

        return $this;
    }

    /**
     * @param  array<string, string>  $components
     */
    public function components(string|BackedEnum $type, array $components): self
    {
        $this->core->registerComponents($type, $components);

        foreach ($components as $name => $component) {
            if (! is_string($component)) {
                continue;
            }

            $this->receipts->recordContribution(
                ExtensionContributionType::ContentWidget,
                'component:' . $this->stringValue($type) . ':' . (string) $name,
                $component,
                self::class,
            );
        }

        return $this;
    }

    /**
     * @param  array<int|string, BackedEnum|class-string<Model>>  $models
     */
    public function models(array $models): self
    {
        $this->core->registerModels($models);

        foreach ($models as $model) {
            if ($model instanceof BackedEnum) {
                $model = (string) $model->value;
            }

            if (! is_string($model)) {
                continue;
            }

            $this->receipts->recordContribution(
                ExtensionContributionType::Model,
                'model:' . $model,
                $model,
                self::class,
            );
        }

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
        $this->receipts->recordContribution(
            ExtensionContributionType::Model,
            'model-interceptor:' . $model . ':' . $interceptorClass . ':' . $this->keyValue($key),
            $interceptorClass,
            self::class,
        );

        return $this;
    }

    /**
     * @param  class-string  $subscriber
     */
    public function subscriber(string $subscriber): self
    {
        $this->core->subscriberManager()->subscribe($subscriber);
        $this->receipts->recordContribution(
            ExtensionContributionReceiptType::Subscriber,
            'subscriber:' . $subscriber,
            $subscriber,
            self::class,
        );

        return $this;
    }

    /**
     * @return SubscriberRegistry<object>
     */
    public function subscriberManager(): SubscriberRegistry
    {
        return $this->core->subscriberManager();
    }

    /**
     * @param  class-string<SettingsSchema>  $schemaClass
     */
    public function settingsSchema(string $group, string $schemaClass, ?string $key = null): self
    {
        $this->settings->register($group, $schemaClass, $key);
        $this->receipts->recordContribution(
            ExtensionContributionType::Setting,
            'settings-schema:' . $group . ':' . ($key ?? class_basename($schemaClass)),
            $schemaClass,
            self::class,
        );

        return $this;
    }

    /**
     * @param  class-string<SettingsContract>  $settingsClass
     */
    public function settingsClass(string $group, string $settingsClass): self
    {
        $this->settings->registerSettingsClass($group, $settingsClass);
        $this->receipts->recordContribution(
            ExtensionContributionType::Setting,
            'settings-class:' . $group,
            $settingsClass,
            self::class,
        );

        return $this;
    }

    public function settingsMetadata(SettingsGroupMetadata $metadata): self
    {
        $this->settings->registerMetadata($metadata);
        $this->receipts->recordContribution(
            ExtensionContributionType::Setting,
            'settings-metadata:' . $metadata->group,
            $metadata::class,
            self::class,
        );

        return $this;
    }

    /** @param class-string $collectorClass */
    public function metricCollector(string $collectorClass): self
    {
        $this->metricCollectors->register($collectorClass);
        $this->receipts->recordContribution(
            ExtensionContributionReceiptType::MetricCollector,
            'metric-collector:' . $collectorClass,
            $collectorClass,
            self::class,
        );

        return $this;
    }
}
