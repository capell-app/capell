<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Users\Actions;

use Capell\Admin\Actions\Users\InviteUserAction;
use Capell\Admin\Data\Users\UserInvitationData;
use Capell\Admin\Enums\RolePreset;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\HtmlString;

final class InviteUserHeaderAction
{
    public static function make(): Action
    {
        return Action::make('inviteUser')
            ->label(__('capell-admin::invitations.action.label'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalHeading(__('capell-admin::invitations.action.heading'))
            ->modalDescription(__('capell-admin::invitations.action.description'))
            ->modalSubmitActionLabel(__('capell-admin::invitations.action.submit'))
            ->visible(fn (): bool => self::actor() instanceof Authenticatable && InviteUserAction::canInvite(self::actor()))
            ->schema([
                TextInput::make('name')
                    ->label(__('capell-admin::invitations.form.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('capell-admin::invitations.form.email'))
                    ->helperText(__('capell-admin::invitations.form.email_help'))
                    ->email()
                    ->required()
                    ->maxLength(255),
                Radio::make('preset')
                    ->label(__('capell-admin::invitations.form.preset'))
                    ->options(RolePreset::options())
                    ->descriptions(self::presetDescriptions())
                    ->default(RolePreset::ClientEditor->value)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $actor = self::actor();

                if (! $actor instanceof Authenticatable) {
                    return;
                }

                $invitation = UserInvitationData::fromForm($data);

                InviteUserAction::run($invitation, $actor);

                Notification::make()
                    ->title(__('capell-admin::invitations.action.sent_title'))
                    ->body(__('capell-admin::invitations.action.sent_body', ['email' => $invitation->email]))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, HtmlString>
     */
    private static function presetDescriptions(): array
    {
        $descriptions = [];

        foreach (RolePreset::cases() as $preset) {
            $descriptions[$preset->value] = new HtmlString(
                '<span class="block">' . e($preset->description()) . '</span>'
                . self::list(__('capell-admin::role_presets.can_heading'), $preset->canDo())
                . self::list(__('capell-admin::role_presets.cannot_heading'), $preset->cannotDo()),
            );
        }

        return $descriptions;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function list(string $heading, array $lines): string
    {
        $items = implode('', array_map(
            static fn (string $line): string => '<li>' . e($line) . '</li>',
            $lines,
        ));

        return '<span class="mt-2 block font-medium">' . e($heading) . '</span>'
            . '<ul class="list-disc ps-5">' . $items . '</ul>';
    }

    private static function actor(): ?Authenticatable
    {
        $user = auth()->user();

        return $user instanceof Authenticatable ? $user : null;
    }
}
