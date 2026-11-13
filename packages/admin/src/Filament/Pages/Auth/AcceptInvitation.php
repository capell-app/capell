<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Auth;

use Capell\Admin\Actions\Users\AcceptUserInvitationAction;
use Capell\Admin\Support\Users\InvitationPasswordBroker;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
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
    use WithRateLimiting;

    public const string ROUTE_NAME = 'auth.invitation.accept';

    #[Locked]
    public ?string $email = null;

    #[Locked]
    public ?string $token = null;

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    public static function registerInvitationRoute(Panel $panel): void
    {
        Route::get('/invitation/accept', static::class)
            ->middleware(['signed'])
            ->name(self::ROUTE_NAME);
    }

    public static function acceptUrl(#[SensitiveParameter] string $token, string $email): string
    {
        $panel = self::panel();

        return URL::temporarySignedRoute(
            $panel->generateRouteName(self::ROUTE_NAME),
            now()->addMinutes(InvitationPasswordBroker::EXPIRES_IN_MINUTES),
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

        $this->form->fill();
    }

    public function accept(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $tooManyRequestsException) {
            Notification::make()
                ->title(__('capell-admin::invitations.accept.throttled', ['seconds' => $tooManyRequestsException->secondsUntilAvailable]))
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();

        $user = AcceptUserInvitationAction::run(
            (string) $this->email,
            (string) $this->token,
            (string) $data['password'],
        );

        if (! $user instanceof Authenticatable) {
            Notification::make()
                ->title(__('capell-admin::invitations.accept.invalid_title'))
                ->body(__('capell-admin::invitations.accept.invalid_body'))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $panel = self::panel();

        if (! $user instanceof FilamentUser || $user->canAccessPanel($panel)) {
            Filament::auth()->login($user);
            session()->regenerate();
        }

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
        return __('capell-admin::invitations.accept.heading');
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::invitations.accept.subheading', ['email' => (string) $this->email]);
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
}
