<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use BackedEnum;
use Capell\Admin\Contracts\RegistryInspectorInterface;
use Capell\Admin\Data\Diagnostics\RegistrySourceData;
use Capell\Admin\Support\Makers\ComponentSourceResolver;
use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\Makers\MakerSafety;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select as SelectField;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ComponentSelect extends SelectField
{
    protected string $component = 'page';

    protected Closure|BackedEnum|string|null $componentType = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.component'))
            ->searchable()
            ->allowHtml()
            ->afterStateHydrated(function (?string $state, self $component): void {
                if (filled($state)) {
                    return;
                }

                $component->state($component->getDefaultState());
            });
    }

    public static function generatedComponentKey(string $componentType, string $name): string
    {
        return Str::kebab($componentType) . '.' . Str::kebab($name);
    }

    public function setupType(
        callable|BackedEnum|string $type,
        string $hintLanguage = 'capell-admin::generic.components_info',
    ): self {
        if ($type instanceof BackedEnum) {
            $type = $type->name;
        }

        $this->componentType = is_callable($type) && ! $type instanceof BackedEnum && ! is_string($type)
            ? Closure::fromCallable($type)
            : $type;

        $this->options(function (?string $state) use ($type): array {
            if (is_callable($type)) {
                $type = $this->evaluate($type);
            }

            $options = CapellCore::getComponents($type);

            $mappedOptions = [];
            foreach ($options as $label => $key) {
                if (! is_string($key)) {
                    continue;
                }

                $mappedOptions[$key] = $label . '<span class="block font-light text-xs">' . $key . '</span>';
            }

            $options = $mappedOptions;

            asort($options);

            if (is_string($state) && $state !== '' && ! isset($options[$state])) {
                return array_merge([$state => $state], $options);
            }

            return $options;
        })
            ->helperText(function () use ($type, $hintLanguage): string {
                if (is_callable($type)) {
                    $type = $this->evaluate($type);
                }

                if (! is_string($type)) {
                    return '';
                }

                return __($hintLanguage, ['type' => $type]);
            })
            ->hintIcon(function (?string $state, self $component, ?Model $record) use ($type): ?string {
                if (is_callable($type)) {
                    $type = $this->evaluate($type);
                }

                if (! is_string($type)) {
                    return null;
                }

                $tooltip = null;

                if (! in_array($state, [null, '', '0'], true)) {
                    $tooltip = $state;
                } else {
                    if ($record instanceof Model && ! $record instanceof Blueprint && method_exists($record, 'type')) {
                        $relatedType = $record->getRelationValue('type');
                        if ($relatedType instanceof Blueprint) {
                            $statePath = $component->getStatePath(isAbsolute: false);
                            $state = $statePath === 'component'
                                ? $relatedType->component
                                : ($relatedType->meta[$statePath] ?? null);

                            $tooltip = is_string($state)
                                ? __('capell-admin::generic.inherited_type_info', ['component' => $state])
                                : null;
                        }
                    }

                    if (! is_string($tooltip) || in_array($tooltip, ['', '0'], true)) {
                        return null;
                    }
                }

                $component->hintIconTooltip($tooltip);

                return 'heroicon-m-information-circle';
            });

        return $this;
    }

    public function withCreateComponentAction(callable|BackedEnum|string|null $type = null, string $nameSuffix = 'Component'): static
    {
        return $this->suffixAction(
            Action::make('createComponent')
                ->label(__('capell-admin::generic.create_component'))
                ->icon('heroicon-m-plus')
                ->schema(function () use ($type, $nameSuffix): array {
                    $componentType = $this->resolveComponentType($type);
                    $sourceOptions = collect([
                        ComponentSourceResolver::BLANK_SOURCE_KEY => __('capell-admin::generic.component_source_blank'),
                    ])->merge(
                        collect(resolve(ComponentSourceResolver::class)->candidates($componentType))
                            ->mapWithKeys(fn (array $source): array => [$source['key'] => $source['key'] . ' - ' . ($source['label'] ?? __('capell-admin::generic.generated'))])
                            ->all(),
                    );

                    return [
                        TextInput::make('name')
                            ->label(__('capell-admin::form.name'))
                            ->default(fn (): string => $this->defaultCreationName($nameSuffix))
                            ->helperText(__('capell-admin::generic.component_name_help', [
                                'type' => Str::kebab($componentType),
                            ]))
                            ->required(),
                        SelectField::make('source')
                            ->label(__('capell-admin::generic.component_source'))
                            ->helperText(__('capell-admin::generic.component_source_help'))
                            ->options($sourceOptions->all())
                            ->default(ComponentSourceResolver::BLANK_SOURCE_KEY)
                            ->required(),
                    ];
                })
                ->modalHeading(__('capell-admin::generic.create_component'))
                ->modalDescription(fn (): string => __('capell-admin::generic.create_component_description'))
                ->action(function (array $data, Set $set) use ($type): void {
                    $componentType = $this->resolveComponentType($type);

                    try {
                        $result = RunMakerAction::run(new MakerInputData(
                            maker: 'admin.component',
                            values: [
                                'type' => $componentType,
                                'name' => $data['name'] ?? '',
                                'source' => $data['source'] ?? ComponentSourceResolver::BLANK_SOURCE_KEY,
                            ],
                            dryRun: false,
                            force: false,
                            databaseWrites: false,
                        ));

                        $statePath = $this->getStatePath(isAbsolute: false);
                        throw_if($statePath === null, RuntimeException::class, 'Component select state path is missing.');

                        $set($statePath, self::generatedComponentKey($componentType, (string) ($data['name'] ?? '')));

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

    public function withSourceFlow(): static
    {
        return $this->hintIcon(function (?string $state): ?string {
            if (blank($state)) {
                return null;
            }

            $source = resolve(RegistryInspectorInterface::class)
                ->components()
                ->first(fn (RegistrySourceData $componentSource): bool => $componentSource->key === $state);

            $this->hintIconTooltip($source->path ?? $state);

            return 'heroicon-m-information-circle';
        });
    }

    private function resolveComponentType(callable|BackedEnum|string|null $type = null): string
    {
        $type ??= $this->componentType;

        if (is_callable($type)) {
            $type = $this->evaluate($type);
        }

        if ($type instanceof BackedEnum) {
            return $type->name;
        }

        return is_string($type) ? $type : '';
    }
}
