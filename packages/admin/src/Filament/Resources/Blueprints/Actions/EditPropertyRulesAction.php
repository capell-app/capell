<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Blueprints\Actions;

use Capell\Core\Actions\Properties\UpdatePropertyDefinitionConstraintsAction;
use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Override;

/**
 * Lets an administrator set a property's validation rules (ranges, lengths,
 * format, uniqueness, allowed reference targets and image limits) from the
 * blueprint that uses it. Saving goes through
 * {@see UpdatePropertyDefinitionConstraintsAction}, and its field errors are
 * shown next to the matching inputs.
 */
final class EditPropertyRulesAction extends Action
{
    private const array RULE_FIELDS = [
        'min', 'max', 'min_length', 'max_length', 'pattern', 'unique_per_site',
        'allowed_blueprints', 'allowed_taxonomies', 'allowed_mime_types',
        'min_width', 'max_width', 'min_height', 'max_height',
    ];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::property_rules.action'))
            ->icon('heroicon-m-adjustments-horizontal')
            ->modalHeading(__('capell-admin::property_rules.heading'))
            ->modalDescription(__('capell-admin::property_rules.description'))
            ->modalSubmitActionLabel(__('capell-admin::property_rules.submit'))
            ->visible(fn (Blueprint $record): bool => ! $record->trashed()
                && $this->canManageRules($record)
                && $this->editableDefinitions($record) !== [])
            ->schema(fn (Blueprint $record): array => $this->formSchema($record))
            ->action(function (array $data, Blueprint $record, Action $action): void {
                $this->save($data, $record, $action);
            });
    }

    #[Override]
    public static function getDefaultName(): string
    {
        return 'propertyRules';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(array $data, Blueprint $record, Action $action): void
    {
        abort_unless($this->canManageRules($record), 403);

        $definitionId = (int) ($data['property_definition_id'] ?? 0);
        abort_unless(array_key_exists($definitionId, $this->editableDefinitions($record)), 404);

        $definition = PropertyDefinition::query()->findOrFail($definitionId);
        $input = array_intersect_key($data, array_flip(self::RULE_FIELDS));

        try {
            UpdatePropertyDefinitionConstraintsAction::run($definition, $input);
        } catch (ValidationException $validationException) {
            $prefix = sprintf('mountedActions.%d.data.', (int) $action->getNestingIndex());
            $errors = [];

            foreach ($validationException->errors() as $key => $messages) {
                $errors[$prefix . explode('.', (string) $key)[0]] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        Notification::make()
            ->success()
            ->title(__('capell-admin::property_rules.saved'))
            ->send();
    }

    /**
     * Property sets are shared by every site, so changing a definition's rules
     * needs the same global-admin capability as editing the set itself, on top
     * of permission to update the blueprint the action is opened from.
     */
    private function canManageRules(Blueprint $record): bool
    {
        $user = auth()->user();

        return $user !== null
            && method_exists($user, 'isGlobalAdmin')
            && $user->isGlobalAdmin() === true
            && Gate::allows('update', $record);
    }

    /**
     * Definitions this blueprint uses whose sets are managed in the admin
     * (package-owned sets are excluded), as `id => "Set: key"` options.
     *
     * @return array<int, string>
     */
    private function editableDefinitions(Blueprint $record): array
    {
        $options = [];
        $attachments = BlueprintPropertySet::query()
            ->where('blueprint_id', $record->getKey())
            ->with('propertySet.definitions')
            ->get();

        foreach ($attachments as $attachment) {
            $propertySet = $attachment->propertySet;
            if (! $propertySet instanceof PropertySet) {
                continue;
            }

            if ($propertySet->owner_package !== null) {
                continue;
            }

            foreach ($propertySet->definitions as $definition) {
                $options[$definition->id] = $propertySet->name . ': ' . $definition->key;
            }
        }

        return $options;
    }

    /**
     * @return list<mixed>
     */
    private function formSchema(Blueprint $record): array
    {
        $typeOf = static function (Get $get): ?PropertyType {
            $id = $get('property_definition_id');

            return is_numeric($id) ? PropertyDefinition::query()->find((int) $id)?->type : null;
        };
        $isNumeric = static fn (Get $get): bool => $typeOf($get)?->isNumeric() === true;
        $isText = static fn (Get $get): bool => $typeOf($get)?->isText() === true;
        $isMedia = static fn (Get $get): bool => $typeOf($get) === PropertyType::Media;
        $canBeUnique = static fn (Get $get): bool => $isNumeric($get) || $isText($get) || $typeOf($get) === PropertyType::EntryReference;

        return [
            Select::make('property_definition_id')
                ->label(__('capell-admin::property_rules.property'))
                ->helperText(__('capell-admin::property_rules.property_help'))
                ->options(fn (): array => $this->editableDefinitions($record))
                ->required()
                ->live()
                ->afterStateUpdated(static function (mixed $state, Set $set): void {
                    $constraints = is_numeric($state) ? PropertyDefinition::query()->find((int) $state)?->constraints : null;
                    $stored = $constraints instanceof PropertyConstraintsData ? $constraints->toStored() : [];

                    foreach (self::RULE_FIELDS as $field) {
                        $set($field, $stored[$field] ?? (in_array($field, ['allowed_blueprints', 'allowed_taxonomies', 'allowed_mime_types'], true) ? [] : null));
                    }
                }),
            TextInput::make('min')
                ->label(__('capell-core::properties.constraints.fields.min'))
                ->helperText(__('capell-admin::property_rules.min_help'))
                ->numeric()
                ->visible($isNumeric),
            TextInput::make('max')
                ->label(__('capell-core::properties.constraints.fields.max'))
                ->helperText(__('capell-admin::property_rules.max_help'))
                ->numeric()
                ->visible($isNumeric),
            TextInput::make('min_length')
                ->label(__('capell-core::properties.constraints.fields.min_length'))
                ->helperText(__('capell-admin::property_rules.length_help'))
                ->integer()
                ->minValue(0)
                ->visible($isText),
            TextInput::make('max_length')
                ->label(__('capell-core::properties.constraints.fields.max_length'))
                ->helperText(__('capell-admin::property_rules.length_help'))
                ->integer()
                ->minValue(1)
                ->visible($isText),
            TextInput::make('pattern')
                ->label(__('capell-core::properties.constraints.fields.pattern'))
                ->helperText(__('capell-admin::property_rules.pattern_help'))
                ->maxLength(500)
                ->visible($isText),
            Toggle::make('unique_per_site')
                ->label(__('capell-core::properties.constraints.fields.unique_per_site'))
                ->helperText(__('capell-admin::property_rules.unique_help'))
                ->visible($canBeUnique),
            Select::make('allowed_blueprints')
                ->label(__('capell-core::properties.constraints.fields.allowed_blueprints'))
                ->helperText(__('capell-admin::property_rules.allowed_blueprints_help'))
                ->multiple()
                ->options(static fn (): array => Blueprint::query()->pageType()->orderBy('name')->pluck('name', 'key')->all())
                ->visible(static fn (Get $get): bool => $typeOf($get) === PropertyType::EntryReference),
            Select::make('allowed_taxonomies')
                ->label(__('capell-core::properties.constraints.fields.allowed_taxonomies'))
                ->helperText(__('capell-admin::property_rules.allowed_taxonomies_help'))
                ->multiple()
                ->options(static fn (): array => SiteAccess::current()->query(Taxonomy::class)->orderBy('name')->pluck('name', 'key')->all())
                ->visible(static fn (Get $get): bool => $typeOf($get) === PropertyType::TermReference),
            TagsInput::make('allowed_mime_types')
                ->label(__('capell-core::properties.constraints.fields.allowed_mime_types'))
                ->helperText(__('capell-admin::property_rules.allowed_mime_types_help'))
                ->visible($isMedia),
            TextInput::make('min_width')
                ->label(__('capell-core::properties.constraints.fields.min_width'))
                ->helperText(__('capell-admin::property_rules.dimensions_help'))
                ->integer()
                ->minValue(1)
                ->visible($isMedia),
            TextInput::make('max_width')
                ->label(__('capell-core::properties.constraints.fields.max_width'))
                ->helperText(__('capell-admin::property_rules.dimensions_help'))
                ->integer()
                ->minValue(1)
                ->visible($isMedia),
            TextInput::make('min_height')
                ->label(__('capell-core::properties.constraints.fields.min_height'))
                ->helperText(__('capell-admin::property_rules.dimensions_help'))
                ->integer()
                ->minValue(1)
                ->visible($isMedia),
            TextInput::make('max_height')
                ->label(__('capell-core::properties.constraints.fields.max_height'))
                ->helperText(__('capell-admin::property_rules.dimensions_help'))
                ->integer()
                ->minValue(1)
                ->visible($isMedia),
        ];
    }
}
