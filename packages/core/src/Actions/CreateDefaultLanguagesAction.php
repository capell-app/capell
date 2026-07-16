<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Locale;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, Language> run(array<int, string> $codes)
 */
class CreateDefaultLanguagesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, string>  $codes
     * @return Collection<int, Language>
     */
    public function handle(array $codes): Collection
    {
        /** @var class-string<Language> $model */
        $model = Language::class;

        $languages = new Collection;

        $hasDefault = $model::query()->default()->exists();

        $defaultCode = null;

        if (! $hasDefault) {
            $defaultCode = in_array('en', $codes, true) ? 'en' : $codes[0];
        }

        foreach ($codes as $code) {
            if ($code === 'en') {
                $language = CreateDefaultLanguageAction::run($defaultCode === 'en');
            } else {
                $language = $model::query()->firstOrCreate(['code' => $code], [
                    'flag' => $code,
                    'locale' => $this->localeFor($code),
                    'name' => $this->nameFor($code),
                    'default' => $code === $defaultCode,
                ]);
            }

            $languages->push($language);
        }

        return $languages;
    }

    private function localeFor(string $code): string
    {
        return str_contains($code, '_') ? $code : $code . '_' . Str::upper($code);
    }

    private function nameFor(string $code): string
    {
        $name = Locale::getDisplayLanguage($code, 'en');

        return is_string($name) ? Str::headline($name) : Str::upper($code);
    }
}
