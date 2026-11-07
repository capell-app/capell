<?php

declare(strict_types=1);

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Capell\Core\Support\Makers\MakerRegistry;
use Capell\Core\Support\Makers\MakerSafety;
use Illuminate\Filesystem\Filesystem;

it('allows php writes in local environments by default', function (): void {
    app()->instance('env', 'local');
    config()->set('capell.diagnostics.php_writes', 'local_only');
    config()->set('capell.diagnostics.allowed_roots', [app_path('Filament/Schemas')]);

    expect(resolve(MakerSafety::class)->current()->phpWritesAllowed)->toBeTrue();
});

it('blocks php writes in production by default', function (): void {
    app()->instance('env', 'production');
    config()->set('capell.diagnostics.php_writes', 'local_only');
    config()->set('capell.diagnostics.allowed_roots', [app_path('Filament/Schemas')]);

    expect(resolve(MakerSafety::class)->current()->phpWritesAllowed)->toBeFalse();
});

it('allows production writes only with explicit config override', function (): void {
    app()->instance('env', 'production');
    config()->set('capell.diagnostics.php_writes', 'enabled');
    config()->set('capell.diagnostics.allowed_roots', [app_path('Filament/Schemas')]);

    expect(resolve(MakerSafety::class)->current()->phpWritesAllowed)->toBeTrue();
});

it('rejects paths outside allowed roots', function (): void {
    config()->set('capell.diagnostics.allowed_roots', [app_path('Filament/Schemas')]);

    expect(resolve(MakerSafety::class)->pathIsAllowed(base_path('.env')))->toBeFalse();
    expect(resolve(MakerSafety::class)->pathIsAllowed(app_path('Filament/Schemas/Pages/HeroSchema.php')))->toBeTrue();
});

it('refuses unsafe maker paths before both preview and write', function (string $kind, bool $dryRun): void {
    $workspace = base_path('var/maker-safety-' . bin2hex(random_bytes(6)));
    $root = $workspace . '/allowed';
    $outside = $workspace . '/outside';
    mkdir($root, 0777, true);
    mkdir($outside, 0777, true);
    file_put_contents($outside . '/existing.php', 'original');
    symlink($outside, $root . '/escape');
    config()->set('capell.diagnostics.allowed_roots', [$root]);
    $path = match ($kind) {
        'traversal' => $root . '/../outside/new.php',
        'absolute' => $outside . '/new.php',
        'symlink-existing' => $root . '/escape/existing.php',
        'symlink-new' => $root . '/escape/new.php',
        'empty' => $root . '//new.php',
        default => throw new InvalidArgumentException('Unknown unsafe path case.'),
    };
    $maker = new class($path) extends AbstractFileMaker
    {
        public function __construct(private readonly string $path) {}

        public function definition(): MakerDefinitionData
        {
            return new MakerDefinitionData('test.unsafe', 'Unsafe', 'Unsafe', 'Test', 'icon', false, true);
        }

        protected function buildPreview(MakerInputData $input): MakerPreviewData
        {
            return $this->previewData($input, collect([$this->fileData($this->path, 'changed', true)]), collect(), collect());
        }
    };
    $registry = new MakerRegistry;
    $registry->register($maker);

    $action = new RunMakerAction($registry, new MakerSafety);

    try {
        expect(fn (): MakerResultData => $action->handle(new MakerInputData('test.unsafe', [], $dryRun, true, false)))
            ->toThrow(RuntimeException::class, 'outside configured maker roots');
        expect(file_get_contents($outside . '/existing.php'))->toBe('original')
            ->and(file_exists($outside . '/new.php'))->toBeFalse();
    } finally {
        unlink($root . '/escape');
        (new Filesystem)->deleteDirectory($workspace);
    }
})->with(['traversal', 'absolute', 'symlink-existing', 'symlink-new', 'empty'])->with([true, false]);
