<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Language;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Language run(bool $default = true)
 */
class CreateDefaultLanguageAction
{
    use AsObject;

    public function handle(bool $default = true): Language
    {
        /** @var class-string<Language> $model */
        $model = Language::class;

        return $model::query()->firstOrCreate(['code' => 'en'], [
            'default' => $default,
            'flag' => 'gb-eng',
            'locale' => 'en_GB',
            'name' => 'English',
        ]);
    }
}
