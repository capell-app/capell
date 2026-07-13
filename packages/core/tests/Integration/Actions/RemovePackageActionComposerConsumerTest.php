<?php

declare(strict_types=1);

use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\SymfonyProcessFactory;
use Capell\Core\Tests\Integration\Fixtures\WidgetShowcaseComposerConsumer;
use Illuminate\Filesystem\Filesystem;

it('removes widget-showcase safely in a clean offline Composer consumer', function (): void {
    $consumer = WidgetShowcaseComposerConsumer::create();
    $originalBasePath = app()->basePath();
    $composerEnvironment = $consumer->composerEnvironment();
    $originalProcessEnvironment = [];
    $originalServerEnvironment = [];
    $originalEnvEnvironment = [];
    $ambientComposerState = [
        'process' => getenv('COMPOSER'),
        'server_exists' => array_key_exists('COMPOSER', $_SERVER),
        'server_value' => $_SERVER['COMPOSER'] ?? null,
        'env_exists' => array_key_exists('COMPOSER', $_ENV),
        'env_value' => $_ENV['COMPOSER'] ?? null,
    ];

    try {
        foreach ($composerEnvironment as $key => $value) {
            $originalProcessEnvironment[$key] = getenv($key);
            $originalServerEnvironment[$key] = [
                'exists' => array_key_exists($key, $_SERVER),
                'value' => $_SERVER[$key] ?? null,
            ];
            $originalEnvEnvironment[$key] = [
                'exists' => array_key_exists($key, $_ENV),
                'value' => $_ENV[$key] ?? null,
            ];

            throw_unless(
                putenv($key . '=' . $value),
                RuntimeException::class,
                sprintf('Unable to set the clean consumer process environment key [%s].', $key),
            );

            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }

        expect($composerEnvironment['COMPOSER'])->toBe($consumer->rootPath . '/composer.json')
            ->and(getenv('COMPOSER'))->toBe($consumer->rootPath . '/composer.json')
            ->and($_SERVER['COMPOSER'])->toBe($consumer->rootPath . '/composer.json')
            ->and($_ENV['COMPOSER'])->toBe($consumer->rootPath . '/composer.json');

        app()->setBasePath($consumer->rootPath);
        app()->instance(Filesystem::class, new Filesystem);
        app()->instance(ProcessFactoryInterface::class, new SymfonyProcessFactory);

        foreach (WidgetShowcaseComposerConsumer::MEMBERS as $memberName) {
            CapellCore::registerPackage(
                $memberName,
                path: $consumer->packagePath($memberName),
                version: '^1.1',
            );
        }

        CapellCore::registerPackage(
            WidgetShowcaseComposerConsumer::BUNDLE,
            path: $consumer->packagePath(WidgetShowcaseComposerConsumer::BUNDLE),
            version: '^1.1',
        );
        $bundle = CapellCore::getPackage(WidgetShowcaseComposerConsumer::BUNDLE);
        $bundle->kind = 'bundle';
        $bundle->requirements = WidgetShowcaseComposerConsumer::MEMBERS;

        $originalComposer = $consumer->composerContents();
        $originalLock = $consumer->lockContents();

        expect($consumer->directRequirements())
            ->toHaveKeys([
                WidgetShowcaseComposerConsumer::BUNDLE,
                ...WidgetShowcaseComposerConsumer::ALREADY_DIRECT_MEMBERS,
            ])
            ->and($consumer->lockedPackageNames())
            ->toContain(
                WidgetShowcaseComposerConsumer::BUNDLE,
                WidgetShowcaseComposerConsumer::TRANSITIVE_DEPENDENCY,
                ...WidgetShowcaseComposerConsumer::MEMBERS,
            );

        $finalizationFailure = new RuntimeException('Injected lifecycle finalization failure.');
        $caughtFailure = null;

        try {
            RemovePackageAction::run(
                WidgetShowcaseComposerConsumer::BUNDLE,
                static fn (): never => throw $finalizationFailure,
            );
        } catch (RuntimeException $exception) {
            $caughtFailure = $exception;
        }

        expect($caughtFailure)->toBe($finalizationFailure)
            ->and($consumer->composerContents())->toBe($originalComposer)
            ->and($consumer->lockContents())->toBe($originalLock)
            ->and($consumer->hasInstalledPackage(WidgetShowcaseComposerConsumer::BUNDLE))->toBeTrue()
            ->and($consumer->lockedPackageNames())
            ->toContain(
                WidgetShowcaseComposerConsumer::BUNDLE,
                WidgetShowcaseComposerConsumer::TRANSITIVE_DEPENDENCY,
                ...WidgetShowcaseComposerConsumer::MEMBERS,
            );

        $result = RemovePackageAction::run(WidgetShowcaseComposerConsumer::BUNDLE);
        $requirements = $consumer->directRequirements();
        $lockedPackageNames = $consumer->lockedPackageNames();

        expect($result['status'])->toBe('removed')
            ->and($requirements)->not->toHaveKey(WidgetShowcaseComposerConsumer::BUNDLE)
            ->and($requirements['capell-app/widget-content-reveal'])->toBe('1.1.0')
            ->and($requirements['capell-app/widget-hotspots'])->toBe('^1.1')
            ->and($requirements)->toHaveKeys(WidgetShowcaseComposerConsumer::MEMBERS)
            ->and($requirements)->not->toHaveKey(WidgetShowcaseComposerConsumer::TRANSITIVE_DEPENDENCY)
            ->and($lockedPackageNames)->not->toContain(WidgetShowcaseComposerConsumer::BUNDLE)
            ->and($lockedPackageNames)->toContain(
                WidgetShowcaseComposerConsumer::TRANSITIVE_DEPENDENCY,
                ...WidgetShowcaseComposerConsumer::MEMBERS,
            )
            ->and($consumer->hasInstalledPackage(WidgetShowcaseComposerConsumer::BUNDLE))->toBeFalse();

        $consumer->validateComposerFiles();

        $successfulComposer = $consumer->composerContents();
        $successfulLock = $consumer->lockContents();
        $retryResult = RemovePackageAction::run(WidgetShowcaseComposerConsumer::BUNDLE);

        expect($retryResult['status'])->toBe('removed')
            ->and($consumer->composerContents())->toBe($successfulComposer)
            ->and($consumer->lockContents())->toBe($successfulLock)
            ->and($consumer->hasInstalledPackage(WidgetShowcaseComposerConsumer::BUNDLE))->toBeFalse();
    } finally {
        app()->setBasePath($originalBasePath);

        foreach ($originalProcessEnvironment as $key => $originalProcessValue) {
            $processRestored = $originalProcessValue === false
                ? putenv($key)
                : putenv($key . '=' . $originalProcessValue);

            throw_unless(
                $processRestored,
                RuntimeException::class,
                sprintf('Unable to restore the clean consumer process environment key [%s].', $key),
            );

            $serverState = $originalServerEnvironment[$key] ?? ['exists' => false, 'value' => null];
            if ($serverState['exists']) {
                $_SERVER[$key] = $serverState['value'];
            } else {
                unset($_SERVER[$key]);
            }

            $envState = $originalEnvEnvironment[$key] ?? ['exists' => false, 'value' => null];
            if ($envState['exists']) {
                $_ENV[$key] = $envState['value'];
            } else {
                unset($_ENV[$key]);
            }
        }

        $consumer->destroy();
    }

    expect([
        'process' => getenv('COMPOSER'),
        'server_exists' => array_key_exists('COMPOSER', $_SERVER),
        'server_value' => $_SERVER['COMPOSER'] ?? null,
        'env_exists' => array_key_exists('COMPOSER', $_ENV),
        'env_value' => $_ENV['COMPOSER'] ?? null,
    ])->toBe($ambientComposerState);
});
