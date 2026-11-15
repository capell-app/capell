<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/scripts/check-docs-class-references.php';

function docsClassReferenceFixture(string $guide): string
{
    $root = sys_get_temp_dir() . '/capell-docs-classes-' . bin2hex(random_bytes(6));
    mkdir($root . '/packages/core/src/Support', 0777, true);
    mkdir($root . '/docs/packages', 0777, true);
    file_put_contents($root . '/packages/core/composer.json', json_encode(['autoload' => ['psr-4' => ['Capell\\Core\\' => 'src']]], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/packages/core/src/Support/Existing.php', "<?php\n");
    file_put_contents($root . '/docs/packages/guide.md', $guide);

    return $root;
}

it('accepts classes and namespaces that exist in the monorepo', function (): void {
    $root = docsClassReferenceFixture("Use `Capell\\Core\\Support\\Existing`, the `Capell\\Core\\Support` namespace and the JSON form `Capell\\\\Core\\\\Support\\\\Existing`.\n");

    try {
        expect(capellDocsMissingClassReferences($root))->toBe([]);
    } finally {
        (new Filesystem)->remove($root);
    }
});

it('names a class a guide invents together with its file and line', function (): void {
    $root = docsClassReferenceFixture("Intro\n\n`Capell\\Core\\Support\\StaticSite\\StaticSiteExtensionRegistry::instance()`\n");

    try {
        expect(capellDocsMissingClassReferences($root))->toBe([
            'docs/packages/guide.md:3 Capell\\Core\\Support\\StaticSite\\StaticSiteExtensionRegistry',
        ]);
    } finally {
        (new Filesystem)->remove($root);
    }
});

it('leaves namespaces the monorepo does not own to their own repositories', function (): void {
    $root = docsClassReferenceFixture("`Capell\\HtmlCache\\Support\\StaticSite\\StaticSiteExtensionRegistry` and `Acme\\Missing`.\n");

    try {
        expect(capellDocsMissingClassReferences($root))->toBe([]);
    } finally {
        (new Filesystem)->remove($root);
    }
});

it('finds no invented class in the shipped documentation', function (): void {
    expect(capellDocsMissingClassReferences(dirname(__DIR__, 2)))->toBe([]);
});
