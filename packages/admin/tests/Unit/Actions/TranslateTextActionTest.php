<?php

declare(strict_types=1);

use Capell\Admin\Actions\TranslateTextAction;
use Illuminate\Support\Facades\App;
use Tanmuhittin\LaravelGoogleTranslate\Translators\ApiTranslate;

it('translates text using the injected ApiTranslate', function (): void {
    $text = 'Hello world';
    $targetLocale = 'fr';
    $baseLocale = 'en';
    $expectedTranslation = 'Bonjour le monde';

    $mockTranslator = Mockery::mock(ApiTranslate::class);
    $mockTranslator->shouldReceive('translate')
        ->once()
        ->with($text, $targetLocale, $baseLocale)
        ->andReturn($expectedTranslation);

    App::instance(ApiTranslate::class, $mockTranslator);

    $result = TranslateTextAction::run($text, $targetLocale, $baseLocale);

    expect($result)->toBe($expectedTranslation);
});
