<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\InstallCommand;

it('normalizes php memory limit units for the installer floor', function (): void {
    $command = installCommandForOptions([]);

    expect(callInstallCommandMethod($command, 'memoryLimitInBytes', '128M'))->toBe(134_217_728)
        ->and(callInstallCommandMethod($command, 'memoryLimitInBytes', '1G'))->toBe(1_073_741_824)
        ->and(callInstallCommandMethod($command, 'memoryLimitInBytes', '524288K'))->toBe(536_870_912)
        ->and(callInstallCommandMethod($command, 'memoryLimitInBytes', '536870912'))->toBe(536_870_912);
});
use Capell\Core\Data\Install\DeveloperToolingChoiceData;
use Capell\Core\Data\Install\InstallHandoffData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallCacheOptionCatalog;
use Capell\Core\Support\Install\Cli\InstallCommandPresenter;
use Capell\Core\Support\Install\Cli\InstallPackageSetComposer;
use Capell\Core\Support\Install\Cli\InstallUserPrompter;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
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
    $presenter = new InstallCommandPresenter;

    expect($presenter->introDetails(
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
        ->and($presenter->introDetails(
            true,
            true,
            false,
            false,
            false,
        ))->toBe(['a forced fresh database refresh']);
});

it('renders the install handoff details in their established order', function (): void {
    $buffer = new BufferedOutput;
    $output = new OutputStyle(new ArrayInput([]), $buffer);

    (new InstallCommandPresenter)->outputHandoff(
        new InstallHandoffData(
            schemaVersion: 1,
            status: 'completed',
            selectedPackages: ['capell-app/core'],
            outcomes: [
                'migrations' => 'Completed',
                'setup' => 'Completed',
                'doctor' => 'Healthy',
            ],
            urls: [
                'admin' => 'https://example.test/admin',
                'public' => 'https://example.test',
            ],
            firstPage: ['status' => 'editable'],
            warnings: ['Review the admin panel.'],
            nextAction: [
                'label' => 'Open the admin panel',
                'url' => 'https://example.test/admin',
            ],
            publicImpact: [
                'summary' => 'The public site is ready.',
                'accountConnection' => 'not_required',
                'telemetrySubmission' => 'not_performed',
            ],
        ),
        true,
        $output,
        new Factory($output),
    );

    $renderedHandoff = $buffer->fetch();
    $headerPosition = strpos($renderedHandoff, 'Capell Install Handoff');
    $packagesPosition = strpos($renderedHandoff, 'Selected packages');
    $migrationsPosition = strpos($renderedHandoff, 'Migrations');
    $privacyPosition = strpos($renderedHandoff, 'No Capell account connection or telemetry identity submission is required for this handoff.');
    $writtenPosition = strpos($renderedHandoff, 'Machine-readable install handoff written.');

    expect($headerPosition)->toBeInt()
        ->and($packagesPosition)->toBeInt()
        ->and($migrationsPosition)->toBeInt()
        ->and($privacyPosition)->toBeInt()
        ->and($writtenPosition)->toBeInt()
        ->and($headerPosition)->toBeLessThan($packagesPosition)
        ->and($packagesPosition)->toBeLessThan($migrationsPosition)
        ->and($migrationsPosition)->toBeLessThan($privacyPosition)
        ->and($privacyPosition)->toBeLessThan($writtenPosition);
});

it('uses cache defaults that exist for the current application', function (): void {
    $command = installCommandForOptions([]);
    $buffer = new BufferedOutput;
    $outputProperty = new ReflectionProperty($command, 'output');
    $outputProperty->setValue($command, new OutputStyle(new ArrayInput([], $command->getDefinition()), $buffer));

    expect(InstallCacheOptionCatalog::baseOptions())->toHaveKeys(['all', 'page', 'config', 'views'])
        ->and(InstallCacheOptionCatalog::defaultKeys())->toContain('page', 'config', 'views', 'admin')
        ->and(callInstallCommandMethod($command, 'defaultCachesToClear', [
            'page' => 'Page cache',
            'views' => 'Views cache',
        ]))->toBe(['page', 'views'])
        ->and(callInstallCommandMethod($command, 'resolveCachesToClear', true, false))->toBe(['all'])
        ->and(callInstallCommandMethod($command, 'resolveCachesToClear', false, true))->toBe(['all']);
});

