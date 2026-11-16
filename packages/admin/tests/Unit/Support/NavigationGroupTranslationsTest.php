<?php

declare(strict_types=1);

use Capell\Admin\Enums\NavigationGroupEnum;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Assert;

it('translates every group and referenced legacy group key in every shipped admin locale', function (): void {
    $filesystem = new Filesystem;
    $packagesDirectory = dirname(__DIR__, 4);
    $languageDirectory = $packagesDirectory . '/admin/resources/lang';
    $loader = new FileLoader($filesystem, $languageDirectory);
    $loader->addNamespace('capell-admin', $languageDirectory);

    $translator = new Translator($loader, 'en');

    $sourceReferences = [];
    foreach ($filesystem->directories($packagesDirectory) as $packageDirectory) {
        foreach ($filesystem->allFiles($packageDirectory . '/src') as $sourceFile) {
            if ($sourceFile->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/capell-admin::navigation\.group_[a-z0-9_]+/', $sourceFile->getContents(), $matches);
            $sourceReferences = [...$sourceReferences, ...$matches[0]];
        }
    }

    $keys = array_unique([
        ...array_map(fn (NavigationGroupEnum $case): string => $case->translationKey(), NavigationGroupEnum::cases()),
        ...$sourceReferences,
    ]);
    $navigationFiles = glob($languageDirectory . '/*/navigation.php');
    assert(is_array($navigationFiles));
    expect($navigationFiles)->not->toBeEmpty();

    foreach ($navigationFiles as $navigationFile) {
        $locale = basename(dirname($navigationFile));

        foreach ($keys as $key) {
            $translation = $translator->get($key, [], $locale, false);
            expect($translation)->toBeString()->not->toBeEmpty();
            Assert::assertNotSame($key, $translation, $locale . ': ' . $key);
        }
    }
});

it('names every sidebar group after what people manage, in sentence case', function (): void {
    $banned = ['people', 'content', 'growth', 'insights', 'insights & health', 'publishing', 'operations', 'integrations', 'system', 'workflow', 'site'];

    foreach (NavigationGroupEnum::cases() as $case) {
        $label = $case->getLabel();

        expect(strtolower($label))->not->toBeIn($banned)
            ->and($label)->toBe(ucfirst(strtolower($label)));
    }
});
