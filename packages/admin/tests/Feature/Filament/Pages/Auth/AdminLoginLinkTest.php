<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\Auth\IssueAdminLoginLinkAction;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Pages\Auth\RequestLoginLink;
use Capell\Admin\Filament\Pages\Auth\SignInWithLink;
use Capell\Admin\Notifications\AdminLoginLinkNotification;
use Capell\Admin\Support\Auth\AdminLoginLinks;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Timebox;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    config()->set('filament-shield.panel_user.enabled', true);
    AssignPermissionsToRole::run(resources: ResourceEnum::cases());
    EnsureCapellPermissionsAction::run();
    SeedDefaultRolesAction::run();
    Notification::fake();
    // Requests run inside a timebox so response times hide whether an address exists; tests need not wait for it.
    app()->instance(Timebox::class, new class extends Timebox
    {
        public function call(callable $callback, int $microseconds): mixed
        {
            return $callback($this);
        }
    });
});

function loginLinkAdmin(): User
{
    return test()->createUserWithRole('super_admin', ['email' => 'operator@example.test', 'password' => Hash::make('a-long-password')]);
}

/** @return array<array-key, mixed> */
function loginLinkQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

/** @return array{exitCode: int, output: string} */
function loginLinkCommand(string $email): array
{
    $exitCode = Artisan::call('capell:admin-login-link', ['email' => $email]);

    return ['exitCode' => $exitCode, 'output' => Artisan::output()];
}

function adminLoginUrl(): string
{
    return (string) Filament::getPanel('admin')->getLoginUrl();
}

/** Sites opt in to the login-page request; the tests for that page turn it on. */
function enableEmailRequests(): void
{
    config()->set('capell-admin.login_links.email_requests', true);
}

function issuedLoginLink(User $user): string
{
    return IssueAdminLoginLinkAction::run($user, AdminLoginLinks::panel());
}

function enableTwoFactorFor(User $user): void
{
    Filament::getPanel('admin')->multiFactorAuthentication([
        new readonly class($user->getKey()) implements MultiFactorAuthenticationProvider
        {
            public function __construct(private mixed $userId) {}

            public function isEnabled(Authenticatable $user): bool
            {
                return $user->getAuthIdentifier() === $this->userId;
            }

            public function getId(): string
            {
                return 'test';
            }

            public function getLoginFormLabel(): string
            {
                return 'Test code';
            }

            public function getManagementSchemaComponents(): array
            {
                return [];
            }

            public function getChallengeFormComponents(Authenticatable $user): array
            {
                return [];
            }
        },
    ]);
}

it('prints a link from the command that signs the user in once and only once', function (): void {
    $user = loginLinkAdmin();

    expect(Artisan::call('capell:admin-login-link', ['email' => 'operator@example.test']))->toBe(0);
    $output = Artisan::output();
    preg_match('/https?:\/\/\S+/', $output, $matches);
    $url = $matches[0] ?? '';

    expect($url)->toContain('/sign-in-link?');

    $response = $this->get($url)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::login_links.sign_in.ready.heading'))
        ->assertHeader('Referrer-Policy', 'no-referrer');

    // The page carries the token, so no browser, proxy or page cache may keep it.
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    // Opening the page does not use the link up, so a mail scanner cannot burn it.
    expect(DB::table(AdminLoginLinks::table())->count())->toBe(1);

    Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class)
        ->call('signIn')
        ->assertRedirect(Filament::getPanel('admin')->getUrl());

    expect(Filament::auth()->id())->toBe($user->getKey());

    Filament::auth()->logout();

    Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class)
        ->assertSet('state', 'invalid')
        ->call('signIn')
        ->assertNoRedirect();

    expect(Filament::auth()->guest())->toBeTrue();
});

it('refuses a link that is used a second time from a page opened before the first use', function (): void {
    $url = issuedLoginLink(loginLinkAdmin());
    $first = Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class);
    $second = Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class);

    $first->call('signIn')->assertRedirect();
    Filament::auth()->logout();

    $second->call('signIn')->assertNoRedirect()->assertSet('state', 'invalid');
    expect(Filament::auth()->guest())->toBeTrue();
});

