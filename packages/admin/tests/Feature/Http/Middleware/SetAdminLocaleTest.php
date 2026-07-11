<?php

declare(strict_types=1);

use Capell\Admin\Http\Middleware\SetAdminLocale;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Core\Models\Language;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('sets the app and translator locale from the authenticated user preference', function (): void {
    config()->set('app.locale', 'en');

    $language = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls')->create(['status' => true]);
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $language->getKey()]);

    $middleware = new SetAdminLocale;
    $middleware->handle(adminLocaleRequestFor($user), fn (): ResponseFactory|Response => response('ok'));

    expect(app()->getLocale())->toBe('cy')
        ->and(resolve(Translator::class)->getLocale())->toBe('cy');
});

it('keeps the app locale when no valid preference exists', function (): void {
    config()->set('app.locale', 'en');
    app()->setLocale('en');

    $middleware = new SetAdminLocale;
    $middleware->handle(adminLocaleRequestFor(UserFactory::new()->createOne()), fn (): ResponseFactory|Response => response('ok'));

    expect(app()->getLocale())->toBe('en');
});

function adminLocaleRequestFor(mixed $user): Request
{
    $request = Request::create('/admin');
    $request->setUserResolver(fn (): mixed => $user);

    return $request;
}
