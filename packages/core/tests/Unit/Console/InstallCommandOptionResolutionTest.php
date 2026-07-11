<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\InstallCommand;
use Capell\Core\Data\NewUserData;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('parses fresh install options and rejects unsupported values', function (): void {
    expect(callInstallCommandMethod(installCommandForOptions([]), 'freshInstallOptions'))->toBe([false, false])
        ->and(callInstallCommandMethod(installCommandForOptions(['--fresh' => true]), 'freshInstallOptions'))->toBe([true, false])
        ->and(callInstallCommandMethod(installCommandForOptions(['--fresh' => 'force']), 'freshInstallOptions'))->toBe([true, true]);

    expect(fn (): mixed => callInstallCommandMethod(installCommandForOptions(['--fresh' => 'yes']), 'freshInstallOptions'))
        ->toThrow(InvalidArgumentException::class, 'only accepts "force"');
});

it('resolves demo languages and sites from explicit options or safe defaults', function (): void {
    config([
        'app.locale' => 'cy',
        'app.name' => 'Capell Demo',
    ]);

    expect(callInstallCommandMethod(installCommandForOptions(['--languages' => 'en, fr, ,de']), 'resolveLanguages'))
        ->toBe(['en', 'fr', 'de'])
        ->and(callInstallCommandMethod(installCommandForOptions(['--sites' => 'Main, Knowledge, ,Services']), 'resolveSites'))
        ->toBe(['Main', 'Knowledge', 'Services'])
        ->and(callInstallCommandMethod(installCommandForOptions(['--demo' => true]), 'resolveLanguages'))
        ->toBe(['en', 'cy', 'fr', 'de'])
        ->and(callInstallCommandMethod(installCommandForOptions(['--demo' => true]), 'resolveSites'))
        ->toBe(['Capell Demo', 'Capell Knowledge', 'Capell Services'])
        ->and(callInstallCommandMethod(installCommandForOptions([]), 'resolveLanguages'))
        ->toBe(['cy'])
        ->and(callInstallCommandMethod(installCommandForOptions([]), 'resolveSites'))
        ->toBe(['Capell Demo']);
});

it('builds install intro details from selected command modes', function (): void {
    expect(callInstallCommandMethod(
        installCommandForOptions([]),
        'installCommandIntroDetails',
        true,
        false,
        true,
        true,
        true,
    ))->toBe([
        'a fresh database refresh',
        'demo content',
        'a plan-only preview',
        'side effects disabled',
    ])
        ->and(callInstallCommandMethod(
            installCommandForOptions([]),
            'installCommandIntroDetails',
            true,
            true,
            false,
            false,
            false,
        ))->toBe(['a forced fresh database refresh']);
});

it('uses cache defaults that exist for the current application', function (): void {
    $command = installCommandForOptions([]);
    $buffer = new BufferedOutput;
    $outputProperty = new ReflectionProperty($command, 'output');
    $outputProperty->setValue($command, new OutputStyle(new ArrayInput([], $command->getDefinition()), $buffer));

    expect(callInstallCommandMethod($command, 'baseCacheOptions'))->toHaveKeys(['all', 'page', 'config', 'views'])
        ->and(callInstallCommandMethod($command, 'defaultCacheKeys'))->toContain('page', 'config', 'views', 'admin')
        ->and(callInstallCommandMethod($command, 'defaultCachesToClear', [
            'page' => 'Page cache',
            'views' => 'Views cache',
        ]))->toBe(['page', 'views'])
        ->and(callInstallCommandMethod($command, 'resolveCachesToClear', true, false))->toBe(['all'])
        ->and(callInstallCommandMethod($command, 'resolveCachesToClear', false, true))->toBe(['all']);
});

