<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Admin\Actions\Users\ResolveAdminLocaleForUserAction;
use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetAdminLocale
{
    public const string APPLICATION_LOCALE_ATTRIBUTE = 'capell.admin.application_locale';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->has(self::APPLICATION_LOCALE_ATTRIBUTE)) {
            $request->attributes->set(self::APPLICATION_LOCALE_ATTRIBUTE, config('app.locale', 'en'));
        }

        $requestUser = $request->user();
        $locale = ResolveAdminLocaleForUserAction::run($requestUser instanceof Model ? $requestUser : null);

        app()->setLocale($locale);
        resolve(Translator::class)->setLocale($locale);

        return $next($request);
    }
}
