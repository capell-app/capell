<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Capell\Admin\Filament\Support\HelperText;
use Capell\Admin\Support\Filament\RawState;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\CapellCoreHelper;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Field
 */
class FieldMacro
{
    /**
     * Add a settings-aware question-mark helper tooltip after the field label.
     *
     * @return Closure(string|Closure|null): mixed
     *
     * @return-closure-this Field
     */
    public function helperTooltip(): Closure
    {
        return fn (string|Closure|null $tooltip): object => HelperText::apply($this, $tooltip, translate: false);
    }

    /**
     * Required Based on Type Required Fields
     *
     * @return Closure(): Field
     *
     * @return-closure-this Field
     */
    public function requiredBasedOnType(): Closure
    {
        return fn (): Field => $this
            ->required(function (Field $component, Get $get): bool {
                $record = $component->getRootContainer()->getRecord();
                $rawState = RawState::array($component->getRootContainer()->getRawState());

                $blueprint = null;
                if ($record instanceof Model && $record->relationLoaded('blueprint')) {
                    $relatedBlueprint = $record->getRelation('blueprint');
                    $blueprint = $relatedBlueprint instanceof Blueprint ? $relatedBlueprint : null;
                } else {
                    $blueprint = CapellCoreHelper::getBlueprint(
                        typeId: $rawState['blueprint_id'] ?? null,
                    );
                }

                $requiredFields = $blueprint?->admin['required_fields'] ?? [];

                if (! $requiredFields) {
                    return false;
                }

                if (! in_array($component->getName(), $requiredFields, true)) {
                    return false;
                }

                $site = null;
                if ($record instanceof Model && $record->relationLoaded('site')) {
                    /** @var Model $record */
                    $site = $record->getAttribute('site');
                } else {
                    $site = CapellCoreHelper::getSite(
                        siteId: $rawState['site_id'] ?? null,
                    );
                }

                $languageId = $get('language_id');

                $requiredTranslationCodes = $site?->admin['require_translations'] ?? [];

                if (! $requiredTranslationCodes) {
                    return false;
                }

                $requiredLanguages = CapellCoreHelper::languagesByCodes($requiredTranslationCodes)
                    ->pluck('id')
                    ->toArray();

                return in_array($languageId, $requiredLanguages, true);
            });
    }
}
