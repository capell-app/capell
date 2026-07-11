<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;

use function Laravel\Prompts\select;

use Symfony\Component\Console\Command\Command as CommandAlias;

final class UninstallExtensionCommand extends Command
{
    use DescribesCommandOptions;

    /** @var string */
    protected $signature = 'capell:extension-uninstall
        {extension? : Installed extension package name. Omit to choose from installed extensions}
        {--all : Uninstall all installed extensions}
        {--delete-data : Delete extension-owned data while uninstalling}
        {--delete-package : Remove the Composer package after uninstalling. This also deletes extension-owned data}
        {--dry-run : Show the uninstall plan without running extension uninstall commands}
    ';

    /** @var string */
    protected $description = 'Uninstall Extension.';

    public function handle(): int
    {
        $this->writeCommandIntro('uninstall Capell extensions', $this->uninstallExtensionIntroDetails());

        try {
            $packages = $this->selectedPackages();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return CommandAlias::FAILURE;
        }

        if ($packages->isEmpty()) {
            $this->warn('No installed extensions available to uninstall.');

            return CommandAlias::SUCCESS;
        }

        $deletePackage = (bool) $this->option('delete-package');
        $deleteData = $deletePackage || (bool) $this->option('delete-data');

        $packages->each(function (PackageData $package) use ($deleteData, $deletePackage): void {
            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Would uninstall %s%s%s',
                    $package->name,
                    $deleteData ? ' and delete extension data' : '',
                    $deletePackage ? ' and remove the Composer package' : '',
                ));

                return;
            }

            $this->line(sprintf('Uninstalling extension: %s', $package->name));

            UninstallPackageAction::run($package, delete: $deletePackage, deleteData: $deleteData);
        });

        return CommandAlias::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function uninstallExtensionIntroDetails(): array
    {
        return $this->enabledOptionDetails([
            'all' => 'all installed extensions',
            'delete-data' => 'extension-owned data deletion',
            'delete-package' => 'Composer package removal',
            'dry-run' => 'a dry run',
        ]);
    }

    /**
     * @return Collection<string, PackageData>
     */
    private function selectedPackages(): Collection
    {
        $extensionName = $this->extensionName();
        $packages = $this->installedExtensions();

        if ($this->option('all')) {
            return resolve(PackageWorkflowPlanner::class)->order($packages)->reverse();
        }

        if ($extensionName === null) {
            $extensionName = $this->promptForExtension($packages);
        }

        if (! $packages->has($extensionName)) {
            throw new InvalidArgumentException(sprintf(
                'Extension [%s] is unknown, core, or not installed.',
                $extensionName,
            ));
        }

        return $packages->only($extensionName);
    }

    /**
     * @return Collection<string, PackageData>
     */
    private function installedExtensions(): Collection
    {
        return CapellCore::getPackages(withoutCore: false)
            ->reject(fn (PackageData $package): bool => $package->name === CapellServiceProvider::$packageName)
            ->reject(fn (PackageData $package): bool => $package->isCore())
            ->filter(fn (PackageData $package): bool => CapellCore::isPackageInstalled($package->name));
    }

    private function extensionName(): ?string
    {
        $extension = $this->argument('extension');

        return is_string($extension) && trim($extension) !== ''
            ? trim($extension)
            : null;
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     */
    private function promptForExtension(Collection $packages): string
    {
        throw_if($packages->isEmpty(), InvalidArgumentException::class, 'No installed extensions available to uninstall.');

        throw_unless($this->input->isInteractive(), InvalidArgumentException::class, 'Pass an extension package name or use --all.');

        return (string) select(
            label: 'Uninstall Extension (installed extensions)',
            options: $packages
                ->mapWithKeys(fn (PackageData $package): array => [$package->name => $package->getShortName()])
                ->all(),
            scroll: 12,
        );
    }
}
