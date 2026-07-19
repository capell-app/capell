<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Frontend\Support\Tailwind\TailwindAssetsGenerator;
use Illuminate\Console\Command;

class GenerateTailwindAssetsCommand extends Command
{
    protected $signature = 'capell:frontend-tailwind-assets {--report : Print the aggregated assets report instead of writing files} {--output-path= : Project-local absolute path or directory for the generated frontend CSS entrypoint}';

    protected $description = 'Generate the Tailwind CSS directive file for Capell frontend.';

    public function handle(TailwindAssetsGenerator $generator): int
    {
        if ($this->option('report')) {
            $encodedReport = JsonCodec::encodeOrDefault(
                $generator->collect()->toReport(),
                '{}',
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );

            $this->line('Tailwind assets report:');
            $this->line($encodedReport);

            return self::SUCCESS;
        }

        $overridePath = $this->option('output-path');
        $targetPath = is_string($overridePath) && $overridePath !== '' ? $overridePath : null;

        foreach ($generator->generate($targetPath) as $generatedPath) {
            $this->info(sprintf('Generated Tailwind assets at %s', $generatedPath));
        }

        return self::SUCCESS;
    }
}
