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
    public function handle(Request $request, Closure $next): Response
    {
        $requestUser = $request->user();
        $locale = ResolveAdminLocaleForUserAction::run($requestUser instanceof Model ? $requestUser : null);

        app()->setLocale($locale);
        resolve(Translator::class)->setLocale($locale);

        return $next($request);
    }
}