it('refuses an expired link', function (): void {
    $this->freezeTime();
    $url = issuedLoginLink(loginLinkAdmin());
    $page = Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class);

    $this->travel(AdminLoginLinks::expiresInMinutes() + 1)->minutes();

    $page->call('signIn')->assertNoRedirect()->assertSet('state', 'invalid');
    expect(Filament::auth()->guest())->toBeTrue();
});

it('refuses a link once the password has changed', function (): void {
    $user = loginLinkAdmin();
    $url = issuedLoginLink($user);

    $user->forceFill(['password' => Hash::make('a-different-password')])->save();

    Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class)
        ->assertSet('state', 'invalid')
        ->call('signIn')
        ->assertNoRedirect();

    expect(Filament::auth()->guest())->toBeTrue();
});

it('refuses a link with a tampered signature', function (): void {
    $url = issuedLoginLink(loginLinkAdmin());

    $this->get($url . 'tampered')->assertForbidden();
    $this->get(str_replace('token=', 'token=0', $url))->assertForbidden();
});

it('refuses a validly signed link whose token does not match', function (): void {
    $user = loginLinkAdmin();
    issuedLoginLink($user);

    Livewire::withQueryParams(['user' => (string) $user->getKey(), 'token' => str_repeat('a', 64)])->test(SignInWithLink::class)
        ->assertSet('state', 'invalid')
        ->call('signIn')
        ->assertNoRedirect();

    expect(Filament::auth()->guest())->toBeTrue();
});

it('stores only a hash of the token', function (): void {
    $url = issuedLoginLink(loginLinkAdmin());
    $token = (string) loginLinkQuery($url)['token'];

    $row = DB::table(AdminLoginLinks::table())->first();

    expect(strlen($token))->toBe(64)
        ->and((string) $row?->token_hash)->not->toBe($token)
        ->and(json_encode($row))->not->toContain($token);
});

it('refuses to sign in someone the panel no longer admits', function (): void {
    $user = loginLinkAdmin();
    $url = issuedLoginLink($user);

    $user->syncRoles([]);

    Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class)
        ->assertSet('state', 'no_panel_access')
        ->call('signIn')
        ->assertNoRedirect();

    expect(Filament::auth()->guest())->toBeTrue();
});

it('does not let a link bypass two-factor authentication', function (): void {
    $user = loginLinkAdmin();
    $url = issuedLoginLink($user);

    enableTwoFactorFor($user);

    Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class)
        ->assertSet('state', 'two_factor_enabled')
        ->call('signIn')
        ->assertNoRedirect();

    expect(Filament::auth()->guest())->toBeTrue();

    $result = loginLinkCommand('operator@example.test');
    expect($result['exitCode'])->not->toBe(0)
        ->and($result['output'])->toContain(__('capell-admin::login_links.refusals.two_factor_enabled'));
});

it('refuses to print a link for someone without panel access or an unknown address', function (): void {
    test()->createUser(['email' => 'visitor@example.test'])->syncRoles([]);

    $result = loginLinkCommand('visitor@example.test');
    expect($result['exitCode'])->not->toBe(0)
        ->and($result['output'])->toContain(__('capell-admin::login_links.refusals.no_panel_access'));

    expect(loginLinkCommand('nobody@example.test')['exitCode'])->not->toBe(0);

    expect(DB::table(AdminLoginLinks::table())->count())->toBe(0);
});

it('refuses links entirely when they are turned off', function (): void {
    $url = issuedLoginLink(loginLinkAdmin());
    config()->set('capell-admin.login_links.enabled', false);

    expect(loginLinkCommand('operator@example.test')['exitCode'])->not->toBe(0);

    Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class)
        ->call('signIn')
        ->assertNoRedirect();

    expect(Filament::auth()->guest())->toBeTrue();
    $this->get(adminLoginUrl())->assertDontSee(__('capell-admin::login_links.login_page.link'));
});

it('emails a working link from the login page', function (): void {
    enableEmailRequests();
    $user = loginLinkAdmin();

    $this->get(adminLoginUrl())
        ->assertSuccessful()
        ->assertSee(__('capell-admin::login_links.login_page.link'));

    Livewire::test(RequestLoginLink::class)
        ->fillForm(['email' => 'Operator@Example.test'])
        ->call('request')
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::login_links.request.sent_title'));

    $url = '';
    Notification::assertSentTo($user, AdminLoginLinkNotification::class, function (AdminLoginLinkNotification $notification) use ($user, &$url): bool {
        $url = (string) $notification->toMail($user)->actionUrl;

        return true;
    });

    Livewire::withQueryParams(loginLinkQuery($url))->test(SignInWithLink::class)
        ->call('signIn')
        ->assertRedirect();

    expect(Filament::auth()->id())->toBe($user->getKey());
});

