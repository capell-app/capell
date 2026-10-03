<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Assert;

it('translates every referenced navigation group in every shipped admin locale', function (): void {
    $filesystem = new Filesystem;
    $packagesDirectory = dirname(__DIR__, 4);
    $languageDirectory = $packagesDirectory . '/admin/resources/lang';
    $loader = new FileLoader($filesystem, $languageDirectory);
    $loader->addNamespace('capell-admin', $languageDirectory);

    $translator = new Translator($loader, 'en');

    // Companion-only groups are part of Admin's translation contract even when
    // Notes, Socials, Social Feeds and Shopify Commerce are not installed here.
    $navigationGroupKeys = [
        'capell-admin::navigation.group_extensions',
        'capell-admin::navigation.group_growth',
        'capell-admin::navigation.group_integrations',
    ];

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

    expect($sourceReferences)->not->toBeEmpty();

    $navigationGroupKeys = array_unique([...$navigationGroupKeys, ...$sourceReferences]);
    $navigationFiles = glob($languageDirectory . '/*/navigation.php');

    assert(is_array($navigationFiles));
    expect($navigationFiles)->not->toBeEmpty();

    foreach ($navigationFiles as $navigationFile) {
        $locale = basename(dirname($navigationFile));

        foreach ($navigationGroupKeys as $navigationGroupKey) {
            $translation = $translator->get($navigationGroupKey, [], $locale, false);

            expect($translation)
                ->toBeString()
                ->not->toBeEmpty();

            Assert::assertNotSame($navigationGroupKey, $translation, $locale . ': ' . $navigationGroupKey);
        }
    }
});
