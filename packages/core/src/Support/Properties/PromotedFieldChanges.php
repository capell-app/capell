<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

use Capell\Core\Actions\Properties\SyncPromotedFieldValuesAction;
use Capell\Core\Actions\Properties\ValidatePromotedPropertyValuesAction;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Models\Page;

/**
 * The one definition of whether a save changes a promoted field's value,
 * shared by the page save check ({@see ValidatePromotedPropertyValuesAction})
 * and the admin page editor, so both treat the same value the same way.
 *
 * Values are compared as the property stores them
 * ({@see SyncPromotedFieldValuesAction::coerce()}): `5.0` entered over a saved
 * `5` is the same number, so it is not a change. A field also counts as
 * changing when the page is new or moves to another blueprint or site, because
 * the rules that apply to it change; a move therefore enforces every promoted
 * field, in production too.
 */
final class PromotedFieldChanges
{
    private const array SCOPE_ATTRIBUTES = ['blueprint_id', 'site_id'];

    public static function sameValue(EffectivePropertyDefinitionData $definition, mixed $value, mixed $other): bool
    {
        return SyncPromotedFieldValuesAction::coerce($definition, $value)
            === SyncPromotedFieldValuesAction::coerce($definition, $other);
    }

    /**
     * Whether this save, before the row is written, changes the value of the
     * definition's promoted field.
     */
    public static function changingBeforeSave(Page $page, EffectivePropertyDefinitionData $definition): bool
    {
        if (! $page->exists || $page->isDirty(self::SCOPE_ATTRIBUTES)) {
            return true;
        }

        $field = (string) $definition->promotedField;
        [$root, $path] = array_pad(explode('.', $field, 2), 2, null);

        if (! $page->isDirty($root)) {
            return false;
        }

        $original = $page->getOriginal($root);

        return ! self::sameValue(
            $definition,
            data_get($page, $field),
            $path === null ? $original : data_get($original, $path),
        );
    }
}
