<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolvePreferredAdminLanguageIdAction
{
    use AsAction;

    public function handle(?Model $user): ?int
    {
        if (! $user instanceof Model) {
            return null;
        }

        $schema = resolve(RuntimeSchemaState::class);

        if (! $schema->hasTable($user->getTable()) || ! $schema->hasColumn($user->getTable(), 'preferred_admin_language_id')) {
            return null;
        }

        $languageId = array_key_exists('preferred_admin_language_id', $user->getAttributes())
            ? $user->getAttribute('preferred_admin_language_id')
            : $user->newQuery()->whereKey($user->getKey())->value('preferred_admin_language_id');

        return is_numeric($languageId) ? (int) $languageId : null;
    }
}
