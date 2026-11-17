<?php

declare(strict_types=1);

use Capell\Core\Tests\Support\PhpStanFixtureAnalysis;
use Symfony\Component\Filesystem\Filesystem;

it('reports Eloquent models in public render data but not hydrated data or unmarked classes', function (): void {
    $root = dirname(__DIR__, 5);
    $directory = sys_get_temp_dir() . '/capell-public-render-data-rule-' . bin2hex(random_bytes(6));
    mkdir($directory);

    // The violating fixture is generated outside the repository so Capell's own
    // PHPStan run and PSR-4 scan never see a class that breaks the rule on purpose.
    $fixture = $directory . '/Consumer.php';
    file_put_contents($fixture, <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace CapellRuleFixture;

        use Capell\Core\Contracts\PublicRenderData;
        use Capell\Core\Models\Site;

        final class ModelCarryingRenderData implements PublicRenderData
        {
            /** @param  array<string, Site>  $sites */
            public function __construct(
                public Site $site,
                public array $sites,
                public string $title,
            ) {}
        }

        final class HydratedRenderData implements PublicRenderData
        {
            /** @param  array<string, string>  $labels */
            public function __construct(
                public string $title,
                public array $labels,
                public ?int $siteId = null,
            ) {}
        }

        final class UnmarkedModelHolder
        {
            public function __construct(public Site $site) {}
        }
        PHP);
    $config = $directory . '/phpstan.neon';
    file_put_contents($config, "includes:\n    - {$root}/packages/core/extension-phpstan.neon\n\nparameters:\n    level: 0\n    tmpDir: {$directory}/cache\n");

    try {
        $analysis = PhpStanFixtureAnalysis::run($root, $config, $fixture, $root . '/vendor/autoload.php');
        $reported = $analysis->messagesWithIdentifier($fixture, 'capell.publicRenderDataModel');
        $text = implode("\n", $reported);

        expect($analysis->generalErrors())->toBe([], $analysis->diagnostic())
            ->and($reported)->toHaveCount(2, $analysis->diagnostic())
            ->and($text)->toContain('ModelCarryingRenderData::$site', 'ModelCarryingRenderData::$sites')
            ->not->toContain('HydratedRenderData')->not->toContain('UnmarkedModelHolder')->not->toContain('::$title');
    } finally {
        new Filesystem()->remove($directory);
    }
});

it('fails with the analyser error when PHPStan cannot run, instead of reading an empty report', function (): void {
    $root = dirname(__DIR__, 5);
    $directory = sys_get_temp_dir() . '/capell-phpstan-analyser-failure-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $fixture = $directory . '/Clean.php';
    file_put_contents($fixture, "<?php\n\ndeclare(strict_types=1);\n\nfinal class CapellCleanFixture {}\n");
    $config = $directory . '/phpstan.neon';
    // The analyser stops before it reports anything, as a crashed run does.
    file_put_contents($config, "includes:\n    - {$directory}/capell-missing-configuration.neon\n");

    try {
        expect(fn (): PhpStanFixtureAnalysis => PhpStanFixtureAnalysis::run($root, $config, $fixture))
            ->toThrow(RuntimeException::class, 'capell-missing-configuration.neon');
    } finally {
        new Filesystem()->remove($directory);
    }
});
