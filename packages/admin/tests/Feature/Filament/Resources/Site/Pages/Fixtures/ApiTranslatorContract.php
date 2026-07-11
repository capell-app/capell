<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Resources\Site\Pages\Fixtures;

use Tanmuhittin\LaravelGoogleTranslate\Contracts\ApiTranslatorContract as BaseApiTranslatorContract;

final readonly class ApiTranslatorContract implements BaseApiTranslatorContract
{
    public function __construct(mixed $api_key = null) {}

    public function translate(string $text, string $locale, ?string $base_locale = null): string
    {
        return 'FAKE_TRANSLATION';
    }
}
