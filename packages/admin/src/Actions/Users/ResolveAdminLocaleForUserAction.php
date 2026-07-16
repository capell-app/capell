<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Core\Models\Language;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveAdminLocaleForUserAction
{
    use AsFake;
    use AsObject;

    public function handle(?Model $user): string
    {
        $fallbackLocale = config('app.locale', 'en');

        if (! $user instanceof Model) {
            return $fallbackLocale;
        }

        $schema = resolve(RuntimeSchemaState::class);

        if (! $schema->hasTable($user->getTable()) || ! $schema->hasColumn($user->getTable(), 'preferred_admin_language_id')) {
            return $fallbackLocale;
        }

        $languageId = array_key_exists('preferred_admin_language_id', $user->getAttributes())
            ? $user->getAttribute('preferred_admin_language_id')
            : $user->newQuery()->whereKey($user->getKey())->value('preferred_admin_language_id');

        if (! is_numeric($languageId)) {
            return $fallbackLocale;
        }

        $language = Language::query()
            ->enabled()
            ->whereKey((int) $languageId)
            ->first();

        if (! $language instanceof Language) {
            return $fallbackLocale;
        }

        $locale = filled($language->locale) ? $language->locale : $language->code;

        return $this->isSafeLocale($locale) ? $locale : $fallbackLocale;
    }

    private function isSafeLocale(string $locale): bool
    {
        return $locale !== ''
            && ! str_contains($locale, '/')
            && ! str_contains($locale, '\\');
    }
}
