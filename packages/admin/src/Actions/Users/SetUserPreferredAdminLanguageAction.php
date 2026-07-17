<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Core\Models\Language;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SetUserPreferredAdminLanguageAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $user, mixed $languageId): void
    {
        $schema = resolve(RuntimeSchemaState::class);

        if (! $schema->hasTable($user->getTable()) || ! $schema->hasColumn($user->getTable(), 'preferred_admin_language_id')) {
            throw new InvalidArgumentException((string) __('capell-admin::message.preferred_admin_language_unavailable'));
        }

        if (blank($languageId)) {
            $user->forceFill(['preferred_admin_language_id' => null])->save();

            return;
        }

        $languageId = filter_var($languageId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($languageId === false) {
            throw new InvalidArgumentException((string) __('capell-admin::message.preferred_admin_language_invalid'));
        }

        $languageExists = Language::query()
            ->enabled()
            ->whereKey($languageId)
            ->exists();

        if (! $languageExists) {
            throw new InvalidArgumentException((string) __('capell-admin::message.preferred_admin_language_enabled'));
        }

        $user->forceFill(['preferred_admin_language_id' => $languageId])->save();
    }
}
