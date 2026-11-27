<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function boostSkillFiles(string $root): array
{
    return array_merge(
        glob($root . '/packages/core/resources/boost/skills/*/SKILL.md') ?: [],
        glob($root . '/packages/core/resources/boost/skills/*/references/*.md') ?: [],
    );
}

it('ships the site-building and theme-scale skills to applications', function (string $skill): void {
    $path = dirname(__DIR__, 2) . '/packages/core/resources/boost/skills/' . $skill . '/SKILL.md';

    expect($path)->toBeFile();

    preg_match('/^---\nname: (?<name>[a-z-]+)\ndescription: .+?\n---\n/s', (string) file_get_contents($path), $frontmatter);

    expect($frontmatter['name'] ?? null)->toBe($skill);
})->with(['capell-site-building', 'capell-theme-scale']);

it('points every agent at the site-building skill from the Core guidelines', function (): void {
    $guidelines = (string) file_get_contents(dirname(__DIR__, 2) . '/packages/core/resources/boost/guidelines/core.blade.php');

    expect($guidelines)->toContain('capell-site-building');
});

it('keeps Core contributor paths out of the skills applications receive', function (): void {
    foreach (boostSkillFiles(dirname(__DIR__, 2)) as $file) {
        $contents = (string) file_get_contents($file);

        foreach (['packages/{pkg}', 'HasMigrations', 'composer preflight', '`packages/core/', '`packages/admin/', 'in packages/core/', 'in the packages repository'] as $contributorOnly) {
            expect(str_contains($contents, $contributorOnly))
                ->toBeFalse(basename(dirname($file)) . '/' . basename($file) . ' tells an application to use ' . $contributorOnly);
        }
    }
});
