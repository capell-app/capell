<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\Auth\IssueAdminLoginLinkAction;
use Capell\Admin\Enums\AdminLoginLinkRefusal;
use Capell\Admin\Support\Auth\AdminLoginLinks;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Prints a one-time admin sign-in link for operators and automated test
 * harnesses. The link is written to standard output only, never logged.
 */
final class AdminLoginLinkCommand extends Command
{
    protected $description = 'Print a one-time sign-in link to the admin panel for a user';

    protected $signature = 'capell:admin-login-link {email : The email address of the user to sign in}';

    public function handle(): int
    {
        if (! AdminLoginLinks::enabled()) {
            $this->components->error((string) __('capell-admin::login_links.command.disabled'));

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $panel = AdminLoginLinks::panel();
        $user = AdminLoginLinks::userProvider($panel)->retrieveByCredentials(['email' => $email]);

        if (! $user instanceof Authenticatable) {
            $this->components->error((string) __('capell-admin::login_links.command.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $refusal = AdminLoginLinks::refusalFor($user, $panel);

        if ($refusal instanceof AdminLoginLinkRefusal) {
            $this->components->error($refusal->message());

            return self::FAILURE;
        }

        $url = IssueAdminLoginLinkAction::run($user, $panel);

        $this->components->info((string) __('capell-admin::login_links.command.created', [
            'email' => $email,
            'minutes' => AdminLoginLinks::expiresInMinutes(),
        ]));
        $this->line($url);

        return self::SUCCESS;
    }
}
