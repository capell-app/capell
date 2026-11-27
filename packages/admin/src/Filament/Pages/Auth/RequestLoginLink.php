<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Auth;

use Capell\Admin\Actions\Auth\RequestAdminLoginLinkEmailAction;
use Capell\Admin\Support\Auth\AdminLoginLinks;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use Override;

/**
 * Lets someone ask for a sign-in link by email instead of typing a password.
 * The answer is the same whether or not the address has an account.
 *
 * @property-read Schema $form
 */
class RequestLoginLink extends SimplePage
{
    public const string ROUTE_NAME = 'auth.login-link.request';

    public ?string $email = '';

    public static function registerRoute(Panel $panel): void
    {
        Route::get('/sign-in-link/request', static::class)->name(self::ROUTE_NAME);
    }

    public static function url(): ?string
    {
        if (! AdminLoginLinks::emailRequestsEnabled()) {
            return null;
        }

        return route(AdminLoginLinks::panel()->generateRouteName(self::ROUTE_NAME));
    }

    public function mount(): void
    {
        abort_unless(AdminLoginLinks::emailRequestsEnabled(), 404);

        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function request(): void
    {
        abort_unless(AdminLoginLinks::emailRequestsEnabled(), 404);

        $data = $this->form->getState();

        $waitSeconds = RequestAdminLoginLinkEmailAction::run((string) $data['email'], (string) request()->ip(), AdminLoginLinks::panel());

        if ($waitSeconds !== null) {
            Notification::make()
                ->title(__('capell-admin::login_links.request.throttled', ['seconds' => $waitSeconds]))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('capell-admin::login_links.request.sent_title'))
            ->body(__('capell-admin::login_links.request.sent_body', ['minutes' => AdminLoginLinks::expiresInMinutes()]))
            ->success()
            ->persistent()
            ->send();

        $this->form->fill();
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::login_links.request.title');
    }

    #[Override]
    public function getHeading(): string|Htmlable|null
    {
        return __('capell-admin::login_links.request.heading');
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::login_links.request.subheading');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label(__('capell-admin::login_links.request.email'))
                ->email()
                ->required()
                ->autocomplete('email')
                ->autofocus(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('request')
                ->footer([
                    Actions::make([
                        Action::make('request')
                            ->label(__('capell-admin::login_links.request.submit'))
                            ->submit('request'),
                    ])->fullWidth(),
                    Actions::make([
                        Action::make('backToLogin')
                            ->label(__('capell-admin::login_links.request.back_to_login'))
                            ->link()
                            ->url(fn (): ?string => AdminLoginLinks::panel()->getLoginUrl()),
                    ])->fullWidth(),
                ]),
        ]);
    }
}