it('creates new admin user data only when all non-interactive options are present', function (): void {
    $newUser = callInstallCommandMethod(installCommandForOptions([
        '--name' => 'Capell Admin',
        '--email' => 'admin@example.test',
        '--password' => 'secret-password',
    ]), 'newUserFromOptions');

    expect($newUser)->toBeInstanceOf(NewUserData::class)
        ->and($newUser->name)->toBe('Capell Admin')
        ->and($newUser->email)->toBe('admin@example.test')
        ->and($newUser->password)->toBe('secret-password');

    expect(fn (): mixed => callInstallCommandMethod(installCommandForOptions([
        '--name' => 'Missing email',
    ]), 'newUserFromOptions'))->toThrow(InvalidArgumentException::class, 'Pass --name, --email, and --password together');
});

it('uses installer configuration for first user defaults when options are absent', function (): void {
    config([
        'capell-installer.admin_user' => [
            'name' => 'Configured Admin',
            'email' => 'configured@example.test',
            'password' => 'configured-password',
        ],
        'app.url' => 'https://capell.example.test',
    ]);

    $command = installCommandForOptions([]);
    $configuredUser = callInstallCommandMethod($command, 'newUserFromOptions');

    expect($configuredUser)->toBeInstanceOf(NewUserData::class)
        ->and($configuredUser->name)->toBe('Configured Admin')
        ->and($configuredUser->email)->toBe('configured@example.test')
        ->and($configuredUser->password)->toBe('configured-password')
        ->and(callInstallCommandMethod($command, 'defaultSiteUrl'))->toBe('https://capell.example.test')
        ->and(callInstallCommandMethod($command, 'stringConfigValue', '  trimmed  '))->toBe('trimmed')
        ->and(callInstallCommandMethod($command, 'stringConfigValue', ['not' => 'a string']))->toBe('');
});

it('resolves non-interactive fresh demo defaults without prompting', function (): void {
    config([
        'app.locale' => 'cy',
        'app.name' => 'Capell Demo',
    ]);

    app()->instance(DeveloperToolingInstallationState::class, new class(false) extends DeveloperToolingInstallationState
    {
        public function __construct(private readonly bool $installed) {}

        public function isInstalled(): bool
        {
            return $this->installed;
        }
    });

    $freshDemoCommand = installCommandForOptions([
        '--fresh' => 'force',
        '--demo' => true,
        '--package-mode' => 'all',
    ]);
    $explicitDemoCommand = installCommandForOptions([
        '--fresh' => 'force',
        '--demo' => true,
        '--url' => 'https://example.test',
    ]);
    $allPackagesCommand = installCommandForOptions([
        '--all-packages' => true,
    ]);

    expect(callInstallCommandMethod($freshDemoCommand, 'shouldUseFreshDemoDefaults'))->toBeTrue()
        ->and(callInstallCommandMethod($freshDemoCommand, 'shouldUseFreshDemoPackageDefaults'))->toBeTrue()
        ->and(callInstallCommandMethod($freshDemoCommand, 'shouldInstallAllPackages'))->toBeTrue()
        ->and(callInstallCommandMethod($freshDemoCommand, 'shouldIncludeDemoPackagesAfterSelection'))->toBeTrue()
        ->and(callInstallCommandMethod($freshDemoCommand, 'developerToolingOptionsForPlan'))->toBe([false, false])
        ->and(callInstallCommandMethod($freshDemoCommand, 'resolveLanguages'))->toBe(['en', 'cy', 'fr', 'de'])
        ->and(callInstallCommandMethod($freshDemoCommand, 'resolveSites'))->toBe(['Capell Demo', 'Capell Knowledge', 'Capell Services'])
        ->and(callInstallCommandMethod($explicitDemoCommand, 'shouldUseFreshDemoDefaults'))->toBeFalse()
        ->and(callInstallCommandMethod($allPackagesCommand, 'shouldInstallAllPackages'))->toBeTrue();
});

