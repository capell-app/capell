<?php

declare(strict_types=1);

use Capell\Admin\Http\Middleware\AuthorisePeekPreview;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Pboivin\FilamentPeek\CachedPreview;
use Pboivin\FilamentPeek\Facades\Peek;

uses(CreatesAdminUser::class)
    ->group('preview');

/**
 * Issue a Peek preview token as the given user, the way the panel does while an editor is open.
 *
 * @param  array<string, mixed>  $data
 */
function issuePeekPreview(User $user, array $data = ['title' => 'Draft only the author may see']): string
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $token = Peek::cache()->createPreviewToken();
    CachedPreview::make('PreviewedPage', 'preview', $data)->put($token);

    return $token;
}

function signOutOfPanel(): void
{
    auth()->guard('web')->logout();
    resolve(Factory::class)->forgetGuards();
}

it('opens a preview for the user it was granted to, unshared and without a referrer', function (): void {
    $owner = test()->createUserWithRole('super_admin');
    $token = issuePeekPreview($owner);

    $response = test()->actingAs($owner)->getJson(route('filament-peek.preview', ['token' => $token]));

    $response->assertOk()->assertExactJson(['title' => 'Draft only the author may see']);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
});

it('gives every preview its own random token instead of one token per user', function (): void {
    $owner = test()->createUserWithRole('super_admin');

    $first = issuePeekPreview($owner, ['title' => 'First draft']);
    $second = issuePeekPreview($owner, ['title' => 'Second draft']);

    expect($first)->not->toBe($second)
        ->and(strlen($first))->toBe(64)
        ->and($first)->not->toBe(md5('preview-' . $owner->getAuthIdentifier() . Config::get('app.key', '')));

    // The first link still names only the first preview.
    test()->actingAs($owner)->getJson(route('filament-peek.preview', ['token' => $first]))
        ->assertOk()->assertExactJson(['title' => 'First draft']);
});

it('refuses a guest holding a valid preview token', function (): void {
    $token = issuePeekPreview(test()->createUserWithRole('super_admin'));
    signOutOfPanel();

    test()->getJson(route('filament-peek.preview', ['token' => $token]))->assertNotFound();
});

it('refuses another panel user holding a valid preview token', function (): void {
    $token = issuePeekPreview(test()->createUserWithRole('super_admin'));

    test()->actingAs(test()->createUserWithRole('super_admin'))
        ->getJson(route('filament-peek.preview', ['token' => $token]))
        ->assertNotFound();
});

it('refuses the owner once they can no longer use the panel', function (): void {
    $owner = test()->createUserWithRole('super_admin');
    $token = issuePeekPreview($owner);

    $owner->removeRole('super_admin');

    test()->actingAs($owner->fresh())
        ->getJson(route('filament-peek.preview', ['token' => $token]))
        ->assertNotFound();
});

it('refuses a preview token once its grant has expired', function (): void {
    $owner = test()->createUserWithRole('super_admin');
    $token = issuePeekPreview($owner);
    // Keep the cached preview itself alive, so only the grant's expiry can refuse it.
    CachedPreview::make('PreviewedPage', 'preview', ['title' => 'Stale draft'])->put($token, 3600);

    Date::setTestNow(now()->addSeconds(CachedPreview::$cacheDuration + 1));

    test()->actingAs($owner)->getJson(route('filament-peek.preview', ['token' => $token]))->assertNotFound();
});

it('refuses the per-user token Peek derives from the application key', function (): void {
    $owner = test()->createUserWithRole('super_admin');
    $derived = md5('preview-' . $owner->getAuthIdentifier() . Config::get('app.key', ''));
    CachedPreview::make('PreviewedPage', 'preview', ['title' => 'Derived'])->put($derived);

    test()->actingAs($owner)->getJson(route('filament-peek.preview', ['token' => $derived]))->assertNotFound();
});

it('runs the preview route through the session and its grant check', function (): void {
    $middleware = resolve(Router::class)->getRoutes()->getByName('filament-peek.preview')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('web')
        ->toContain(AuthorisePeekPreview::class);
});

it('refuses a grant whose expiry has passed even while its cache entry lives on', function (): void {
    $owner = test()->createUserWithRole('super_admin');
    $token = issuePeekPreview($owner);
    $key = 'capell-admin:peek-preview-grant:' . hash('sha256', $token);
    $grant = Cache::store(CachedPreview::$cacheStore)->get($key);
    expect($grant)->toBeArray();

    // A cache store that keeps entries past their TTL must not extend the grant.
    Cache::store(CachedPreview::$cacheStore)->put($key, [...$grant, 'expires_at' => now()->subSecond()->getTimestamp()], 3600);
    CachedPreview::make('PreviewedPage', 'preview', ['title' => 'Stale draft'])->put($token, 3600);

    test()->actingAs($owner)->getJson(route('filament-peek.preview', ['token' => $token]))->assertNotFound();
});

it('always runs the preview route through the session and the grant check', function (mixed $configured, array $expected): void {
    expect(AuthorisePeekPreview::around($configured))->toBe($expected);
})->with([
    'nothing configured' => [[], ['web', AuthorisePeekPreview::class]],
    'a bare string' => ['auth', ['web', 'auth', AuthorisePeekPreview::class]],
    'a list without web' => [['auth'], ['web', 'auth', AuthorisePeekPreview::class]],
    'already complete' => [['web', AuthorisePeekPreview::class], ['web', AuthorisePeekPreview::class]],
    'not a list' => [42, ['web', AuthorisePeekPreview::class]],
]);
