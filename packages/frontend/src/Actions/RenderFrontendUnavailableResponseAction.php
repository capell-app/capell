<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Support\Locale\HtmlLanguageAttribute;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plain, uncached 503 page shown when a site cannot be rendered because its
 * theme or renderer is unavailable, so the operator sees the reason.
 */
final class RenderFrontendUnavailableResponseAction
{
    use AsFake;
    use AsObject;

    public function handle(string $message, ?Language $language = null): Response
    {
        $title = (string) __('capell-frontend::errors.frontend_unavailable');
        $htmlLang = HtmlLanguageAttribute::forLanguage($language);

        return response(
            '<!doctype html><html lang="' . e($htmlLang) . '"><head><meta charset="utf-8"><title>'
                . e($title)
                . '</title></head><body><h1>'
                . e($title)
                . '</h1><p>'
                . e($message)
                . '</p></body></html>',
            Response::HTTP_SERVICE_UNAVAILABLE,
            [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'text/html; charset=UTF-8',
            ],
        );
    }
}
