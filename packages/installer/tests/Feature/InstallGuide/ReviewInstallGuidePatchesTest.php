<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\ApplyFilePatchPlanAction;
use Capell\Core\Data\Patching\PatchPreviewData;
use Capell\Core\Data\Patching\PatchRecoveryData;
use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\FilePatchReview;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewablePatch;
use Capell\Installer\Actions\InstallGuide\ApplyInstallGuidePatchesAction;
use Capell\Installer\Actions\InstallGuide\ApplyReviewedInstallGuidePatchesAction;
use Capell\Installer\Actions\InstallGuide\ReviewInstallGuidePatchesAction;
use Capell\Installer\Data\InstallGuide\ApplyPatchesInputData;
use Capell\Installer\Data\InstallGuide\ApplyPatchesResultData;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelColorsPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelThemePatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelWidgetsPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvQueueConnectionPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;
use Capell\Installer\Support\InstallGuide\Patches\FilesystemsPageCacheDiskPatch;
use Capell\Installer\Support\InstallGuide\Patches\ManualStepPatch;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(CreatesAdminUser::class)->group('installer');

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->originalStoragePath = $this->app->storagePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-guide-review-' . uniqid();
    File::ensureDirectoryExists($this->temporaryBasePath);
    $this->app->setBasePath($this->temporaryBasePath);
    $this->app->useStoragePath($this->temporaryBasePath . '/storage');
    File::put(base_path('composer.json'), json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
    File::put(base_path('.env'), "APP_KEY=do-not-display\nQUEUE_CONNECTION=sync\n");
    $this->app->instance(PatchRegistry::class, new PatchRegistry);
    resolve(PatchRegistry::class)->register(new EnvQueueConnectionPatch)->register(new EnvSettingsCachePatch);
    test()->actingAsAdmin();
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    $this->app->useStoragePath($this->originalStoragePath);
    File::deleteDirectory($this->temporaryBasePath);
});

it('requires a review before the real page can write selected files', function (): void {
    $before = File::get(base_path('.env'));
    Livewire::test(InstallGuidePage::class)
        ->assertSee(__('capell-installer::install-guide.review_changes'))
        ->call('applyPatches');
    expect(File::get(base_path('.env')))->toBe($before);
});

it('previews scoped environment edits without writes or secret disclosure then returns real recovery files', function (): void {
    $before = File::get(base_path('.env'));
    $review = ReviewInstallGuidePatchesAction::run(['env-queue-connection-patch', 'env-settings-cache-patch']);
    expect(File::get(base_path('.env')))->toBe($before)
        ->and(File::isDirectory(base_path('storage')))->toBeFalse();
    $preview = json_encode($review->toArray(), JSON_THROW_ON_ERROR);
    expect($preview)->toContain('QUEUE_CONNECTION')->toContain('database')
        ->not->toContain('do-not-display')->not->toContain('APP_KEY');

    $result = ApplyReviewedInstallGuidePatchesAction::run(['env-queue-connection-patch', 'env-settings-cache-patch'], $review->token);
    expect($result->succeeded())->toHaveCount(2)
        ->and(File::get(base_path('.env')))->toContain('QUEUE_CONNECTION=database')->toContain('SETTINGS_CACHE_ENABLED=true');
    foreach ($result->results as $patchResult) {
        expect($patchResult->recoveryPaths)->toHaveCount(1);
        expect(File::get($patchResult->recoveryPaths[0]))->toContain('QUEUE_CONNECTION=');
    }
});

it('rejects stale files or changed selections before any selected write', function (bool $changeFile): void {
    $review = ReviewInstallGuidePatchesAction::run(['env-queue-connection-patch']);
    if ($changeFile) {
        File::append(base_path('.env'), "OTHER_SETTING=changed\n");
    }

    $before = File::get(base_path('.env'));
    $ids = $changeFile ? ['env-queue-connection-patch'] : ['env-queue-connection-patch', 'env-settings-cache-patch'];
    expect(fn (): mixed => ApplyReviewedInstallGuidePatchesAction::run($ids, $review->token))->toThrow(RuntimeException::class);
    expect(File::get(base_path('.env')))->toBe($before);
})->with([true, false]);

it('consumes reviews so retries require renewed review', function (): void {
    $review = ReviewInstallGuidePatchesAction::run(['env-queue-connection-patch']);
    ApplyReviewedInstallGuidePatchesAction::run(['env-queue-connection-patch'], $review->token);
    expect(fn (): mixed => ApplyReviewedInstallGuidePatchesAction::run(['env-queue-connection-patch'], $review->token))->toThrow(RuntimeException::class);
});

