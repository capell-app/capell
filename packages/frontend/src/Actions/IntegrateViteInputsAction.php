<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Support\Assets\FrontendVitePluginRegistry;
use Capell\Frontend\Support\Assets\ViteConfigurationLocator;
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
     * entry points. A configuration without a plugins array is left alone until a package needs one.
     */
    private function integratePlugins(string $contents): string
    {
        if (str_contains($contents, 'capellVitePlugins')) {
            return $contents;
        }

        $pluginsNeeded = $this->pluginRegistry->all() !== [];

        if (preg_match('/\bplugins\s*:\s*\[/', $contents) !== 1 || ! str_contains($contents, self::IMPORT)) {
            // Without a plugins array or with a customised helper import, only a package that needs a
            // plugin makes this a failure; everything else keeps building as it did.
            throw_if(
                $pluginsNeeded,
                RuntimeException::class,
                'The Vite plugins Capell packages need could not be added automatically. Import capellVitePlugins from capell-vite-inputs.js and add capellVitePlugins() to your Vite plugins.',
            );

            return $contents;
        }

        $updated = str_replace(self::IMPORT, self::IMPORT_WITH_PLUGINS, $contents);

        $updated = preg_replace('/(\bplugins\s*:\s*\[)/', '$1capellVitePlugins(), ', $updated, 1, $pluginCount);
        throw_unless(is_string($updated) && $pluginCount === 1, RuntimeException::class, 'The Vite plugins array could not be updated automatically.');

        return $updated;
    }
}
