<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

use Capell\Core\Actions\Properties\SyncPromotedFieldValuesAction;
use Capell\Core\Actions\Properties\ValidatePromotedPropertyValuesAction;
use Capell\Core\Models\Page;

/**
 * Decides whether a save is changing a promoted field's value, so property
 * rules are enforced on exactly the saves that set a value and never on an
 * unrelated save of an older page.
 *
 * A field counts as changing when the page is new, when the field itself
 * changes, or when the page moves to another blueprint or site (the rules that
 * apply to it change). {@see ValidatePromotedPropertyValuesAction} asks before
 * the row is written; {@see SyncPromotedFieldValuesAction} asks after.
 */
final class PromotedFieldChanges
{
    private const array SCOPE_ATTRIBUTES = ['blueprint_id', 'site_id'];

    public static function changingBeforeSave(Page $page, string $field): bool
    {
        if (! $page->exists) {
            return true;
        }

        if ($page->isDirty(self::rootAttribute($field))) {
            return true;
        }

        return $page->isDirty(self::SCOPE_ATTRIBUTES);
    }

    public static function changedBySave(Page $page, string $field): bool
    {
        // `wasRecentlyCreated` stays true for the instance's lifetime; a later
        // update on the same instance records its changes, so only treat the
        // save as the create while no update changes are recorded.
        if ($page->wasRecentlyCreated && $page->getChanges() === []) {
            return true;
        }

        if ($page->wasChanged(self::rootAttribute($field))) {
            return true;
        }

        return $page->wasChanged(self::SCOPE_ATTRIBUTES);
    }

    private static function rootAttribute(string $field): string
    {
        return explode('.', $field)[0];
    }
}