it('covers non-interactive install command branch decisions and manual-change reporting', function (): void {
    app()->instance(DeveloperToolingInstallationState::class, new class(true) extends DeveloperToolingInstallationState
    {
        public function __construct(private readonly bool $installed) {}

        public function isInstalled(): bool
        {
            return $this->installed;
        }
    });

    $allPackagesCommand = installCommandForOptions([
        '--all-packages' => true,
    ]);
    $developerToolingCommand = installCommandForOptions([]);
    $invalidThemeCommand = installCommandForOptions([
        '--theme' => 'missing-theme',
    ]);

    expect(callInstallCommandMethod($allPackagesCommand, 'shouldIncludeDemoPackagesAfterSelection'))->toBeTrue()
        ->and(callInstallCommandMethod($developerToolingCommand, 'developerToolingOptionsForPlan'))->toBe([true, false])
        ->and(callInstallCommandMethod($invalidThemeCommand, 'resolveThemeSelection'))->toBe([null, SymfonyCommand::FAILURE])
        ->and(callInstallCommandMethod($invalidThemeCommand, 'formatThemeCandidatesForConsole', [
            'none' => 'No starter theme',
            'foundation' => 'Foundation Theme',
        ]))->toBe('none (No starter theme), foundation (Foundation Theme)');

    $command = installCommandForOptions([]);
    $buffer = new BufferedOutput;
    $outputProperty = new ReflectionProperty($command, 'output');
    $outputProperty->setValue($command, new OutputStyle(new ArrayInput([], $command->getDefinition()), $buffer));

    callInstallCommandMethod(
        $command,
        'configureWelcomeRouteManuallyOnFailure',
        fn (): never => throw new RuntimeException('permission denied'),
        'Set CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false in .env.',
    );
    callInstallCommandMethod($command, 'recordManualInstallChange', 'Review app/Providers/Filament/AdminPanelProvider.php.');
    callInstallCommandMethod($command, 'recordManualInstallChange', 'Review app/Providers/Filament/AdminPanelProvider.php.');
    callInstallCommandMethod($command, 'reportManualInstallChanges');

    $output = $buffer->fetch();

    expect($output)->toContain('Unable to update .env automatically')
        ->and($output)->toContain('Manual install changes required')
        ->and($output)->toContain('Set CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false in .env. permission denied')
        ->and(substr_count($output, 'Review app/Providers/Filament/AdminPanelProvider.php.'))->toBe(1);
});

it('fails existing-user resolution when the application user table is missing', function (): void {
    $command = installCommandForOptions([]);
    $userTable = (new User)->getTable();

    Schema::drop($userTable);

    expect(callInstallCommandMethod($command, 'resolveUserInput', 'missing@example.test', null, false))
        ->toBe([null, null, SymfonyCommand::FAILURE]);
});

it('resolves command user and role-user inputs without interactive prompts', function (): void {
    $newUser = new NewUserData(
        name: 'Capell Admin',
        email: 'admin@example.test',
        password: 'secret-password',
    );

    $conflictingUserCommand = installCommandForOptions([
        '--fresh' => 'force',
        '--demo' => true,
        '--user' => 'existing@example.test',
    ]);
    $freshDemoCommand = installCommandForOptions([
        '--fresh' => 'force',
        '--demo' => true,
    ]);
    $roleUsersWithoutPasswordCommand = installCommandForOptions([
        '--role-users' => true,
    ]);
    $roleUsersCommand = installCommandForOptions([
        '--role-users' => true,
        '--role-user-password' => 'shared-password',
    ]);

    $conflict = callInstallCommandMethod($conflictingUserCommand, 'resolveUserInput', 'existing@example.test', $newUser, true);
    $freshDemoUser = callInstallCommandMethod($freshDemoCommand, 'resolveUserInput', null, null, true);
    $missingPassword = callInstallCommandMethod($roleUsersWithoutPasswordCommand, 'resolveAdditionalUsersInput');
    $roleUsers = callInstallCommandMethod($roleUsersCommand, 'resolveAdditionalUsersInput');

    expect($conflict)->toBe([null, null, SymfonyCommand::FAILURE])
        ->and($freshDemoUser[0])->toBeNull()
        ->and($freshDemoUser[1])->toBeInstanceOf(NewUserData::class)
        ->and($freshDemoUser[1]->email)->toBe('admin@example.test')
        ->and($freshDemoUser[2])->toBeNull()
        ->and($missingPassword)->toBe([[], SymfonyCommand::FAILURE])
        ->and($roleUsers[0])->toHaveCount(2)
        ->and($roleUsers[0][0]->email)->toBe('super-admin@example.test')
        ->and($roleUsers[0][0]->roleName)->toBe('super_admin')
        ->and($roleUsers[0][1]->email)->toBe('editor@example.test')
        ->and($roleUsers[0][1]->roleName)->toBe('editor')
        ->and($roleUsers[1])->toBeNull();
});

