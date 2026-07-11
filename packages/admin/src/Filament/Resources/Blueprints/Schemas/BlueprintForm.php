<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Blueprints\Schemas;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Configurators\Blueprints\DefaultBlueprintConfigurator;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Filament\RawState;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Schemas\Schema;

class BlueprintForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        $record = $schema->getRecord();
        $typeRecord = $record instanceof Blueprint ? $record : null;
        $configurator = self::resolveConfigurator(
            $typeRecord instanceof Blueprint ? null : self::safeRawState($schema),
            $typeRecord,
            $context,
        );

        return $configurator::configure($schema->columns(), $context);
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return class-string<ConfiguratorInterface>
     */
    private static function resolveConfigurator(
        ?array $state,
        ?Blueprint $record,
        ?ConfiguratorContextData $context,
    ): string {
        $candidates = [
            $context?->resourceName,
            data_get($state, 'admin.type_configurator'),
            self::componentNameForType(data_get($state, 'type')),
            data_get($state, 'type'),
            $record?->admin['type_configurator'] ?? null,
            $record?->type->getComponentName(),
            DefaultBlueprintConfigurator::getKey(),
        ];

        foreach ($candidates as $candidate) {
            $configurator = self::configuratorForCandidate($candidate);

            if ($configurator !== null) {
                return $configurator;
            }
        }

        return DefaultBlueprintConfigurator::class;
    }

    /**
     * @return class-string<ConfiguratorInterface>|null
     */
    private static function configuratorForCandidate(mixed $candidate): ?string
    {
        if (! is_string($candidate) || blank($candidate)) {
            return null;
        }

        $candidateKeys = collect([
            $candidate,
            str($candidate)->studly()->toString(),
            str($candidate)->lower()->toString(),
        ])->unique();

        foreach ($candidateKeys as $candidateKey) {
            if (AdminSurfaceLookup::hasConfigurator(ConfiguratorTypeEnum::Blueprint, name: $candidateKey)) {
                /** @var class-string<ConfiguratorInterface> $configurator */
                $configurator = AdminSurfaceLookup::configurator(ConfiguratorTypeEnum::Blueprint, name: $candidateKey);

                return $configurator;
            }

            /** @var array<string, class-string<ConfiguratorInterface>> $typeConfigurators */
            $typeConfigurators = ConfiguratorTypeEnum::Blueprint->getConfigurators();

            if (isset($typeConfigurators[$candidateKey])) {
                return $typeConfigurators[$candidateKey];
            }
        }

        return null;
    }

    private static function componentNameForType(mixed $type): ?string
    {
        if (! is_string($type) || blank($type)) {
            return null;
        }

        return CapellCore::getPageTypes()
            ->first(fn (PageTypeData $pageType): bool => $pageType->name === $type)
            ?->getComponentName();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function safeRawState(Schema $schema): ?array
    {
        try {
            return RawState::array($schema->getRawState());
        } catch (ActionNotResolvableException) {
            return null;
        }
    }
}
