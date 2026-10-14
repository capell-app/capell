<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class RestoreDeletedTranslationForLanguageAction
{
    use AsFake;
    use AsObject;

    /** @param Builder<Model> $relationshipQuery */
    public function handle(Builder $relationshipQuery, int $languageId): ?Translation
    {
        $model = $relationshipQuery->getModel();
        throw_unless($model instanceof Translation, RuntimeException::class, 'Expected a translation relationship.');

        // Keep the owner and workspace constraints; only remove the soft-delete scope.
        $translation = (clone $relationshipQuery)
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull($model->getQualifiedDeletedAtColumn())
            ->where($model->qualifyColumn('language_id'), $languageId)
            ->lockForUpdate()
            ->first();

        if (! $translation instanceof Translation) {
            return null;
        }

        throw_unless($translation->restore(), RuntimeException::class, 'Could not restore the deleted translation.');

        return $translation;
    }
}
