<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Term;
use Capell\Core\Support\Media\MediaModel;
use Capell\Core\Support\Properties\PropertyValueValidator;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Turns a definition's {@see PropertyConstraintsData} into Laravel validation
 * rules for ONE property value. This is the single source of constraint rules:
 * {@see PropertyValueValidator} applies them on every write (admin editor,
 * agent API and SiteSpec import all reach it), so a constrained blueprint
 * rejects the same bad value the same way on every path.
 *
 * Rules are null-tolerant: an empty value is a completeness question, answered
 * by the definition's requirement, not by its constraints.
 *
 * @method static list<mixed> run(EffectivePropertyDefinitionData $definition, int $siteId, ?int $ignorePageId = null)
 */
final class BuildPropertyValidationRulesAction
{
    use AsFake;
    use AsObject;

    /** Backtracking budget for an editor-supplied pattern: ample for real formats, far below a catastrophic one. */
    public const int PATTERN_BACKTRACK_LIMIT = 100_000;

    /**
     * Whether `$value` matches the stored pattern, evaluated under a bounded
     * backtracking budget so an editor-supplied pattern cannot tie up a
     * request. Returns null when the pattern does not compile or cannot finish
     * within the budget.
     *
     * Every stored pattern is checked here before it is used, whichever path
     * stored it, so a pattern that could escape the whole-value anchors (see
     * {@see self::anchorsHold()}) fails the check instead of passing it.
     */
    public static function matchesPattern(string $pattern, string $value): ?bool
    {
        if (! self::anchorsHold($pattern)) {
            return null;
        }

        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::PATTERN_BACKTRACK_LIMIT);

        try {
            $result = @preg_match(self::compilePattern($pattern), $value);
        } finally {
            if (is_string($previous)) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }

