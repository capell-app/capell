<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

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
        $process = new Process([PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--configuration=' . $config, '--autoload-file=' . $root . '/vendor/autoload.php', $fixture], $root);
        $process->run();

        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $messages = $result['files'][$fixture]['messages'] ?? $result['error_details'][$fixture] ?? [];
        $reported = array_column(array_filter($messages, static fn (array $message): bool => ($message['identifier'] ?? null) === 'capell.publicRenderDataModel'), 'message');
        $text = implode("\n", $reported);

        expect($reported)->toHaveCount(2)
            ->and($text)->toContain('ModelCarryingRenderData::$site', 'ModelCarryingRenderData::$sites')
            ->not->toContain('HydratedRenderData')->not->toContain('UnmarkedModelHolder')->not->toContain('::$title');
    } finally {
        new Filesystem()->remove($directory);
    }
});
