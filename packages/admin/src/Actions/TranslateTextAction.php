<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Tanmuhittin\LaravelGoogleTranslate\Helpers\ConfigHelper;
use Tanmuhittin\LaravelGoogleTranslate\Translators\ApiTranslate;

/**
 * @method static string run(string $text, string $locale, ?string $base_locale = null)
 */
class TranslateTextAction
{
    use AsFake;
    use AsObject;

    public function handle(string $text, string $locale, ?string $base_locale = null): string
    {
        ConfigHelper::getBaseLocale($base_locale);

        $translator = resolve(ApiTranslate::class);

        return $translator->translate($text, $locale, $base_locale);
    }
}
