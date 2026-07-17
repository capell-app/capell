<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Capell\Core\Data\NewUserData;
use Capell\Core\Support\Install\InstallInputFactory;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Symfony\Component\Console\Command\Command as CommandAlias;

final class InstallUserPrompter
{
    private const string CreateAdminUserOption = '__create_admin_user__';

    private const string UseExistingAdminUserOption = 'existing';

    /**
     * @param  Closure(string): void  $writeError
     * @param  Closure(string): void  $writeLine
     * @param  Closure(string, array<string, mixed>): void  $logDebug
     * @param  Closure(string, string): void  $requireInteractiveOrFail
     */
    public function __construct(
        private readonly bool $interactive,
        private readonly Closure $writeError,
        private readonly Closure $writeLine,
        private readonly Closure $logDebug,
        private readonly Closure $requireInteractiveOrFail,
    ) {}

    /** @return array{?int, ?NewUserData, ?int} */
    public function resolveUserInput(
        ?string $userEmailOption,
        ?NewUserData $newUserOption,
        bool $freshInstall,
        bool $useFreshDemoDefaults,
    ): array {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model');
        $userTable = (new $userModel)->getTable();

        if ($userEmailOption !== null && $newUserOption instanceof NewUserData) {
            ($this->writeError)('Use either --user for an existing user or --name/--email/--password for a new user, not both.');

            return [null, null, CommandAlias::FAILURE];
        }

        if ($newUserOption instanceof NewUserData) {
            return [null, $newUserOption, null];
        }

        if ($freshInstall && $useFreshDemoDefaults) {
            return [null, $this->defaultDemoAdminUser(), null];
        }

        if (! Schema::hasTable($userTable)) {
            if ($userEmailOption !== null) {
                ($this->writeError)('User table not found: ' . $userTable);

                return [null, null, CommandAlias::FAILURE];
            }

            return [null, $this->promptForNewUser(), null];
        }

        if ($userEmailOption !== null) {
            $user = $userModel::query()->where('email', $userEmailOption)->first();
            if ($user === null) {
                ($this->writeError)('User not found: ' . $userEmailOption);

                return [null, null, CommandAlias::FAILURE];
            }

            return [$user->getKey(), null, null];
        }

        if ($freshInstall) {
            return [null, $this->promptForNewUser(), null];
        }

        $totalUsers = $userModel::query()->count();

        if ($totalUsers === 0) {
            return [null, $this->promptForNewUser(), null];
        }

        $this->requireInteractiveOrFail(
            'Admin user',
            'Pass --user=<email> or --name=<name>, --email=<email>, and --password=<password>.',
        );

        $adminUserMode = select(
            label: 'Which admin user should we use?',
            options: [
                self::UseExistingAdminUserOption => 'Use an existing user',
                self::CreateAdminUserOption => 'Create a new admin user',
            ],
            default: self::UseExistingAdminUserOption,
        );

        if ($adminUserMode === self::CreateAdminUserOption) {
            return [null, $this->promptForNewUser(), null];
        }

        $selectedUser = search(
            label: 'Search for an existing admin user',
            options: fn (string $search): array => $this->existingUserOptions($userModel, $search),
            validate: fn (int|string|null $value): ?string => $this->validateInstallUserSelection($value, $userTable),
        );

        return [(int) $selectedUser, null, null];
    }

    public function newUserFromOptions(mixed $name, mixed $email, mixed $password): ?NewUserData
    {
        $hasAnyOption = $name !== null || $email !== null || $password !== null;
        if ($hasAnyOption) {
            throw_if(
                ! is_string($name) || $name === '' || ! is_string($email) || $email === '' || ! is_string($password) || $password === '',
                InvalidArgumentException::class,
                'Pass --name, --email, and --password together to create the first user non-interactively.',
            );

            return new NewUserData(name: $name, email: $email, password: $password);
        }

        return $this->newUserFromInstallerConfig();
    }

    /** @return array{array<NewUserData>, ?int} */
    public function resolveAdditionalUsersInput(
        bool $createRoleUsers,
        mixed $roleUserPassword,
        InstallInputFactory $installInputFactory,
    ): array {
        if (! $createRoleUsers) {
            return [[], null];
        }

        if (! is_string($roleUserPassword) || $roleUserPassword === '') {
            if (! $this->interactive) {
                ($this->writeError)('Pass --role-user-password=<password> when using --role-users non-interactively.');

                return [[], CommandAlias::FAILURE];
            }

            $this->requireInteractiveOrFail('Example role user password', 'Pass --role-user-password=<password>.');
            $roleUserPassword = password(label: 'Example role user password', required: true);
        }

        return [$installInputFactory->exampleRoleUsers($roleUserPassword), null];
    }

    /**
     * @param  class-string<User>  $userModel
     * @return array<int|string, string>
     */
    public function existingUserOptions(string $userModel, string $search): array
    {
        return $userModel::query()
            ->when(mb_strlen($search) > 0, fn (Builder $query) => $query->whereAny(['name', 'email'], 'like', $search . '%'))
            ->limit(10)
            ->select(['id', 'name', 'email'])
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => sprintf('%s <%s>', $user->name, $user->email),
            ])
            ->all();
    }

    public function validateInstallUserSelection(int|string|null $value, string $userTable): ?string
    {
        if ($value === self::CreateAdminUserOption) {
            return null;
        }

        if (! is_int($value) && ! ctype_digit((string) $value)) {
            return 'Select an existing user or create a new admin user.';
        }

        return Schema::hasTable($userTable) && DB::table($userTable)->where('id', (int) $value)->exists()
            ? null
            : 'The selected user does not exist.';
    }

    private function newUserFromInstallerConfig(): ?NewUserData
    {
        $configured = config('capell-installer.admin_user');
        if (! is_array($configured)) {
            $configured = config('capell.install.admin_user', []);
        }

        if (! is_array($configured)) {
            return null;
        }

        $name = $this->stringConfigValue($configured['name'] ?? null);
        $email = $this->stringConfigValue($configured['email'] ?? null);
        $password = $this->stringConfigValue($configured['password'] ?? null);

        if ($name === '' || $email === '' || $password === '') {
            return null;
        }

        return new NewUserData(name: $name, email: $email, password: $password);
    }

    private function promptForNewUser(): NewUserData
    {
        $this->requireInteractiveOrFail(
            'Admin user',
            'Pass --name=<name>, --email=<email>, and --password=<password>.',
        );

        ($this->writeLine)('Please enter details for the admin user who can log in to Capell.');
        $name = text(label: 'Name', required: true);
        $email = text(label: 'Email', required: true, validate: ['email' => 'email']);
        $password = password(label: 'Password', required: true);

        return new NewUserData(name: $name, email: $email, password: $password);
    }

    private function defaultDemoAdminUser(): NewUserData
    {
        ($this->logDebug)('using default fresh demo admin user', [
            'email' => 'admin@example.test',
        ]);

        return new NewUserData(
            name: 'Capell Admin',
            email: 'admin@example.test',
            password: 'password',
        );
    }

    private function requireInteractiveOrFail(string $requirement, string $hint): void
    {
        ($this->requireInteractiveOrFail)($requirement, $hint);
    }

    private function stringConfigValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
