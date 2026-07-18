<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Install\ConsoleProgressReporter;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;

use function Laravel\Prompts\select;

use Symfony\Component\Console\Command\Command as CommandAlias;

class InstallExtensionCommand extends Command
{
    use DescribesCommandOptions;

    /** @var string */
    protected $signature = 'capell:extension-install
        {extension? : Extension package name. Omit to choose from extensions that are not already installed}
        {--all : Install all extensions that are not already installed}
        {--include-installed : Re-run install workflow for an extension already marked installed}
        {--dry-run : Show the install plan without running extension install commands}
        {--url= : Value forwarded to extensions that declare the url install param}
        {--languages=* : Values forwarded to extensions that declare the languages install param}
        {--sites=* : Values forwarded to extensions that declare the sites install param}
        {--user= : Value forwarded to extensions that declare the user install param}
        {--assets=* : Values forwarded to extensions that declare the assets install param}
        {--param=* : Dynamic install param as name=value, --name=value, package:name=value, or package:--name=value. Repeatable}
    ';

    /** @var string */
    protected $description = 'Install Extension.';

    public function handle(): int
    {
        $this->writeCommandIntro('install Capell extensions', $this->installExtensionIntroDetails());

        try {
            $packages = $this->selectedPackages();
            $dynamicParams = $this->dynamicParams();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return CommandAlias::FAILURE;
        }

        if ($packages->isEmpty()) {
            $this->warn('No extensions available to install.');

            return CommandAlias::SUCCESS;
        }

        $reporter = new ConsoleProgressReporter($this);

        $packages->each(function (PackageData $package) use ($dynamicParams, $reporter): void {
            $arguments = $this->argumentsForPackage($package, $dynamicParams);

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Would install %s%s',
                    $package->name,
                    $arguments === [] ? '' : ' with ' . JsonCodec::encode($arguments),
                ));

                return;
            }

            $this->line(sprintf('Installing extension: %s', $package->name));

            InstallPackageAction::run($package, $arguments, $reporter);
        });

        return CommandAlias::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function installExtensionIntroDetails(): array
    {
        $details = $this->enabledOptionDetails([
            'all' => 'all available extensions',
            'include-installed' => 'installed extensions included',
            'dry-run' => 'a dry run',
        ]);

        if ($this->option('url')) {
            $details[] = 'a forwarded URL';
        }

        if ($this->option('languages') !== []) {
            $details[] = 'forwarded languages';
        }

        if ($this->option('sites') !== []) {
            $details[] = 'forwarded sites';
        }

        if ($this->option('user')) {
            $details[] = 'a forwarded user';
        }

        if ($this->option('assets') !== []) {
            $details[] = 'forwarded assets';
        }

        if ($this->option('param') !== []) {
            $details[] = 'custom forwarded parameters';
        }

        return $details;
    }

    /**
     * @return Collection<string, PackageData>
     */
    private function selectedPackages(): Collection
    {
        $extensionName = $this->extensionName();
        $includeInstalled = $this->option('include-installed');

        $packages = $this->availableExtensions($includeInstalled);

        if ($this->option('all')) {
            return resolve(PackageWorkflowPlanner::class)->order($packages);
        }

        if ($extensionName === null) {
            $extensionName = $this->promptForExtension($packages);
        }

        if (! $packages->has($extensionName)) {
            throw new InvalidArgumentException(sprintf(
                'Extension [%s] is unknown, core, or already installed.',
                $extensionName,
            ));
        }

        return resolve(PackageWorkflowPlanner::class)->expandAndOrder(
            $packages,
            [$extensionName],
        );
    }

    /**
     * @return Collection<string, PackageData>
     */
    private function availableExtensions(bool $includeInstalled): Collection
    {
        return CapellCore::getPackages(withoutCore: false)
            ->reject(fn (PackageData $package): bool => $package->name === CapellServiceProvider::$packageName)
            ->reject(fn (PackageData $package): bool => $package->isCore())
            ->unless(
                $includeInstalled,
                fn (Collection $packages): Collection => $packages->reject(fn (PackageData $package): bool => CapellCore::isPackageInstalled($package->name)),
            );
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
        throw_if($packages->isEmpty(), InvalidArgumentException::class, 'No extensions available to install.');

        throw_unless($this->input->isInteractive(), InvalidArgumentException::class, 'Pass an extension package name or use --all.');

        return (string) select(
            label: 'Install Extension (extensions are not already installed)',
            options: $packages
                ->mapWithKeys(fn (PackageData $package): array => [$package->name => $package->getShortName()])
                ->all(),
            scroll: 12,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function dynamicParams(): array
    {
        $params = ['*' => []];

        foreach ($this->option('param') as $rawParam) {
            if (! is_string($rawParam)) {
                continue;
            }

            if (trim($rawParam) === '') {
                continue;
            }

            [$target, $name, $value] = $this->parseDynamicParam($rawParam);

            $params[$target][$name] = $value;
        }

        return $params;
    }

    /**
     * @return array{0: string, 1: string, 2: mixed}
     */
    private function parseDynamicParam(string $rawParam): array
    {
        $target = '*';
        $nameAndValue = trim($rawParam);

        if (str_contains($nameAndValue, ':')) {
            [$possibleTarget, $possibleNameAndValue] = explode(':', $nameAndValue, 2);

            if ($possibleTarget !== '' && $possibleNameAndValue !== '') {
                $target = $possibleTarget;
                $nameAndValue = $possibleNameAndValue;
            }
        }

        if (str_contains($nameAndValue, '=')) {
            [$name, $value] = explode('=', $nameAndValue, 2);
        } else {
            $name = $nameAndValue;
            $value = true;
        }

        $name = ltrim(trim($name), '-');
        if ($name === '') {
            throw new InvalidArgumentException(sprintf('Invalid dynamic extension install param [%s].', $rawParam));
        }

        return [$target, $name, $this->normalizeDynamicValue($value)];
    }

    private function normalizeDynamicValue(mixed $value): mixed
    {
        if (! is_string($value) || ! str_contains($value, ',')) {
            return $value;
        }

        return collect(explode(',', $value))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $dynamicParams
     * @return array<string, mixed>
     */
    private function argumentsForPackage(PackageData $package, array $dynamicParams): array
    {
        $arguments = [];

        foreach ($package->getInstallParams() as $param) {
            if ($param === '') {
                continue;
            }

            $value = $this->valueForPackageParam($package, $param, $dynamicParams);
            if ($value === null) {
                continue;
            }

            if ($value === []) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $arguments['--' . $param] = $value;
        }

        return $arguments;
    }

    /**
     * @param  array<string, array<string, mixed>>  $dynamicParams
     */
    private function valueForPackageParam(PackageData $package, string $param, array $dynamicParams): mixed
    {
        $packageTargets = [
            $package->name,
            str($package->name)->afterLast('/')->toString(),
        ];

        foreach ($packageTargets as $target) {
            if (array_key_exists($param, $dynamicParams[$target] ?? [])) {
                return $dynamicParams[$target][$param];
            }
        }

        if (array_key_exists($param, $dynamicParams['*'] ?? [])) {
            return $dynamicParams['*'][$param];
        }

        return $this->commonOptionValue($param);
    }

    private function commonOptionValue(string $param): mixed
    {
        return match ($param) {
            'url', 'user' => $this->option($param) !== '' ? $this->option($param) : null,
            'languages', 'sites', 'assets' => $this->option($param) !== [] ? $this->option($param) : null,
            default => null,
        };
    }
}
