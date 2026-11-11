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
        $subject = $this->subject($formData, $page);

        if (! $subject instanceof Page) {
            return;
        }

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

            $this->validator->assertConstraints(
                $definition,
                SyncPromotedFieldValuesAction::coerce($definition, $rawValue),
                (int) $subject->site_id,
                $subject->exists ? (int) $subject->getKey() : null,
                'data.' . $field,
            );
        }
    }

    /**
     * The page whose blueprint and site decide which rules apply: the record
     * being edited, or an unsaved page built from the create form's choices.
     *
     * @param  array<string, mixed>  $formData
     * @param  Pageable<covariant Model>|null  $page
     */
    private function subject(array $formData, ?Pageable $page): ?Page
    {
        if ($page instanceof Page) {
            return $page;
        }

        $blueprintId = $formData['blueprint_id'] ?? null;
        $siteId = $formData['site_id'] ?? null;

        if (! is_numeric($blueprintId) || ! is_numeric($siteId)) {
            return null;
        }

        return (new Page)->forceFill([
            'blueprint_id' => (int) $blueprintId,
            'site_id' => (int) $siteId,
        ]);
    }
}