it('renders redacted edits and retains the recovery result after refreshing patch status', function (): void {
    Livewire::test(InstallGuidePage::class)
        ->call('reviewPatches')
        ->assertSeeHtml('data-patch-review="env-queue-connection-patch"')
        ->assertSee('QUEUE_CONNECTION=sync')
        ->assertSee('QUEUE_CONNECTION=database')
        ->assertDontSee('do-not-display')
        ->call('applyPatches')
        ->assertSeeHtml('data-patch-result="env-queue-connection-patch"')
        ->assertSet('reviewToken', null)
        ->assertSet('review', [])
        ->assertSee('install-guide-backups');
});

it('invalidates the displayed review when selection changes and refuses stale file input', function (): void {
    $page = Livewire::test(InstallGuidePage::class)->call('reviewPatches');
    $page->set('data.selectedPatches', ['env-queue-connection-patch'])
        ->assertSet('reviewToken', null)
        ->assertSet('review', [])
        ->call('reviewPatches');
    File::append(base_path('.env'), "PRIVATE_KEY=changed-secret\n");
    $before = File::get(base_path('.env'));
    $page->call('applyPatches')->assertSet('reviewToken', null)->assertSet('patchResults', []);
    expect(File::get(base_path('.env')))->toBe($before);
});

it('reports unsupported steps explicitly and does not invoke their apply path', function (): void {
    $patch = ManualStepPatch::queueWorker();
    resolve(PatchRegistry::class)->register($patch);
    $review = ReviewInstallGuidePatchesAction::run([$patch->id()]);
    expect($review->patches->sole()->preview)->toBeNull()
        ->and($review->patches->sole()->guidance)->toBe(__('capell-installer::install-guide.preview_manual'));
    $result = ApplyReviewedInstallGuidePatchesAction::run([$patch->id()], $review->token);
    expect($result->failed())->toBeEmpty()->and($result->succeeded())->toBeEmpty();
});

it('returns a truthful failure with the existing backup when the reviewed file cannot be written', function (): void {
    $path = base_path('.env');
    chmod($path, 0400);
    try {
        $before = File::get($path);
        $review = ReviewInstallGuidePatchesAction::run(['env-queue-connection-patch']);
        $result = ApplyReviewedInstallGuidePatchesAction::run(['env-queue-connection-patch'], $review->token);
        expect($result->failed())->toHaveCount(1)
            ->and($result->succeeded())->toBeEmpty()
            ->and($result->skipped())->toBeEmpty()
            ->and(File::get($path))->toBe($before);
        expect(File::get($result->failed()->sole()->recoveryPaths[0]))->toBe($before);
    } finally {
        chmod($path, 0600);
    }
});

it('expires reviews and rechecks access before reviewing or applying', function (): void {
    $review = ReviewInstallGuidePatchesAction::run(['env-queue-connection-patch']);
    $this->travel(16)->minutes();
    expect(fn (): mixed => ApplyReviewedInstallGuidePatchesAction::run(['env-queue-connection-patch'], $review->token))->toThrow(RuntimeException::class);
    $this->travelBack();
    auth()->logout();
    expect(fn (): mixed => ReviewInstallGuidePatchesAction::run(['env-queue-connection-patch']))->toThrow(HttpException::class);
    expect(fn (): mixed => ApplyReviewedInstallGuidePatchesAction::run(['env-queue-connection-patch'], $review->token))->toThrow(HttpException::class);
});

