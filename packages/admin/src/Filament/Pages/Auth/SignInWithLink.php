<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Auth;

use Capell\Admin\Actions\Auth\RedeemAdminLoginLinkAction;
use Capell\Admin\Enums\AdminLoginLinkRefusal;
use Capell\Admin\Http\Middleware\SendNoReferrer;
use Capell\Admin\Support\Auth\AdminLoginLinks;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Override;

/**
 * Where an emailed or printed sign-in link lands. Opening the link only checks
 * it; the person signs in by pressing the button, so mail scanners that open
 * links in advance cannot use the link up.
 */
class SignInWithLink extends SimplePage
{
    public const string ROUTE_NAME = 'auth.login-link';

    private const int MAX_ATTEMPTS_PER_MINUTE = 10;

    #[Locked]
    public ?string $user = null;

    #[Locked]
    public ?string $token = null;

    #[Locked]
    public string $state = 'invalid';

    public static function registerRoute(Panel $panel): void
    {
        Route::get('/sign-in-link', static::class)
            ->middleware(['signed', SendNoReferrer::class])
            ->name(self::ROUTE_NAME);
    }

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->user = (string) request()->query('user');
        $this->token = (string) request()->query('token');
        $this->state = $this->stateFor(RedeemAdminLoginLinkAction::run($this->user, $this->token, AdminLoginLinks::panel(), consume: false));
    }

    public function signIn(): void
    {
        $rateLimitKey = 'capell-admin:login-link:redeem:' . hash('sha256', (string) request()->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_ATTEMPTS_PER_MINUTE)) {
            Notification::make()
                ->title(__('capell-admin::login_links.sign_in.throttled', ['seconds' => RateLimiter::availableIn($rateLimitKey)]))
                ->danger()
                ->send();

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        $panel = AdminLoginLinks::panel();
        $result = RedeemAdminLoginLinkAction::run((string) $this->user, (string) $this->token, $panel);

        if (! $result instanceof Authenticatable) {
            $this->state = $this->stateFor($result);

            return;
        }

        Filament::auth()->login($result);
        session()->regenerate();

        $this->redirect($panel->getUrl());
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::login_links.sign_in.title');
    }

    #[Override]
    public function getHeading(): string|Htmlable|null
    {
        return __('capell-admin::login_links.sign_in.' . $this->state . '.heading');
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::login_links.sign_in.' . $this->state . '.body', ['minutes' => AdminLoginLinks::expiresInMinutes()]);
    }

    public function content(Schema $schema): Schema
    {
        if ($this->state === 'ready') {
            return $schema->components([
                Actions::make([
                    Action::make('signIn')
                        ->label(__('capell-admin::login_links.sign_in.submit'))
                        ->action('signIn'),
                ])->fullWidth(),
            ]);
        }

        $loginUrl = AdminLoginLinks::panel()->getLoginUrl();

        return $schema->components([
            Actions::make([
                Action::make('backToLogin')
                    ->label(__('capell-admin::login_links.sign_in.back_to_login'))
                    ->link()
                    ->url($loginUrl),
            ])->fullWidth(),
        ]);
    }

    private function stateFor(Authenticatable|AdminLoginLinkRefusal|null $result): string
    {
        return match (true) {
            $result instanceof Authenticatable => 'ready',
            $result instanceof AdminLoginLinkRefusal => $result->value,
            default => 'invalid',
        };
    }
}
