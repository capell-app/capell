<?php

declare(strict_types=1);

use Capell\Core\Tests\Support\PhpStanFixtureAnalysis;
use Symfony\Component\Filesystem\Filesystem;

it('reports cache reads expected to be numbers but not reads normalised first', function (): void {
    $root = dirname(__DIR__, 5);
    $directory = sys_get_temp_dir() . '/capell-cache-numeric-read-rule-' . bin2hex(random_bytes(6));
    mkdir($directory);

    // Generated outside the repository so Capell's own PHPStan run never sees
    // code that breaks the rule on purpose. Each reported line ends in "// reported".
    $fixture = $directory . '/Consumer.php';
    file_put_contents($fixture, <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace CapellRuleFixture;

        use Illuminate\Contracts\Cache\Repository;
        use Illuminate\Support\Facades\Cache;

        final class TransitionCheck
        {
            public function __construct(private Repository $cache) {}

            /** The shape that withdrew every public store once a minute in production. */
            public function lastCheckBeforeFix(): ?int
            {
                $checkedAt = $this->cache->get('checked-at');

                return is_int($checkedAt) ? $checkedAt : null; // reported
            }

            public function lastCheckAfterFix(): ?int
            {
                $checkedAt = $this->cache->get('checked-at');

                if (is_string($checkedAt) && ctype_digit($checkedAt)) {
                    $checkedAt = (int) $checkedAt;
                }

                return is_int($checkedAt) ? $checkedAt : null;
            }

            public function normalised(): int
            {
                $count = Cache::get('count', 0);

                return is_numeric($count) ? (int) $count : 0;
            }

            public function facadeRead(): bool
            {
                return is_int(Cache::get('count')) || is_float(Cache::get('ratio') ?? 0.5); // reported
            }

            public function namedStore(): int
            {
                $cache = Cache::store('redis');
                $count = $cache->get('count', 0);

                if (! is_int($count)) { // reported
                    return 0;
                }

                return $count; // reported
            }

            public function helperRead(): bool
            {
                return is_int(cache('count')) || is_int(cache()->get('count')); // reported
            }

            public function rememberedCount(): int
            {
                return Cache::remember('count', 30, fn (): int => 3); // reported
            }

            public function arrayStoreCount(): int
            {
                return Cache::store('array')->rememberForever('count', fn (): int => 3);
            }

            public function rememberedLabel(): string
            {
                return Cache::remember('label', 30, fn (): string => 'label');
            }

            public function notACacheRead(mixed $value): bool
            {
                $count = $value;

                return is_int($count) && is_int(config('count'));
            }

            public function readInClosure(): callable
            {
                return fn (): bool => is_int($this->cache->pull('count')); // reported
            }

            public function selfNormalisedWithIsInt(): int
            {
                $count = Cache::get('count');
                $count = is_int($count) ? $count : 0; // reported

                return $count;
            }

            public function arrowReturn(): callable
            {
                return fn (): int => cache('count'); // reported
            }

            public function capturedByUse(): callable
            {
                $count = Cache::get('count');

                return function () use ($count): bool {
                    return is_int($count); // reported
                };
            }

            /** @param  list<mixed>  $values */
            public function shadowedByParameterAndLoop(array $values): bool
            {
                $value = Cache::get('value');
                $ints = array_filter($values, fn (mixed $value): bool => is_int($value));

                foreach ($values as $value) {
                    if (is_int($value)) {
                        return true;
                    }
                }

                return $ints !== [];
            }

            public function notCapturedByClosure(): callable
            {
                $count = Cache::get('count');

                return function (): bool {
                    $count = 3;

                    return is_int($count);
                };
            }
        }
        PHP);
    $config = $directory . '/phpstan.neon';
    file_put_contents($config, "includes:\n    - {$root}/packages/core/extension-phpstan.neon\n\nparameters:\n    level: 0\n    tmpDir: {$directory}/cache\n");

    $expectedLines = [];

    foreach (file($fixture) ?: [] as $index => $line) {
        if (str_contains($line, '// reported')) {
            $expectedLines[] = $index + 1;
        }
    }

    try {
        $analysis = PhpStanFixtureAnalysis::run($root, $config, $fixture, $root . '/vendor/autoload.php');
        $reported = array_values(array_filter($analysis->messagesFor($fixture), static fn (array $message): bool => ($message['identifier'] ?? null) === 'capell.cacheNumericRead'));
        $lines = array_values(array_unique(array_map(static fn (array $message): int => (int) ($message['line'] ?? 0), $reported)));
        sort($lines);

        expect($analysis->generalErrors())->toBe([], $analysis->diagnostic())
            ->and($lines)->toBe($expectedLines, $analysis->diagnostic())
            // Two reads on the facade line and two on the helper line.
            ->and($reported)->toHaveCount(count($expectedLines) + 2, $analysis->diagnostic());
    } finally {
        new Filesystem()->remove($directory);
    }
});