it('reviews and applies both theme files through the same read-only theme plan', function (): void {
    $provider = base_path('app/Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($provider));
    File::put($provider, '<?php class AdminPanelProvider { public function panel($panel) { return $panel->id("admin"); } }');
    $patch = new AdminPanelThemePatch(adoptDefault: true);
    resolve(PatchRegistry::class)->register($patch);
    $before = File::get($provider);
    $theme = resource_path('css/filament/admin/theme.css');
    $review = ReviewInstallGuidePatchesAction::run([$patch->id()]);
    expect(File::exists($theme))->toBeFalse()->and(File::get($provider))->toBe($before);
    expect($review->patches->sole()->preview->files)->toHaveCount(2);
    $result = ApplyReviewedInstallGuidePatchesAction::run([$patch->id()], $review->token);
    expect($result->succeeded())->toHaveCount(1)
        ->and(File::get($provider))->toContain('viteTheme')
        ->and(File::get($theme))->toContain('@import')
        ->and($result->results->sole()->createdPaths)->toBe([$theme])
        ->and(File::get($result->results->sole()->recoveryPaths[0]))->toBe($before);
});

it('previews configuration and cumulative panel edits without losing the original recovery file', function (): void {
    $provider = base_path('app/Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($provider));
    File::put($provider, '<?php class AdminPanelProvider { public function panel($panel) { return $panel->id("admin"); } }');
    File::ensureDirectoryExists(base_path('config'));
    File::put(base_path('config/filesystems.php'), '<?php return ["disks" => []];');
    $patches = [
        new AdminPanelColorsPatch,
        new AdminPanelWidgetsPatch,
        new FilesystemsPageCacheDiskPatch,
    ];
    foreach ($patches as $patch) {
        resolve(PatchRegistry::class)->register($patch);
    }

    $ids = array_map(static fn (AdminPanelColorsPatch|AdminPanelWidgetsPatch|FilesystemsPageCacheDiskPatch $patch): string => $patch->id(), $patches);
    $before = File::get($provider);
    $review = ReviewInstallGuidePatchesAction::run($ids);
    expect(File::get($provider))->toBe($before);
    foreach ($review->patches as $patch) {
        expect($patch->preview)->not->toBeNull();
        foreach ($patch->preview->files as $file) {
            expect($file->path)->not->toStartWith('/');
        }
    }

    $result = ApplyReviewedInstallGuidePatchesAction::run($ids, $review->token);
    expect($result->succeeded())->toHaveCount(3)
        ->and(File::get($provider))->toContain('colors')->toContain('widgets')
        ->and((require base_path('config/filesystems.php'))['disks'])->toHaveKey('page_cache');
    $first = $result->results[0]->recoveryPaths[0];
    $second = $result->results[1]->recoveryPaths[0];
    expect($second)->not->toBe($first)
        ->and(File::get($first))->toBe($before)
        ->and(File::get($second))->toContain('colors')->not->toContain('widgets');
});

it('rejects an environment edit made after receipt validation before plan generation', function (): void {
    File::append(base_path('.env'), "OTHER_SETTING=original\n");
    $review = ReviewInstallGuidePatchesAction::run(['env-queue-connection-patch']);
    ApplyInstallGuidePatchesAction::partialMock()->shouldReceive('handle')->once()
        ->andReturnUsing(function (ApplyPatchesInputData $input): ApplyPatchesResultData {
            File::replaceInFile('OTHER_SETTING=original', 'OTHER_SETTING=concurrent-edit', base_path('.env'));

            return new ApplyInstallGuidePatchesAction()->handle($input);
        });

    $result = ApplyReviewedInstallGuidePatchesAction::run(['env-queue-connection-patch'], $review->token);

    expect($result->failed())->toHaveCount(1)
        ->and($result->succeeded())->toBeEmpty()
        ->and($result->failed()->sole()->recoveryPaths)->toBeEmpty()
        ->and(File::get(base_path('.env')))->toContain('QUEUE_CONNECTION=sync')->toContain('OTHER_SETTING=concurrent-edit');
});

it('redacts arbitrary PHP host keys from serialised and rendered configuration reviews', function (): void {
    File::ensureDirectoryExists(base_path('config'));
    File::put(base_path('config/filesystems.php'), "<?php return ['credentials' => ['test-private-token' => 'owner'], 'disks' => []];");
    $patch = new FilesystemsPageCacheDiskPatch;
    resolve(PatchRegistry::class)->register($patch);
    $review = ReviewInstallGuidePatchesAction::run([$patch->id()]);

    expect(json_encode($review->toArray(), JSON_THROW_ON_ERROR))
        ->toContain('disks')->toContain('page_cache')
        ->not->toContain('test-private-token')->not->toContain('credentials')->not->toContain('owner');

    Livewire::test(InstallGuidePage::class)
        ->set('data.selectedPatches', [$patch->id()])
        ->call('reviewPatches')
        ->assertSeeHtml('data-patch-review="' . $patch->id() . '"')
        ->assertSee('page_cache')
        ->assertDontSee('test-private-token')->assertDontSee('credentials')->assertDontSee('owner');
});

it('retains completed recovery results when a later apply-time probe throws', function (bool $page): void {
    $state = new class
    {
        public bool $probeUnavailable = false;
    };
    $patch = Mockery::mock(ReviewablePatch::class);
    $patch->shouldReceive('id')->andReturn('probe-failure');
    $patch->shouldReceive('label')->andReturn('Later probe failure');
    $patch->shouldReceive('description')->andReturn('A reviewable patch with an unavailable target.');
    $patch->shouldReceive('group')->andReturn('environment');
    $patch->shouldReceive('docUrl')->andReturnNull();
    $patch->shouldReceive('reason')->andReturnNull();
    $patch->shouldReceive('defaultEnabled')->andReturnTrue();
    $patch->shouldReceive('preview')->andReturn(new PatchPreviewData([], 'probe-failure'));
    $patch->shouldReceive('recoveryResult')->andReturn(new PatchRecoveryData);
    $patch->shouldReceive('probe')->andReturnUsing(function () use ($state): PatchStatus {
        throw_if($state->probeUnavailable, RuntimeException::class, 'Private probe detail');

        return PatchStatus::Applicable;
    });
    $patch->shouldNotReceive('apply');
    resolve(PatchRegistry::class)->register($patch);
    ApplyInstallGuidePatchesAction::partialMock()->shouldReceive('handle')->once()
        ->andReturnUsing(function (ApplyPatchesInputData $input) use ($state): ApplyPatchesResultData {
            $state->probeUnavailable = true;

            return new ApplyInstallGuidePatchesAction()->handle($input);
        });
    $ids = ['env-queue-connection-patch', 'probe-failure'];
    $before = File::get(base_path('.env'));

    if ($page) {
        $component = Livewire::test(InstallGuidePage::class)
            ->set('data.selectedPatches', $ids)
            ->call('reviewPatches')
            ->call('applyPatches')
            ->assertSet('patchResults.0.failed', false)
            ->assertSet('patchResults.1.failed', true)
            ->assertSet('reviewToken', null)
            ->assertSeeHtml('data-patch-result="env-queue-connection-patch"')
            ->assertSeeHtml('data-patch-result="probe-failure"')
            ->assertSee('install-guide-backups')
            ->assertDontSee('Private probe detail')
            ->assertNotNotified(__('capell-installer::install-guide.apply_failed'));
        $recovery = $component->get('patchResults')[0]['recoveryPaths'];
    } else {
        $review = ReviewInstallGuidePatchesAction::run($ids);
        $result = ApplyReviewedInstallGuidePatchesAction::run($ids, $review->token);
        expect($result->succeeded())->toHaveCount(1)
            ->and($result->failed())->toHaveCount(1)
            ->and($result->failed()->sole()->patchId)->toBe('probe-failure')
            ->and($result->failed()->sole()->errorMessage)->toBe(__('capell-installer::install-guide.reviewed_patch_failed'));
        $recovery = $result->succeeded()->sole()->recoveryPaths;
    }

    expect($recovery)->toHaveCount(1)
        ->and(File::get($recovery[0]))->toBe($before)
        ->and(File::get(base_path('.env')))->toContain('QUEUE_CONNECTION=database')
        ->and(session()->has('capell.install-guide.review'))->toBeFalse();
})->with(['action' => false, 'page' => true]);

it('rejects concurrent host edits between cumulative selected writes while retaining the first recovery', function (): void {
    File::append(base_path('.env'), "OTHER_SETTING=original\n");
    $ids = ['env-queue-connection-patch', 'env-settings-cache-patch'];
    $review = ReviewInstallGuidePatchesAction::run($ids);
    $before = File::get(base_path('.env'));
    $calls = 0;
    ApplyFilePatchPlanAction::partialMock()->shouldReceive('handle')->twice()
        ->andReturnUsing(function (FilePatchPlan $plan, callable $recordRecovery, ?FilePatchReview $fileReview = null) use (&$calls): void {
            new ApplyFilePatchPlanAction()->handle($plan, $recordRecovery, $fileReview);
            if (++$calls === 1) {
                File::replaceInFile('OTHER_SETTING=original', 'OTHER_SETTING=concurrent-edit', base_path('.env'));
            }
        });

    $result = ApplyReviewedInstallGuidePatchesAction::run($ids, $review->token);

    expect($result->succeeded())->toHaveCount(1)
        ->and($result->failed())->toHaveCount(1)
        ->and($result->failed()->sole()->patchId)->toBe('env-settings-cache-patch')
        ->and($result->failed()->sole()->recoveryPaths)->toBeEmpty()
        ->and(File::get($result->succeeded()->sole()->recoveryPaths[0]))->toBe($before)
        ->and(File::get(base_path('.env')))->toContain('QUEUE_CONNECTION=database')->toContain('OTHER_SETTING=concurrent-edit')
        ->not->toContain('SETTINGS_CACHE_ENABLED');
});
