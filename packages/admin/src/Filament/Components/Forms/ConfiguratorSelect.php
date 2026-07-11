<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use BackedEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Makers\ConfiguratorSourceResolver;
use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Support\Makers\MakerSafety;
use Filament\Actions\Action;
use Filament\Forms\Components\Select as SelectField;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ConfiguratorSelect extends SelectField
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.configurator'))
            ->afterStateHydrated(function (?string $state, self $component): void {
                if (filled($state)) {
                    return;
                }

                $component->state($component->getDefaultState());
            });
    }

    public static function generatedConfiguratorKey(string $configuratorType, string $name): string
    {
        $typeName = Str::singular(Str::studly($configuratorType));
        $class = Str::studly($name);

        if (! str_ends_with($class, 'Configurator')) {
            $class .= 'Configurator';
        }

        return Str::of($class)
            ->replaceEnd($typeName . 'Configurator', '')
            ->toString();
    }

    public function withCreateConfiguratorAction(callable|BackedEnum|string $configurator): static
    {
        return $this->suffixAction(
            Action::make('createConfigurator')
                ->label(__('capell-admin::generic.create_configurator'))
                ->icon('heroicon-m-plus')
                ->schema(function () use ($configurator): array {
                    if (is_callable($configurator)) {
                        $configurator = $this->evaluate($configurator);
                    }

                    if ($configurator instanceof BackedEnum) {
                        $configurator = $configurator->value;
                    }

                    $configuratorType = is_string($configurator) ? $configurator : '';
                    $sourceOptions = collect([
                        ConfiguratorSourceResolver::BLANK_SOURCE_KEY => __('capell-admin::generic.configurator_source_blank'),
                    ])->merge(
                        collect(resolve(ConfiguratorSourceResolver::class)->candidates($configuratorType))
                            ->mapWithKeys(fn (array $source): array => [$source['key'] => $source['key'] . ' - ' . ($source['class'] ?? __('capell-admin::generic.generated'))])
                            ->all(),
                    );

                    return [
                        TextInput::make('name')
                            ->label(__('capell-admin::form.name'))
                            ->default(fn (): string => $this->defaultCreationName('Configurator'))
                            ->helperText(__('capell-admin::generic.configurator_name_help', [
                                'type' => $configuratorType,
                            ]))
                            ->required(),
                        SelectField::make('source')
                            ->label(__('capell-admin::generic.configurator_source'))
                            ->helperText(__('capell-admin::generic.configurator_source_help'))
                            ->options($sourceOptions->all())
                            ->default(ConfiguratorSourceResolver::BLANK_SOURCE_KEY)
                            ->required(),
                    ];
                })
                ->modalHeading(__('capell-admin::generic.create_configurator'))
                ->modalDescription(fn (): string => __('capell-admin::generic.create_configurator_description'))
                ->action(function (array $data, Set $set) use ($configurator): void {
                    if (is_callable($configurator)) {
                        $configurator = $this->evaluate($configurator);
                    }

                    if ($configurator instanceof BackedEnum) {
                        $configurator = $configurator->value;
                    }

                    try {
                        $result = RunMakerAction::run(new MakerInputData(
                            maker: 'admin.configurator',
                            values: [
                                'type' => (string) $configurator,
                                'name' => $data['name'] ?? '',
                                'source' => $data['source'] ?? ConfiguratorSourceResolver::BLANK_SOURCE_KEY,
                            ],
                            dryRun: false,
                            force: false,
                            databaseWrites: false,
                        ));

                        $statePath = $this->getStatePath(isAbsolute: false);
                        throw_if($statePath === null, RuntimeException::class, 'Configurator select state path is missing.');

                        $set($statePath, self::generatedConfiguratorKey((string) $configurator, (string) ($data['name'] ?? '')));

                        Notification::make()
                            ->title(__('capell-admin::generic.maker_completed'))
                            ->body($result->files->pluck('path')->implode(PHP_EOL))
                            ->success()
                            ->send();
                    } catch (Throwable $throwable) {
                        Notification::make()
                            ->title(__('capell-admin::generic.maker_failed'))
                            ->body($throwable->getMessage())
                            ->danger()
                            ->send();

                        throw $throwable;
                    }
                })
                ->visible(fn (): bool => resolve(MakerSafety::class)->current()->phpWritesAllowed),
        );
    }

    public function defaultCreationName(string $suffix): string
    {
        $state = $this->getContainer()->getRawState();

        if ($state instanceof Arrayable) {
            $state = $state->toArray();
        }

        if (! is_array($state)) {
            return 'Custom' . $suffix;
        }

        $name = data_get($state, 'name', data_get($state, 'title', data_get($state, 'label')));

        if (! is_string($name) || $name === '') {
            return 'Custom' . $suffix;
        }

        $name = Str::studly($name);

        if (! str_ends_with($name, $suffix)) {
            $name .= $suffix;
        }

        return $name;
    }

    public function setupOptions(callable|BackedEnum|string $configurator): static
    {
        $this->allowHtml()
            ->native(false)
            ->options(function (?string $state) use ($configurator): array {
                if (is_callable($configurator)) {
                    $configurator = $this->evaluate($configurator);
                }

                $configurators = AdminSurfaceLookup::configurators($configurator);

                $options = [];

                foreach ($configurators as $configuratorClass) {
                    $key = $configuratorClass::getKey();
                    $baseClassName = class_basename($configuratorClass);
                    $options[$key] = $key . '<br /><span class="block break-words font-light text-gray-500 text-xs">' . $baseClassName . '</span>';
                }

                if ($state !== null && $state !== '' && ! isset($options[$state])) {
                    return array_merge([$state => $state], $options);
                }

                return $options;
            })
            ->placeholder(function () use ($configurator): string {
                if (is_callable($configurator)) {
                    $configurator = $this->evaluate($configurator);
                }

                if (! is_string($configurator)) {
                    return '';
                }

                return __('capell-admin::generic.admin_configurator_type_info', ['type' => $configurator]);
            })
            ->hintIcon(function (?string $state): ?string {
                if (blank($state)) {
                    return null;
                }

                $this->hintIconTooltip(__('capell-admin::generic.loaded_from', ['source' => $state]));

                return 'heroicon-m-information-circle';
            });

        return $this;
    }
}