it('creates new admin user data only when all non-interactive options are present', function (): void {
    $newUser = requiredInstallNewUser(installUserPrompterForOptions([
        '--name' => 'Capell Admin',
        '--email' => 'admin@example.test',
        '--password' => 'secret-password',
    ])->newUserFromOptions('Capell Admin', 'admin@example.test', 'secret-password'));

    expect($newUser)->toBeInstanceOf(NewUserData::class)
        ->and($newUser->name)->toBe('Capell Admin')
        ->and($newUser->email)->toBe('admin@example.test')
        ->and($newUser->password)->toBe('secret-password');

    expect(fn (): ?NewUserData => installUserPrompterForOptions([])->newUserFromOptions('Missing email', null, null))
        ->toThrow(InvalidArgumentException::class, 'Pass --name, --email, and --password together');
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
    $configuredUser = requiredInstallNewUser(installUserPrompterForOptions([])->newUserFromOptions(null, null, null));

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
        ->and(developerToolingChoiceForOptions($freshDemoCommand)->installDeveloperTooling)->toBeFalse()
        ->and(developerToolingChoiceForOptions($freshDemoCommand)->configureBoostDeveloperTooling)->toBeFalse()
        ->and(callInstallCommandMethod($freshDemoCommand, 'resolveLanguages'))->toBe(['en', 'cy', 'fr', 'de'])
        ->and(callInstallCommandMethod($freshDemoCommand, 'resolveSites'))->toBe(['Capell Demo', 'Capell Knowledge', 'Capell Services'])
        ->and(callInstallCommandMethod($explicitDemoCommand, 'shouldUseFreshDemoDefaults'))->toBeFalse()
        ->and(callInstallCommandMethod($allPackagesCommand, 'shouldInstallAllPackages'))->toBeTrue();
});

