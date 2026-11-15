<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Support\Assets\FrontendVitePluginRegistry;
use Capell\Frontend\Support\Assets\ViteConfigurationLocator;
use Capell\Frontend\Support\Assets\ViteConfigurationScanner;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class IntegrateViteInputsAction
{
    use AsFake;
    use AsObject;

    private const string IMPORT = "import { capellViteInputs } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js';";

    private const string IMPORT_WITH_PLUGINS = "import { capellViteInputs, capellVitePlugins } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js';";

    public function __construct(
        private readonly Filesystem $files,
        private readonly FrontendVitePluginRegistry $pluginRegistry,
    ) {}

    public function handle(): string
    {
        $path = new ViteConfigurationLocator($this->files)->find();

        throw_unless(is_string($path), RuntimeException::class, 'No supported Vite configuration file was found.');

        $contents = $this->files->get($path);
        $updated = $this->integrateInputs($contents);
        $updated = $this->integratePlugins($updated);

        if ($updated !== $contents) {
            $this->files->put($path, $updated);
        }

        return $path;
    }

    private function integrateInputs(string $contents): string
    {
        if (str_contains($contents, 'capellViteInputs') && str_contains($contents, 'capell-vite-inputs.js')) {
            return $contents;
        }

        throw_unless(
            preg_match('/\binput\s*:\s*\[[^\]]*[\'"]resources\/(?:css|js)\//s', $contents) === 1,
            RuntimeException::class,
            'The Vite input array is customised and could not be updated automatically.',
        );

        $updated = self::IMPORT . PHP_EOL . $contents;
        $updated = preg_replace(
            '/(\binput\s*:\s*\[\s*\n)([ \t]*)/',
            '$1$2...capellViteInputs(),' . PHP_EOL . '$2',
            $updated,
            1,
            $multilineCount,
        );
        throw_unless(is_string($updated), RuntimeException::class, 'The Vite configuration could not be updated automatically.');

        if ($multilineCount === 0) {
            $updated = preg_replace(
                '/(\binput\s*:\s*\[)/',
                '$1...capellViteInputs(), ',
                $updated,
                1,
                $inlineCount,
            );

            throw_unless(is_string($updated) && $inlineCount === 1, RuntimeException::class, 'The Vite input array could not be updated automatically.');
        }

        return $updated;
    }

    /**
     * Adds the helper that loads package-registered Vite plugins, such as the Vue plugin for `.vue`
     * entry points. Configurations are only touched when a package needs a plugin, and only the
     * exported configuration's own `plugins` array is edited.
     */
    private function integratePlugins(string $contents): string
    {
        if ($this->pluginRegistry->all() === [] || preg_match('/\bcapellVitePlugins\s*\(/', ViteConfigurationScanner::code($contents)) === 1) {
            return $contents;
        }

        $arrayEnd = ViteConfigurationScanner::topLevelPluginsArrayEnd($contents);

        throw_if(
            $arrayEnd === null || ! str_contains($contents, self::IMPORT),
            RuntimeException::class,
            'The Vite plugins Capell packages need could not be added automatically. Import capellVitePlugins from capell-vite-inputs.js and add capellVitePlugins() to the plugins array of your Vite configuration.',
        );

        $updated = substr_replace($contents, $this->pluginHelperInsertion($contents, $arrayEnd), $arrayEnd, 0);

        return str_replace(self::IMPORT, self::IMPORT_WITH_PLUGINS, $updated);
    }

    /**
     * A plugins array written one entry per line gets the helper on its own line at the
     * entries' indentation; an inline array gets it inline. Neither leaves trailing spaces.
     */
    private function pluginHelperInsertion(string $contents, int $arrayEnd): string
    {
        if (preg_match('/\G[ \t]*\r?\n([ \t]*)\S/', $contents, $nextLine, 0, $arrayEnd) !== 1) {
            return 'capellVitePlugins(), ';
        }

        $lineBreak = str_contains(substr($contents, $arrayEnd, strlen($nextLine[0])), "\r\n") ? "\r\n" : "\n";

        return $lineBreak . $nextLine[1] . 'capellVitePlugins(),';
    }
}
