<?php

declare(strict_types=1);

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Actions\Install\BuildInstallSummaryLinesAction;
use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Events\CapellInstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Runs the real install command with the real executor for the publish, cache and closing steps.
 * Every other step is a no-op, so no package, migration or process side effects happen.
 */
function bindPresetScenarioExecutor(User $author, ?Closure $createContent = null): void
{
    $real = new InstallStepExecutor;

    app()->instance(InstallStepExecutor::class, new class($real, $author, $createContent)
    {
        private bool $created = false;

        public function __construct(private readonly InstallStepExecutor $real, private readonly User $author, private readonly ?Closure $createContent) {}

        public function execute(string $stepKey, InstallRunState $state): InstallRunState
        {
            // The earlier install steps create the site and its pages; the real run is not repeated here.
            if (! $this->created && $this->createContent instanceof Closure) {
                $this->created = true;
                InstallRunState::rememberInitialInstallation($state->inputData->initialInstallation);
                ($this->createContent)();
                InstallRunState::checkpointInitialInstallation();
            }

            if (! in_array($stepKey, [InstallPlan::STEP_CLEAR_CACHES, InstallPlan::STEP_MARK_CORE_INSTALLED], true)) {
                return $state;
            }

            return $this->real->execute($stepKey, $state->setResolvedUser($this->author));
        }
    });
}

function allowPublication(bool $allowed): void
{
    app()->instance(AuthorizesPublicationTransition::class, new readonly class($allowed) implements AuthorizesPublicationTransition
    {
        public function __construct(private bool $allowed) {}

        public function allows(PublicationTransitionRequestData $request): bool
        {
            return $this->allowed;
        }
    });
}

/** @param array<string, mixed> $options */
function runPresetScenario(array $options): array
{
    request()->server->set('argv', ['artisan', 'capell:install', ...array_map(
        static fn (string $name, mixed $value): string => $value === true ? $name : $name . '=' . $value,
        array_keys($options),
        $options,
    )]);
    $output = new BufferedOutput;
    $exit = Artisan::call('capell:install', $options, $output);

    return [$exit, $output->fetch()];
}

beforeEach(function (): void {
    InstallRunState::completeInitialInstallation();
    CapellExtension::query()->whereIn('composer_name', ['capell-app/core', 'capell-app/admin'])->delete();
    Site::query()->delete();
    CapellCore::clearExtensionCache();
    InstallRunState::clearStoppedStep();
    config(['capell.install.recommendations' => [
        'site' => [
            'label' => 'Hartley site',
            'description' => 'A site for testing.',
            'packages' => ['capell-app/core'],
            'order' => 10,
        ],
    ]]);
    GetPluginsAction::mock()->shouldReceive('handle')->andReturn(collect());
    // The Composer dry-run needs the network and the host project's lock file.
    app()->instance(ProcessFactoryInterface::class, new class implements ProcessFactoryInterface
    {
        public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
        {
            return new Process([PHP_BINARY, '--version']);
        }
    });
});

afterEach(function (): void {
    InstallRunState::completeInitialInstallation();
    InstallRunState::clearStoppedStep();
    ClearCachesAction::clearFake();
});

/** @return array<string, mixed> */
function presetScenarioOptions(User $author): array
{
    return [
        '--no-interaction' => true,
        '--url' => 'https://hartley.example.test',
        '--site-name' => 'Hartley Dental',
        '--no-seed-default-data' => true,
        '--no-frontend-build' => true,
        '--preset' => 'site',
        '--publish' => true,
        '--package-mode' => 'custom',
        '--packages' => 'capell-app/core',
        '--user' => $author->email,
    ];
}

it('names the chosen preset in the closing summary', function (): void {
    $author = User::factory()->createOne();
    bindPresetScenarioExecutor($author);
    allowPublication(true);

    [$exit, $output] = runPresetScenario(presetScenarioOptions($author));

    $summary = substr((string) $output, (int) strpos((string) $output, (string) __('capell-core::install.handoff.completed_title')));

    expect($exit)->toBe(0)
        ->and($summary)->toContain((string) __('capell-core::install.handoff.summary.preset'))
        ->toContain('Hartley site')
        ->and(count(explode("\n", trim($summary))))->toBeLessThanOrEqual(BuildInstallSummaryLinesAction::MAX_LINES);
});

it('does not mention a preset in the summary when none was chosen', function (): void {
    $author = User::factory()->createOne();
    bindPresetScenarioExecutor($author);
    $options = presetScenarioOptions($author);
    unset($options['--preset'], $options['--publish']);

    [$exit, $output] = runPresetScenario($options);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain((string) __('capell-core::install.handoff.summary.preset'));
});

it('publishes the draft pages as part of the install', function (): void {
    $author = User::factory()->createOne();
    $page = null;
    bindPresetScenarioExecutor($author, function () use (&$page): void {
        $page = Page::factory()->site(Site::factory()->default()->create())->create(['visible_from' => PublishSentinel::draftValue()]);
    });
    allowPublication(true);

    [$exit] = runPresetScenario(presetScenarioOptions($author));

    expect($exit)->toBe(0)
        ->and($page->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
});

it('publishes the draft pages of a public site spec after importing it', function (): void {
    $author = User::factory()->createOne();
    bindPresetScenarioExecutor($author);
    allowPublication(true);
    $late = null;
    // The spec import announces itself with CapellInstalled; a page that appears then proves publishing runs after the import.
    Event::listen(CapellInstalled::class, function () use (&$late): void {
        $late = Page::factory()->site(Site::query()->latest('id')->firstOrFail())->create(['visible_from' => PublishSentinel::draftValue()]);
    });
    $spec = tempnam(sys_get_temp_dir(), 'capell-spec-');
    file_put_contents($spec, json_encode([
        'site' => ['name' => 'Fixture Site'],
        'theme' => ['key' => 'default'],
        'pages' => [['name' => 'Home', 'slug' => 'home', 'title' => 'Home', 'pageType' => 'default']],
        'initialVisibility' => 'public',
        'acknowledgePublic' => true,
    ], JSON_THROW_ON_ERROR));

    try {
        [$exit] = runPresetScenario([...presetScenarioOptions($author), '--spec' => $spec]);
    } finally {
        unlink($spec);
    }

    expect($exit)->toBe(0)
        ->and($late)->toBeInstanceOf(Page::class)
        ->and($late->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
});

it('fails after the install and names the pages it could not publish', function (): void {
    $author = User::factory()->createOne();
    $page = null;
    bindPresetScenarioExecutor($author, function () use (&$page): void {
        $page = Page::factory()->site(Site::factory()->default()->create())->create(['name' => 'About', 'visible_from' => PublishSentinel::draftValue()]);
    });
    allowPublication(false);

    [$exit, $output] = runPresetScenario(presetScenarioOptions($author));

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('About')
        ->and($page->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft);
});

it('prints a resume command that replays the preset and publish when the install stops', function (): void {
    $author = User::factory()->createOne();
    bindPresetScenarioExecutor($author);
    allowPublication(true);
    ClearCachesAction::mock()->shouldReceive('handle')->andThrow(new RuntimeException('Cache store is unreachable.'));

    [$exit, $output] = runPresetScenario(presetScenarioOptions($author));

    expect($exit)->not->toBe(0)
        ->and(InstallRunState::stoppedStep()?->key)->toBe(InstallPlan::STEP_CLEAR_CACHES)
        ->and($output)->toContain("'--preset=site'")
        ->toContain("'--publish'");
});