it('requires missing foundation packages when all-package mode is selected', function (): void {
    CapellCore::clearPackages();

    $command = installCommandForOptions([
        '--package-mode' => 'all',
    ]);

    expect(callInstallCommandMethod($command, 'installTimePackageNamesFromSelection'))
        ->toBe([
            'capell-app/admin',
            'capell-app/frontend',
            'capell-app/marketplace',
        ]);
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
        ->and(developerToolingChoiceForOptions($developerToolingCommand)->installDeveloperTooling)->toBeTrue()
        ->and(developerToolingChoiceForOptions($developerToolingCommand)->configureBoostDeveloperTooling)->toBeFalse()
        ->and(callInstallCommandMethod($invalidThemeCommand, 'resolveThemeSelection'))->toBe([null, SymfonyCommand::FAILURE])
        ->and(resolve(InstallPackageSetComposer::class)->formatThemeCandidatesForConsole([
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
    $command->reportManualChanges();

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

    expect(installUserPrompterForOptions([])->resolveUserInput('missing@example.test', null, false, false))
        ->toBe([null, null, SymfonyCommand::FAILURE]);
});

it('uses the install command non-interactive fallback before prompting for an admin user', function (): void {
    $userTable = (new User)->getTable();

    Schema::drop($userTable);

    expect(fn (): array => installUserPrompterForOptions([])->resolveUserInput(null, null, false, false))
        ->toThrow(
            RuntimeException::class,
            'Admin user is required in non-interactive mode. Pass --name=<name>, --email=<email>, and --password=<password>.',
        );
});

it('resolves command user and role-user inputs without interactive prompts', function (): void {
    $newUser = new NewUserData(
        name: 'Capell Admin',
        email: 'admin@example.test',
        password: 'secret-password',
    );

    $prompter = installUserPrompterForOptions([]);

    $conflict = $prompter->resolveUserInput('existing@example.test', $newUser, true, true);
    $freshDemoUser = $prompter->resolveUserInput(null, null, true, true);
    $missingPassword = $prompter->resolveAdditionalUsersInput(true, null, resolve(InstallInputFactory::class));
    $roleUsers = $prompter->resolveAdditionalUsersInput(true, 'shared-password', resolve(InstallInputFactory::class));

    expect($conflict)->toBe([null, null, SymfonyCommand::FAILURE])
        ->and($freshDemoUser[0])->toBeNull()
        ->and($freshDemoUser[1])->toBeInstanceOf(NewUserData::class)
        ->and(requiredInstallNewUser($freshDemoUser[1])->email)->toBe('admin@example.test')
        ->and($freshDemoUser[2])->toBeNull()
        ->and($missingPassword)->toBe([[], SymfonyCommand::FAILURE])
        ->and($roleUsers[0])->toHaveCount(2)
        ->and($roleUsers[0][0]->email)->toBe('super-admin@example.test')
        ->and($roleUsers[0][0]->roleName)->toBe('super_admin')
        ->and($roleUsers[0][1]->email)->toBe('editor@example.test')
        ->and($roleUsers[0][1]->roleName)->toBe('editor')
        ->and($roleUsers[1])->toBeNull();
});

it('normalises array list options and exposes installer package options', function (): void {
    $command = installCommandForOptions([
        '--languages' => [' en ', '', 'fr'],
        '--sites' => [' Main Site ', null, 'Knowledge'],
    ]);

    expect(callInstallCommandMethod($command, 'parseListOption', 'languages'))->toBe(['en', 'fr'])
        ->and(callInstallCommandMethod($command, 'parseListOption', 'sites'))->toBe(['Main Site', 'Knowledge'])
        ->and(callInstallCommandMethod(installCommandForOptions(['--languages' => ' , ']), 'parseListOption', 'languages'))->toBeNull()
        ->and(callInstallCommandMethod($command, 'installerPackageName'))->toBe('capell-app/installer')
        ->and(InstallCacheOptionCatalog::optionalOptions())->toHaveKeys([
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
    $prompter = installUserPrompterForOptions([]);
    $userTable = (new User)->getTable();

    expect($prompter->existingUserOptions(User::class, 'Install'))->toBe([
        $user->getKey() => 'Install Admin <install-admin@example.test>',
    ])
        ->and($prompter->validateInstallUserSelection('__create_admin_user__', $userTable))->toBeNull()
        ->and($prompter->validateInstallUserSelection('not-a-user-id', $userTable))
        ->toBe('Select an existing user or create a new admin user.')
        ->and($prompter->validateInstallUserSelection((string) $user->getKey(), $userTable))->toBeNull()
        ->and($prompter->validateInstallUserSelection((string) ($user->getKey() + 1000), $userTable))
        ->toBe('The selected user does not exist.');

    Schema::drop($userTable);

    expect($prompter->validateInstallUserSelection((string) $user->getKey(), $userTable))
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

    expect(installUserPrompterForOptions([])->newUserFromOptions(null, null, null))->toBeNull();
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

/**
 * @param  array<string, mixed>  $options
 */
function installUserPrompterForOptions(array $options, bool $interactive = false): InstallUserPrompter
{
    return callInstallCommandMethod(installCommandForOptions($options, $interactive), 'userPrompter');
}

function requiredInstallNewUser(?NewUserData $newUser): NewUserData
{
    if ($newUser === null) {
        throw new RuntimeException('Expected installer user data.');
    }

    return $newUser;
}

function callInstallCommandMethod(InstallCommand $command, string $method, mixed ...$arguments): mixed
{
    $reflectionMethod = new ReflectionMethod($command, $method);

    return $reflectionMethod->invoke($command, ...$arguments);
}

function developerToolingChoiceForOptions(InstallCommand $command): DeveloperToolingChoiceData
{
    $choice = callInstallCommandMethod($command, 'developerToolingOptionsForPlan');

    if (! $choice instanceof DeveloperToolingChoiceData) {
        throw new RuntimeException('Expected developer tooling choice data.');
    }

    return $choice;
}
