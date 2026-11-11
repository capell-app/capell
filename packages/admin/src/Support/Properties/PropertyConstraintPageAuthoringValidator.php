<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Properties;

use Capell\Admin\Contracts\Extenders\PageAuthoringValidator;
use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Actions\Properties\SyncPromotedFieldValuesAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Models\Page;
use Capell\Core\Support\Properties\PromotedFieldChanges;
use Capell\Core\Support\Properties\PropertyValueValidator;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Override;

/**
 * Applies property constraints to the page editor before anything is saved.
 *
 * A field promoted to a constrained property is checked with the same
 * {@see PropertyValueValidator::assertConstraints()} call every other write
 * path uses, so the editor sees the error on the field itself (`data.<field>`)
 * instead of the save failing later when the promoted value is synced.
 *
 * Values the form is changing are always enforced: every promoted field on a
 * new page or when the form moves the page to another blueprint or site,
 * otherwise fields whose value differs from the saved page.
 *
 * A saved value the form leaves untouched may predate a rule. Outside
 * production it is enforced too ({@see PropertyValueValidator::enforcesUntouchedValues()}),
 * so developers and staging see a newly added rule's effect straight away. In
 * production it is reported instead: the save goes through and the editor sees
 * a warning naming the field, so a rule added later never stops an unrelated
 * edit. The page save then logs the breach once ({@see PromotedFieldChanges}
 * is the shared definition of an unchanged value).
 */
final readonly class PropertyConstraintPageAuthoringValidator implements PageAuthoringValidator
{
    public function __construct(private PropertyValueValidator $validator) {}

    /**
     * @param  array<string, mixed>  $formData
     * @param  Pageable<covariant Model>|null  $page
     */
    #[Override]
    public function validate(array $formData, ?Pageable $page = null, string $operation = 'save'): void
    {
        $record = $page instanceof Page && $page->exists ? $page : null;
        $subject = $this->subject($formData, $record);

        if (! $subject instanceof Page) {
            return;
        }

        $scopeChanged = ! $record instanceof Page
            || (int) $record->blueprint_id !== (int) $subject->blueprint_id
            || (int) $record->site_id !== (int) $subject->site_id;

        $promoted = ResolveEffectiveDefinitionsAction::run($subject)->filter(
            static fn (EffectivePropertyDefinitionData $definition): bool => $definition->promotedField !== null
                && $definition->constraints instanceof PropertyConstraintsData,
        );

        foreach ($promoted as $definition) {
            $field = (string) $definition->promotedField;
            $rawValue = data_get($formData, $field);

            if ($rawValue === null) {
                continue;
            }

            $value = SyncPromotedFieldValuesAction::coerce($definition, $rawValue);

            $untouched = ! $scopeChanged && $record instanceof Page
                && PromotedFieldChanges::sameValue($definition, $rawValue, data_get($record, $field));

            if ($untouched && ! PropertyValueValidator::enforcesUntouchedValues()) {
                $this->reportUntouchedBreach($definition, $value, $record, $field);

                continue;
            }

            $this->validator->assertConstraints(
                $definition,
                $value,
                (int) $subject->site_id,
                $record instanceof Page ? (int) $record->getKey() : null,
                'data.' . $field,
            );
        }
    }

    private function reportUntouchedBreach(
        EffectivePropertyDefinitionData $definition,
        mixed $value,
        Page $record,
        string $field,
    ): void {
        try {
            $this->validator->assertConstraints($definition, $value, (int) $record->site_id, (int) $record->getKey(), $field);

            return;
        } catch (ValidationException $validationException) {
            $reason = (string) Arr::first(Arr::flatten($validationException->errors()));
        }

        Notification::make()
            ->warning()
            ->title(__('capell-admin::property_rules.untouched_breach_title', ['field' => Str::headline($field)]))
            ->body(__('capell-admin::property_rules.untouched_breach_body', ['reason' => $reason]))
            ->persistent()
            ->send();
    }

    /**
     * The page whose blueprint and site decide which rules apply: the record
     * being edited with any blueprint or site the form now chooses, or an
     * unsaved page built from the create form's choices.
     *
     * @param  array<string, mixed>  $formData
     */
    private function subject(array $formData, ?Page $record): ?Page
    {
        $blueprintId = $formData['blueprint_id'] ?? $record?->blueprint_id;
        $siteId = $formData['site_id'] ?? $record?->site_id;

        if (! is_numeric($blueprintId) || ! is_numeric($siteId)) {
            return null;
        }

        return (new Page)->forceFill([
            'blueprint_id' => (int) $blueprintId,
            'site_id' => (int) $siteId,
        ]);
    }
}
