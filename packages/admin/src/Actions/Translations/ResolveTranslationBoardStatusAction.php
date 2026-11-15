<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Translations;

use Capell\Admin\Enums\TranslationBoardStatus;
use Capell\Core\Actions\Translations\ResolveChangedSourceFieldsAction;
use Capell\Core\Models\Translation;
use DateTimeInterface;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Classifies one language row of a page against its default-language row. Both rows are passed in already loaded,
 * so a board of many pages runs no query per cell. A row that is out of date reports Needs update even when it is
 * still a copy, matching the badge on the page editor's language tabs.
 *
 * @method static TranslationBoardStatus run(?Translation $translation, ?Translation $defaultTranslation)
 */
final class ResolveTranslationBoardStatusAction
{
    use AsFake;
    use AsObject;

    /** The fields a language row is compared on, the same ones source fingerprints track. */
    private const array COMPARED_ATTRIBUTES = ['title', 'content', 'meta'];

    public function handle(?Translation $translation, ?Translation $defaultTranslation): TranslationBoardStatus
    {
        if (! $translation instanceof Translation) {
            return TranslationBoardStatus::Missing;
        }

        if (! $defaultTranslation instanceof Translation || $defaultTranslation->is($translation)) {
            return TranslationBoardStatus::Done;
        }

        if ($this->isOutdated($translation, $defaultTranslation)) {
            return TranslationBoardStatus::NeedsUpdate;
        }

        if ($this->mirrorsDefault($translation, $defaultTranslation)) {
            return TranslationBoardStatus::Copied;
        }

        return TranslationBoardStatus::Done;
    }

    private function isOutdated(Translation $translation, Translation $defaultTranslation): bool
    {
        $changedFields = ResolveChangedSourceFieldsAction::run($translation, $defaultTranslation);

        if ($changedFields !== null) {
            return $changedFields !== [];
        }

        $defaultUpdatedAt = $defaultTranslation->getAttribute('updated_at');
        $updatedAt = $translation->getAttribute('updated_at');

        return $defaultUpdatedAt instanceof DateTimeInterface
            && $updatedAt instanceof DateTimeInterface
            && $defaultUpdatedAt > $updatedAt;
    }

    private function mirrorsDefault(Translation $translation, Translation $defaultTranslation): bool
    {
        $compared = 0;

        foreach (self::COMPARED_ATTRIBUTES as $attribute) {
            $defaultValue = $defaultTranslation->getRawOriginal($attribute);

            if (in_array($defaultValue, [null, ''], true)) {
                continue;
            }

            if ($translation->getRawOriginal($attribute) !== $defaultValue) {
                return false;
            }

            $compared++;
        }

        return $compared > 0;
    }
}
