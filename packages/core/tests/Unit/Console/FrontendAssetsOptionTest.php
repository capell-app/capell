<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\Concerns\HasFrontendAssetsOption;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('resolves frontend asset options from explicit CLI values and safe defaults', function (): void {
    expect(frontendAssetsForOptionValue('default'))->toBe(['resources/css/app.css'])
        ->and(frontendAssetsForOptionValue(' resources/css/app.css, resources/js/app.js ,, '))->toBe([
            'resources/css/app.css',
            'resources/js/app.js',
        ])
        ->and(frontendAssetsForOptionValue([' resources/css/app.css ', '', 'resources/js/app.js']))->toBe([
            'resources/css/app.css',
            'resources/js/app.js',
        ])
        ->and(frontendAssetsForOptionValue(new Collection([
            'resources/css/app.css' => true,
            'resources/js/app.js' => true,
        ])))->toBe([
            'resources/css/app.css',
            'resources/js/app.js',
        ]);
});

it('uses the conventional CSS asset in non-interactive installs when it exists', function (): void {
    $assetPath = base_path('resources/css/app.css');
    $originalContents = File::exists($assetPath) ? (string) File::get($assetPath) : null;

    try {
        File::ensureDirectoryExists(dirname($assetPath));
        File::put($assetPath, 'body { color: inherit; }');

        expect(frontendAssetsForOptionValue(null, interactive: false))->toBe(['resources/css/app.css']);
    } finally {
        if ($originalContents === null) {
            File::delete($assetPath);
        } else {
            File::put($assetPath, $originalContents);
        }
    }
});

it('fails with actionable frontend asset option errors in non-interactive installs', function (): void {
    $assetPath = base_path('resources/css/app.css');
    $originalContents = File::exists($assetPath) ? (string) File::get($assetPath) : null;

    try {
        File::delete($assetPath);

        expect(fn (): ?array => frontendAssetsForOptionValue(' , '))
            ->toThrow(InvalidArgumentException::class, 'The --assets option must contain at least one asset path.');

        expect(fn (): ?array => frontendAssetsForOptionValue(new stdClass))
            ->toThrow(InvalidArgumentException::class, 'The --assets option must be a string, array or collection.');

        expect(fn (): ?array => frontendAssetsForOptionValue(null, interactive: false))
            ->toThrow(RuntimeException::class, 'Frontend asset paths is required in non-interactive mode.');
    } finally {
        if ($originalContents !== null) {
            File::ensureDirectoryExists(dirname($assetPath));
            File::put($assetPath, $originalContents);
        }
    }
});

it('lets interactive installs use or skip detected frontend assets', function (): void {
    $assetPath = base_path('resources/css/app.css');
    $originalContents = File::exists($assetPath) ? (string) File::get($assetPath) : null;

    try {
        File::ensureDirectoryExists(dirname($assetPath));
        File::put($assetPath, 'body { color: inherit; }');

        Artisan::registerCommand(new FrontendAssetsOptionTestCommand(null));

        artisanCommand('capell:test-frontend-assets-option')
            ->expectsQuestion("I've detected your frontend resources:\nresources/css/app.css\n\nIs this correct?", 'use')
            ->expectsOutput('["resources\/css\/app.css"]')
            ->assertExitCode(Command::SUCCESS);

        artisanCommand('capell:test-frontend-assets-option')
            ->expectsQuestion("I've detected your frontend resources:\nresources/css/app.css\n\nIs this correct?", 'skip')
            ->expectsOutput('null')
            ->assertExitCode(Command::SUCCESS);
    } finally {
        if ($originalContents === null) {
            File::delete($assetPath);
        } else {
            File::put($assetPath, $originalContents);
        }
    }
});

it('prompts interactive installs for css and js paths when default assets are missing', function (): void {
    $cssPath = base_path('resources/css/app.css');
    $jsPath = base_path('resources/js/app.js');
    $originalCss = File::exists($cssPath) ? (string) File::get($cssPath) : null;
    $originalJs = File::exists($jsPath) ? (string) File::get($jsPath) : null;

    try {
        File::delete($cssPath);
        File::delete($jsPath);

        Artisan::registerCommand(new FrontendAssetsOptionTestCommand(null));

        artisanCommand('capell:test-frontend-assets-option')
            ->expectsQuestion('App CSS path', 'resources/css/custom.css')
            ->expectsQuestion('App JS path', 'resources/js/custom.js')
            ->expectsOutput('["resources\/css\/custom.css","resources\/js\/custom.js"]')
            ->assertExitCode(Command::SUCCESS);
    } finally {
        if ($originalCss !== null) {
            File::ensureDirectoryExists(dirname($cssPath));
            File::put($cssPath, $originalCss);
        }

        if ($originalJs !== null) {
            File::ensureDirectoryExists(dirname($jsPath));
            File::put($jsPath, $originalJs);
        }
    }
});

function frontendAssetsForOptionValue(mixed $assets, bool $interactive = false): ?array
{
    $command = new FrontendAssetsOptionTestCommand($assets);
    $command->setLaravel(app());

    $input = new ArrayInput([], $command->getDefinition());
    $input->setInteractive($interactive);

    $inputProperty = new ReflectionProperty($command, 'input');
    $inputProperty->setValue($command, $input);

    $outputProperty = new ReflectionProperty($command, 'output');
    $outputProperty->setValue($command, new BufferedOutput);

    return $command->frontendAssets();
}

final class FrontendAssetsOptionTestCommand extends Command
{
    use HasFrontendAssetsOption;

    protected $signature = 'capell:test-frontend-assets-option';

    protected $description = 'Test frontend asset option resolution.';

    public function __construct(private readonly mixed $assets)
    {
        parent::__construct();
    }

    #[Override]
    public function option($key = null)
    {
        if ($key === 'assets') {
            return $this->assets;
        }

        return parent::option($key);
    }

    public function frontendAssets(): ?array
    {
        return $this->getFrontendAssets();
    }

    public function handle(): int
    {
        $this->line(json_encode($this->frontendAssets(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
