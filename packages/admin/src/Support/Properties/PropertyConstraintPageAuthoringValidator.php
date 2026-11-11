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
use Capell\Core\Support\Properties\PropertyValueValidator;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Applies property constraints to the page editor before anything is saved.
 *
 * A field promoted to a constrained property is checked with the same
 * {@see PropertyValueValidator::assertConstraints()} call every other write
 * path uses, so the editor sees the error on the field itself (`data.<field>`)
 * instead of the save failing later when the promoted value is synced.
 *
 * Like the page-save check in Core, only values the form is changing are
 * checked: every promoted field on a new page or when the form moves the page
 * to another blueprint or site, otherwise only fields whose value differs from
 * the saved page. An older value that predates a rule never blocks an editor's
 * unrelated change; it is checked when someone edits that field.
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

            $unchanged = ! $scopeChanged && $record instanceof Page
                && $value === SyncPromotedFieldValuesAction::coerce($definition, data_get($record, $field));

            $arguments = [
                $definition,
                $value,
                (int) $subject->site_id,
                $record instanceof Page ? (int) $record->getKey() : null,
                'data.' . $field,
            ];

            if ($unchanged) {
                $this->validator->assertOrReportUntouchedConstraints(...$arguments);
            } else {
                $this->validator->assertConstraints(...$arguments);
            }
        }
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
