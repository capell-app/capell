<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Frontend\Data\Assets\FrontendDependencyPlanData;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
use Capell\Frontend\Support\Assets\ViteConfigurationLocator;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use stdClass;

final class PrepareFrontendInstallationAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Filesystem $files) {}

    public function handle(?FrontendDependencyPlanData $plan = null, ?ProgressReporter $reporter = null): void
    {
        throw_unless($this->viteInputHelperIsIntegrated(), FrontendResourcePlanException::class, 'Capell Vite inputs are not integrated. Add the Capell Vite input helper before preparing frontend assets.');
        $plan ??= ResolveFrontendDependencyPlanAction::run();
        $path = base_path('package.json');
        $package = json_decode($this->files->get($path), flags: JSON_THROW_ON_ERROR);
        throw_unless($package instanceof stdClass, FrontendResourcePlanException::class, 'The application package.json must contain an object.');
        foreach (['dependencies', 'devDependencies'] as $bucket) {
            throw_if(isset($package->{$bucket}) && ! $package->{$bucket} instanceof stdClass, FrontendResourcePlanException::class, 'Application dependency groups must contain objects.');
        }

        foreach ($plan->requirements as $name => $requirement) {
            // Preserve the application's chosen constraint and dependency bucket.
            if (isset($package->dependencies->{$name})) {
                continue;
            }

            if (isset($package->devDependencies->{$name})) {
                continue;
            }

            $bucket = $requirement['type'] === 'runtime' ? 'dependencies' : 'devDependencies';
            $package->{$bucket} ??= new stdClass;
            $package->{$bucket}->{$name} = $requirement['version'];
            $dependencies = get_object_vars($package->{$bucket});
            ksort($dependencies);
            $package->{$bucket} = (object) $dependencies;
        }

        $written = $this->files->put($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        throw_if($written === false, FrontendResourcePlanException::class, 'Unable to write application package.json. Check install-time file permissions.');
        WriteViteInputManifestAction::run(GenerateTailwindAssetsAction::run());
        $reporter?->report(sprintf('Frontend assets prepared. Before opening Admin or the public page, run %s install and %s run build.', $plan->manager->value, $plan->manager->value));
    }

    public function viteInputHelperIsIntegrated(): bool
    {
        $path = new ViteConfigurationLocator($this->files)->find();
        if ($path === null) {
            return false;
        }

        $config = $this->files->get($path);

        return str_contains($config, 'capellViteInputs') && str_contains($config, 'capell-vite-inputs.js');
    }
}