it('normalises array list options and exposes install selection sentinels', function (): void {
    $command = installCommandForOptions([
        '--languages' => [' en ', '', 'fr'],
        '--sites' => [' Main Site ', null, 'Knowledge'],
    ]);

    expect(callInstallCommandMethod($command, 'parseListOption', 'languages'))->toBe(['en', 'fr'])
        ->and(callInstallCommandMethod($command, 'parseListOption', 'sites'))->toBe(['Main Site', 'Knowledge'])
        ->and(callInstallCommandMethod(installCommandForOptions(['--languages' => ' , ']), 'parseListOption', 'languages'))->toBeNull()
        ->and(callInstallCommandMethod($command, 'createAdminUserOption'))->toBe('__create_admin_user__')
        ->and(callInstallCommandMethod($command, 'useExistingAdminUserOption'))->toBe('existing')
        ->and(callInstallCommandMethod($command, 'installerPackageName'))->toBe('capell-app/installer')
        ->and(callInstallCommandMethod($command, 'optionalCacheOptions'))->toHaveKeys([
            'admin',
            'components',
            'widgets',
            'configurators',
            'filament-components',
        ]);
});

it('validates existing admin user selections before install input is built', function (): void {
    $user = User::factory()->createOne([
        'name' => 'Install Admin',
        'email' => 'install-admin@example.test',
    ]);
    $command = installCommandForOptions([]);
    $userTable = (new User)->getTable();

    expect(callInstallCommandMethod($command, 'existingUserOptions', User::class, 'Install'))->toBe([
        $user->getKey() => 'Install Admin <install-admin@example.test>',
    ])
        ->and(callInstallCommandMethod($command, 'validateInstallUserSelection', '__create_admin_user__', $userTable))->toBeNull()
        ->and(callInstallCommandMethod($command, 'validateInstallUserSelection', 'not-a-user-id', $userTable))
        ->toBe('Select an existing user or create a new admin user.')
        ->and(callInstallCommandMethod($command, 'validateInstallUserSelection', (string) $user->getKey(), $userTable))->toBeNull()
        ->and(callInstallCommandMethod($command, 'validateInstallUserSelection', (string) ($user->getKey() + 1000), $userTable))
        ->toBe('The selected user does not exist.');

    Schema::drop($userTable);

    expect(callInstallCommandMethod($command, 'validateInstallUserSelection', (string) $user->getKey(), $userTable))
        ->toBe('The selected user does not exist.');
});

it('ignores incomplete installer admin user config', function (): void {
    config([
        'capell-installer.admin_user' => 'not an array',
        'capell.install.admin_user' => [
            'name' => '  ',
            'email' => 'fallback@example.test',
            'password' => 'password',
        ],
    ]);

    expect(callInstallCommandMethod(installCommandForOptions([]), 'newUserFromOptions'))->toBeNull();
});

/**
 * @param  array<string, mixed>  $options
 */
function installCommandForOptions(array $options, bool $interactive = false): InstallCommand
{
    $command = new InstallCommand;
    $command->setLaravel(app());

    $input = new ArrayInput($options, $command->getDefinition());
    $input->setInteractive($interactive);

    $inputProperty = new ReflectionProperty($command, 'input');
    $inputProperty->setValue($command, $input);

    $outputProperty = new ReflectionProperty($command, 'output');
    $outputProperty->setValue($command, new BufferedOutput);

    return $command;
}

function callInstallCommandMethod(InstallCommand $command, string $method, mixed ...$arguments): mixed
{
    $reflectionMethod = new ReflectionMethod($command, $method);

    return $reflectionMethod->invoke($command, ...$arguments);
}