        return $result === false ? null : $result === 1;
    }

    /**
     * The storage column a unique-per-site value is compared in, or null when
     * the type cannot be unique.
     */
    public static function uniqueColumn(PropertyType $type): ?string
    {
        return match (true) {
            $type->isNumeric() => 'value_number',
            $type->isText() => 'value_text',
            $type === PropertyType::EntryReference => 'referenced_page_id',
            default => null,
        };
    }

    /**
     * Wrap a stored pattern body so it must match the WHOLE value. Editors
     * write the pattern without delimiters or anchors.
     */
    public static function compilePattern(string $pattern): string
    {
        return '~\A(?:' . str_replace('~', '\~', $pattern) . ')\z~u';
    }

    /**
     * Whether wrapping the pattern in {@see self::compilePattern()} still makes
     * it match the WHOLE value.
     *
     * The pattern must compile on its own, so its groups are balanced and it
     * cannot close the wrapping group early: `x)|(y` would otherwise become an
     * unanchored alternation. Backtracking control verbs such as `(*ACCEPT)`
     * are refused because they can end a match before the closing anchor.
     */
    public static function anchorsHold(string $pattern): bool
    {
        if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*\(\*/', $pattern) === 1) {
            return false;
        }

        return @preg_match('~' . str_replace('~', '\~', $pattern) . '~u', '') !== false
            && @preg_match(self::compilePattern($pattern), '') !== false;
    }

    /**
     * @return list<mixed>
     */
    public function handle(EffectivePropertyDefinitionData $definition, int $siteId, ?int $ignorePageId = null): array
    {
        $constraints = $definition->constraints;

        if (! $constraints instanceof PropertyConstraintsData || $constraints->isEmpty()) {
            return [];
        }

        $label = Str::headline($definition->key);
        $type = $definition->type;
        $rules = ['nullable'];

        if ($type->isNumeric()) {
            $rules[] = $this->numericRange($constraints, $label);
        }

        if ($type->isText()) {
            $rules[] = $this->textLength($constraints, $label);

            if ($constraints->pattern !== null) {
                $rules[] = $this->pattern($constraints->pattern, $label);
            }
        }

        if ($type === PropertyType::EntryReference && $constraints->allowedBlueprints !== []) {
            $rules[] = $this->allowedBlueprints($constraints->allowedBlueprints, $siteId, $label);
        }

        if ($type === PropertyType::TermReference && $constraints->allowedTaxonomies !== []) {
            $rules[] = $this->allowedTaxonomies($constraints->allowedTaxonomies, $siteId, $label);
        }

        if ($type === PropertyType::Media) {
            $rules[] = $this->media($constraints, $label);
        }

        if ($constraints->uniquePerSite && self::uniqueColumn($type) !== null) {
            $rules[] = $this->uniquePerSite($definition, $siteId, $ignorePageId, $label);
        }

        return $rules;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function mimeAllowed(string $mimeType, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            if ($candidate === $mimeType) {
                return true;
            }

            if (str_ends_with($candidate, '/*') && str_starts_with($mimeType, substr($candidate, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    private static function message(mixed $translated): string
    {
        return is_string($translated) ? $translated : '';
    }

    private function numericRange(PropertyConstraintsData $constraints, string $label): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($constraints, $label): void {
            if (! is_numeric($value)) {
                return;
            }

            if ($constraints->min !== null && (float) $value < (float) $constraints->min) {
                $fail(self::message(__('capell-core::properties.constraints.below_min', ['property' => $label, 'min' => $constraints->min])));
            }

            if ($constraints->max !== null && (float) $value > (float) $constraints->max) {
                $fail(self::message(__('capell-core::properties.constraints.above_max', ['property' => $label, 'max' => $constraints->max])));
            }
        };
    }

    private function textLength(PropertyConstraintsData $constraints, string $label): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($constraints, $label): void {
            if (! is_scalar($value)) {
                return;
            }

            $length = mb_strlen((string) $value);

            if ($constraints->minLength !== null && $length < $constraints->minLength) {
                $fail(self::message(__('capell-core::properties.constraints.too_short', ['property' => $label, 'min' => $constraints->minLength])));
            }

            if ($constraints->maxLength !== null && $length > $constraints->maxLength) {
                $fail(self::message(__('capell-core::properties.constraints.too_long', ['property' => $label, 'max' => $constraints->maxLength])));
            }
        };
    }

    private function pattern(string $pattern, string $label): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($pattern, $label): void {
            if (! is_scalar($value) || (string) $value === '') {
                return;
            }

            $matches = self::matchesPattern($pattern, (string) $value);

            // A pattern that cannot finish within the backtracking budget is a
            // failure, never a pass: the value has not been shown to match.
            if ($matches === null) {
                $fail(self::message(__('capell-core::properties.constraints.pattern_unverifiable', ['property' => $label])));
            } elseif (! $matches) {
                $fail(self::message(__('capell-core::properties.constraints.pattern', ['property' => $label])));
            }
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowedBlueprints(array $allowed, int $siteId, string $label): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($allowed, $siteId, $label): void {
            if (! is_numeric($value)) {
                return;
            }

            $matches = Page::query()
                ->whereKey((int) $value)
                ->where('site_id', $siteId)
                ->whereHas('blueprint', static fn (Builder $query): Builder => $query->whereIn('key', $allowed))
                ->exists();

            if (! $matches) {
                $fail(self::message(__('capell-core::properties.constraints.blueprint_not_allowed', ['property' => $label, 'allowed' => implode(', ', $allowed)])));
            }
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowedTaxonomies(array $allowed, int $siteId, string $label): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($allowed, $siteId, $label): void {
            if (! is_numeric($value)) {
                return;
            }

            $matches = Term::query()
                ->whereKey((int) $value)
                ->whereHas('taxonomy', static fn (Builder $query): Builder => $query
                    ->where('site_id', $siteId)
                    ->whereIn('key', $allowed))
                ->exists();

            if (! $matches) {
                $fail(self::message(__('capell-core::properties.constraints.taxonomy_not_allowed', ['property' => $label, 'allowed' => implode(', ', $allowed)])));
            }
        };
    }

    private function media(PropertyConstraintsData $constraints, string $label): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($constraints, $label): void {
            if (! is_numeric($value)) {
                return;
            }

            $media = MediaModel::query()->whereKey((int) $value)->first();

            if (! $media instanceof Model) {
                return;
            }

            if ($constraints->allowedMimeTypes !== [] && ! self::mimeAllowed((string) $media->getAttribute('mime_type'), $constraints->allowedMimeTypes)) {
                $fail(self::message(__('capell-core::properties.constraints.mime_not_allowed', ['property' => $label, 'allowed' => implode(', ', $constraints->allowedMimeTypes)])));
            }

            $checksWidth = $constraints->minWidth !== null || $constraints->maxWidth !== null;
            $checksHeight = $constraints->minHeight !== null || $constraints->maxHeight !== null;

            if (! $checksWidth && ! $checksHeight) {
                return;
            }

            $customProperties = $media->getAttribute('custom_properties');
            $width = is_array($customProperties) && is_numeric($customProperties['width'] ?? null) ? (int) $customProperties['width'] : null;
            $height = is_array($customProperties) && is_numeric($customProperties['height'] ?? null) ? (int) $customProperties['height'] : null;

            if (($checksWidth && $width === null) || ($checksHeight && $height === null)) {
                $fail(self::message(__('capell-core::properties.constraints.dimensions_unknown', ['property' => $label])));

                return;
            }

            if ($constraints->minWidth !== null && $width < $constraints->minWidth) {
                $fail(self::message(__('capell-core::properties.constraints.too_narrow', ['property' => $label, 'min' => $constraints->minWidth])));
            }

            if ($constraints->maxWidth !== null && $width > $constraints->maxWidth) {
                $fail(self::message(__('capell-core::properties.constraints.too_wide', ['property' => $label, 'max' => $constraints->maxWidth])));
            }

            if ($constraints->minHeight !== null && $height < $constraints->minHeight) {
                $fail(self::message(__('capell-core::properties.constraints.too_short_image', ['property' => $label, 'min' => $constraints->minHeight])));
            }

            if ($constraints->maxHeight !== null && $height > $constraints->maxHeight) {
                $fail(self::message(__('capell-core::properties.constraints.too_tall', ['property' => $label, 'max' => $constraints->maxHeight])));
            }
        };
    }

    private function uniquePerSite(EffectivePropertyDefinitionData $definition, int $siteId, ?int $ignorePageId, string $label): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($definition, $siteId, $ignorePageId, $label): void {
            if (! is_scalar($value) || (string) $value === '') {
                return;
            }

            $columns = new PropertyValueData($definition->key, $definition->type, $value)->toColumns();
            $column = self::uniqueColumn($definition->type);

            if ($column === null || $columns[$column] === null) {
                return;
            }

            $taken = PagePropertyValue::query()
                ->where('site_id', $siteId)
                ->where('property_definition_id', $definition->definitionId)
                ->where($column, $columns[$column])
                ->when($ignorePageId !== null, static fn (Builder $query): Builder => $query->where('page_id', '!=', $ignorePageId))
                // A page in the bin does not hold its value: editors cannot see it to resolve the clash.
                ->whereHas('page')
                ->exists();

            if ($taken) {
                $fail(self::message(__('capell-core::properties.constraints.not_unique', ['property' => $label])));
            }
        };
    }
}
