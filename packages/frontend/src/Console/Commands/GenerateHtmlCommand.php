<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Frontend\Actions\GenerateStaticPageArtifactsAction;
use Illuminate\Console\Command;

final class GenerateHtmlCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:generate-html {--site= : Limit generation to a site id} {--url=* : Limit generation to one or more public URLs}';

    protected $description = 'Generate static HTML artifacts and metadata for published Capell frontend pages';

    public function handle(): int
    {
        $this->writeCommandIntro('generate static Capell HTML', $this->enabledOptionDetails([
            'site' => 'a selected site',
            'url' => 'selected URLs',
        ]));

        $staleReleaseMessage = $this->staleReleaseMessage();

        if ($staleReleaseMessage !== null) {
            $this->error($staleReleaseMessage);

            return self::FAILURE;
        }

        $siteId = $this->option('site');
        $manifest = GenerateStaticPageArtifactsAction::run(
            siteId: is_numeric($siteId) ? (int) $siteId : null,
            urls: array_values(array_filter((array) $this->option('url'), is_string(...))),
        );

        $this->info(sprintf('Generated %d static page artifact(s).', count($manifest['artifacts'] ?? [])));

        return self::SUCCESS;
    }

    private function staleReleaseMessage(): ?string
    {
        $basePath = realpath(base_path());

        if (! is_string($basePath)) {
            return null;
        }

        $currentPath = realpath(dirname($basePath, 2) . '/current');

        if (! is_string($currentPath) || $basePath === $currentPath) {
            return null;
        }

        return sprintf(
            'Refusing to generate static artifacts from stale release [%s]. Run: cd -P %s',
            $basePath,
            $currentPath,
        );
    }
}
