<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Auth;

use Capell\Admin\Actions\Users\AcceptUserInvitationAction;
use Capell\Admin\Http\Middleware\SendNoReferrer;
use Capell\Admin\Support\Users\InvitationPasswordBroker;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Locked;
use LogicException;
use Override;
use SensitiveParameter;

/**
 * Where an invited person chooses their password. Reached only through the
 * signed link in the invitation email.
 *
 * @property-read Schema $form
 */
class AcceptInvitation extends SimplePage
{
    public const string ROUTE_NAME = 'auth.invitation.accept';

    private const int MAX_ATTEMPTS_PER_MINUTE = 5;

    #[Locked]
    public ?string $email = null;

    #[Locked]
    public ?string $token = null;

    #[Locked]
    public bool $linkIsValid = false;

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    public static function registerInvitationRoute(Panel $panel): void
    {
        Route::get('/invitation/accept', static::class)
            ->middleware(['signed', SendNoReferrer::class])
            ->name(self::ROUTE_NAME);
    }

    public static function acceptUrl(#[SensitiveParameter] string $token, string $email): string
    {
        $panel = self::panel();

        return URL::temporarySignedRoute(
            $panel->generateRouteName(self::ROUTE_NAME),
            now()->addMinutes(InvitationPasswordBroker::expiresInMinutes()),
            ['token' => $token, 'email' => $email],
        );
    }

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->token = (string) request()->query('token');
        $this->email = (string) request()->query('email');
        $this->linkIsValid = InvitationPasswordBroker::isValid($this->email, $this->token);

        $this->form->fill();
    }

    public function accept(): void
    {
        $rateLimitKey = 'capell-admin:accept-invitation:' . hash('sha256', request()->ip() . '|' . mb_strtolower((string) $this->email));

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_ATTEMPTS_PER_MINUTE)) {
            Notification::make()
                ->title(__('capell-admin::invitations.accept.throttled', ['seconds' => RateLimiter::availableIn($rateLimitKey)]))
                ->danger()
                ->send();

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        if (! $this->linkIsValid) {
            $this->sendInvalidLinkNotification();

            return;
        }

        $data = $this->form->getState();

        $user = AcceptUserInvitationAction::run(
            (string) $this->email,
            (string) $this->token,
            (string) $data['password'],
        );

        if (! $user instanceof Authenticatable) {
            $this->linkIsValid = false;
            $this->sendInvalidLinkNotification();

            return;
        }

        $panel = self::panel();

        if ($user instanceof FilamentUser && ! $user->canAccessPanel($panel)) {
            Notification::make()
                ->title(__('capell-admin::invitations.accept.saved_no_access_title'))
                ->body(__('capell-admin::invitations.accept.saved_no_access_body'))
                ->warning()
                ->persistent()
                ->send();

            $this->redirect($panel->getLoginUrl() ?? $panel->getUrl());

            return;
        }

        Filament::auth()->login($user);
        session()->regenerate();

        $this->redirect($panel->getUrl());
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::invitations.accept.title');
    }

    #[Override]
    public function getHeading(): string|Htmlable|null
    {
        return $this->linkIsValid
            ? __('capell-admin::invitations.accept.heading')
            : __('capell-admin::invitations.accept.invalid_heading');
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return $this->linkIsValid
            ? __('capell-admin::invitations.accept.subheading', ['email' => (string) $this->email])
            : __('capell-admin::invitations.accept.invalid_body', ['days' => InvitationPasswordBroker::expiresInDays()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('password')
                ->label(__('capell-admin::invitations.accept.password'))
                ->helperText(__('capell-admin::invitations.accept.password_help'))
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->rule(PasswordRule::default())
                ->same('passwordConfirmation')
                ->autocomplete('new-password'),
            TextInput::make('passwordConfirmation')
                ->label(__('capell-admin::invitations.accept.password_confirmation'))
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->dehydrated(false)
                ->autocomplete('new-password'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->linkIsValid) {
            return $schema->components([]);
        }

        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('accept')
                ->footer([
                    Actions::make([
                        Action::make('accept')
                            ->label(__('capell-admin::invitations.accept.submit'))
                            ->submit('accept'),
                    ])->fullWidth(),
                ]),
        ]);
    }

    private static function panel(): Panel
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        throw_unless($panel instanceof Panel, LogicException::class, 'Invitations need a Filament panel.');

        return $panel;
    }

    private function sendInvalidLinkNotification(): void
    {
        Notification::make()
            ->title(__('capell-admin::invitations.accept.invalid_title'))
            ->body(__('capell-admin::invitations.accept.invalid_body', ['days' => InvitationPasswordBroker::expiresInDays()]))
            ->danger()
            ->persistent()
            ->send();
    }
}