it('gives the same answer whether or not the address has an account', function (string $email): void {
    enableEmailRequests();
    loginLinkAdmin();
    test()->createUser(['email' => 'visitor@example.test'])->syncRoles([]);

    Livewire::test(RequestLoginLink::class)
        ->fillForm(['email' => $email])
        ->call('request')
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::login_links.request.sent_title'));
})->with(['operator@example.test', 'nobody@example.test', 'visitor@example.test']);

it('sends nothing to an unknown address or to someone without panel access', function (): void {
    enableEmailRequests();
    $visitor = test()->createUser(['email' => 'visitor@example.test']);
    $visitor->syncRoles([]);

    foreach (['nobody@example.test', 'visitor@example.test'] as $email) {
        Livewire::test(RequestLoginLink::class)->fillForm(['email' => $email])->call('request');
    }

    Notification::assertNothingSent();
    expect(DB::table(AdminLoginLinks::table())->count())->toBe(0);
});

it('limits how many links one address receives', function (): void {
    enableEmailRequests();
    $user = loginLinkAdmin();
    $page = Livewire::test(RequestLoginLink::class);

    for ($request = 1; $request <= AdminLoginLinks::maxEmailsPerAddress() + 2; $request++) {
        $page->fillForm(['email' => 'operator@example.test'])
            ->call('request')
            ->assertNotified(__('capell-admin::login_links.request.sent_title'));
    }

    Notification::assertSentToTimes($user, AdminLoginLinkNotification::class, AdminLoginLinks::maxEmailsPerAddress());
});

it('makes one connection wait after too many requests', function (): void {
    enableEmailRequests();
    $this->freezeTime();
    $page = Livewire::test(RequestLoginLink::class);

    for ($request = 1; $request <= AdminLoginLinks::maxRequestsPerIp(); $request++) {
        $page->fillForm(['email' => 'person' . $request . '@example.test'])->call('request');
    }

    $page->fillForm(['email' => 'another@example.test'])
        ->call('request')
        ->assertNotified(__('capell-admin::login_links.request.throttled', ['seconds' => AdminLoginLinks::requestWindowSeconds()]));
});

it('rate limits sign-in attempts from one connection', function (): void {
    $this->freezeTime();
    $user = loginLinkAdmin();
    issuedLoginLink($user);
    $page = Livewire::withQueryParams(['user' => (string) $user->getKey(), 'token' => 'wrong'])->test(SignInWithLink::class);

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $page->call('signIn');
    }

    $page->call('signIn')->assertNotified(__('capell-admin::login_links.sign_in.throttled', ['seconds' => 60]));
});

it('keeps the login-page request off unless the site turns it on', function (): void {
    loginLinkAdmin();
    $requestUrl = route(Filament::getPanel('admin')->generateRouteName(RequestLoginLink::ROUTE_NAME));

    $this->get(adminLoginUrl())->assertSuccessful()->assertDontSee(__('capell-admin::login_links.login_page.link'));
    $this->get($requestUrl)->assertNotFound();

    // The command is for operators and harnesses, so it keeps working.
    expect(loginLinkCommand('operator@example.test')['exitCode'])->toBe(0);
});

it('refuses a request submitted from a page opened before the site turned the option off', function (): void {
    enableEmailRequests();
    $user = loginLinkAdmin();
    $page = Livewire::test(RequestLoginLink::class)->fillForm(['email' => 'operator@example.test']);

    config()->set('capell-admin.login_links.email_requests', false);

    $page->call('request')->assertNotFound();
    Notification::assertNotSentTo($user, AdminLoginLinkNotification::class);
});

it('gives the same answer when sending the email fails', function (): void {
    enableEmailRequests();
    loginLinkAdmin();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('Mail transport is down'));

    Livewire::test(RequestLoginLink::class)
        ->fillForm(['email' => 'operator@example.test'])
        ->call('request')
        ->assertNotified(__('capell-admin::login_links.request.sent_title'));
});
