<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Data\Install\ThemeInstallOptionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Locale;
use ResourceBundle;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class InstallerOptions
{
    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly ThemePackageCandidates $themes,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function downloadablePackages(): array
    {
        try {
            $packages = [];
            $packageBatches = GetPluginsAction::run('download')
                ->reject(fn (PackageData $package): bool => CapellCore::hasPackage($package->name))
                ->filter(fn (PackageData $package): bool => $this->composerPackageIsAvailable($package->name))
                ->filter(fn (PackageData $package): bool => $package->isVisibleInCatalogue())
                ->reject(fn (PackageData $package): bool => $package->getThemeKey() !== null)
                ->lazy()
                ->chunk(25);

            foreach ($packageBatches as $batch) {
                foreach ($batch as $package) {
                    $packages[] = [
                        'name' => $package->name,
                        'label' => $package->getLabel(),
                        'description' => $package->getDescription(),
                        'requirements' => $package->getRequirements(),
                        'core' => $package->isCore(),
                        'defaultCore' => TrustedCorePackages::isDefaultInstallSelection($package->name),
                        'defaultSelected' => $this->packageIsDefaultSelected($package),
                        'kind' => $package->getKind(),
                        'themeKey' => $package->getThemeKey(),
                        'previewImageUrl' => $package->getPreviewImageUrl(),
                    ];
                }
            }

            unset($packageBatches, $batch);

            return $packages;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    public function configuredDefaultPackageNames(): array
    {
        $packageNames = config('capell-installer.default_packages', []);

        return is_array($packageNames)
            ? collect($packageNames)->filter(fn (mixed $name): bool => is_string($name) && $name !== '')->unique()->values()->all()
            : [];
    }

    public function packageIsDefaultSelected(PackageData $package): bool
    {
        return $package->defaultSelected === true
            || in_array($package->name, $this->configuredDefaultPackageNames(), true);
    }

    /** @return array<string, array{key: string, name: string, description: ?string, packageName: ?string, previewImageUrl: ?string}> */
    public function themeOptions(): array
    {
        $themes = [];
        $themeBatches = collect($this->themes->optionDataForCatalogue())
            ->lazy()
            ->chunk(25);

        foreach ($themeBatches as $batch) {
            foreach ($batch as $option) {
                $themes[$option->key] = [
                    'key' => $option->key,
                    'name' => $option->name,
                    'description' => $option->description,
                    'packageName' => $option->packageName,
                    'previewImageUrl' => $option->previewImageUrl,
                ];
            }
        }

        unset($themeBatches, $batch);

        return $themes;
    }

    /** @param array<int, string> $selected @param array<int, string> $extra @return array<string, string> */
    public function themeNamesForSelection(array $selected, array $extra = []): array
    {
        return $this->themes->optionsForSelection($selected, $extra)
            + collect($this->themes->optionDataForCatalogue())
                ->mapWithKeys(fn (ThemeInstallOptionData $option): array => [$option->key => $option->name])
                ->all();
    }

    /** @return array<string, string> */
    public function languageOptions(): array
    {
        return collect([$this->normaliseLanguageCode((string) config('app.locale', 'en'))])
            ->merge($this->availableLanguageCodes())
            ->map($this->normaliseLanguageCode(...))
            ->unique()
            ->mapWithKeys(fn (string $code): array => [$code => $this->languageName($code)])
            ->all();
    }

    /** @return array<string, string> */
    public function customLanguageSuggestions(): array
    {
        return collect($this->availableLanguageCodes())
            ->mapWithKeys(fn (string $code): array => [$code => $this->languageName($code)])
            ->all();
    }

    public function normaliseLanguageCode(string $code): string
    {
        return Str::of($code)->replace('_', '-')->before('-')->lower()->toString();
    }

    /** @return array{name: string, email: string, password: string} */
    public function defaultAdminUser(): array
    {
        $configured = config('capell-installer.admin_user', []);

        return [
            'name' => $this->stringValue($configured['name'] ?? null),
            'email' => $this->stringValue($configured['email'] ?? null),
            'password' => $this->stringValue($configured['password'] ?? null),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function withDefaultAdminUserInput(array $input, string $mode): array
    {
        if ($mode === 'existing') {
            return $input;
        }

        $defaults = $this->defaultAdminUser();

        foreach (['new_user_name' => 'name', 'new_user_email' => 'email', 'new_user_password' => 'password'] as $inputKey => $defaultKey) {
            if ($this->stringValue($input[$inputKey] ?? null) === '' && $defaults[$defaultKey] !== '') {
                $input[$inputKey] = $defaults[$defaultKey];
            }
        }

        return $input;
    }

    /** @return array<string, array<int, mixed>> */
    public function adminUserValidationRules(string $mode): array
    {
        $creating = $mode !== 'existing';
        $tableExists = $this->usersTableExists();
        $existing = ['nullable'];

        if (! $creating) {
            $existing = ['required', 'integer'];
            $existing[] = $tableExists
                ? Rule::exists($this->userTable(), 'id')
                : function (string $attribute, mixed $value, callable $fail): void {
                    $fail('No existing users are available yet. Create a new administrator account to continue.');
                };
        }

        $email = [$creating ? 'required' : 'nullable', 'email', 'max:255'];
        if ($creating && $tableExists) {
            $email[] = Rule::unique($this->userTable(), 'email');
        }

        return [
            'existing_user_id' => $existing,
            'new_user_name' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'new_user_email' => $email,
            'new_user_password' => [$creating ? 'required' : 'nullable', 'string', 'min:8'],
        ];
    }

    public function usersTableExists(): bool
    {
        try {
            return Schema::hasTable($this->userTable());
        } catch (Throwable) {
            return false;
        }
    }

    /** @return class-string<Model> */
    public function userModel(): string
    {
        $model = config('auth.providers.users.model');

        throw_if(! is_string($model) || ! is_subclass_of($model, Model::class), RuntimeException::class, 'The configured user provider model must be an Eloquent model.');

        return $model;
    }

    private function userTable(): string
    {
        $model = $this->userModel();

        return (new $model)->getTable();
    }

    /** @return array<int, string> */
    private function availableLanguageCodes(): array
    {
        $bundle = ResourceBundle::create('en', 'ICUDATA-lang');
        $languages = $bundle instanceof ResourceBundle ? $bundle->get('Languages') : null;

        if (! $languages instanceof ResourceBundle) {
            return ['en', 'fr', 'de', 'es', 'nl'];
        }

        return collect(iterator_to_array($languages))->keys()
            ->filter(fn (string $code): bool => preg_match('/^[a-z]{2,3}$/', $code) === 1)
            ->sortBy(fn (string $code): string => $this->languageName($code))->values()->all();
    }

    private function languageName(string $code): string
    {
        $name = Locale::getDisplayLanguage($code, 'en');

        return $name !== false ? Str::headline($name) : Str::upper($code);
    }

    private function composerPackageIsAvailable(string $packageName): bool
    {
        $key = 'capell.installer.package_installable.' . hash('sha256', $packageName);
        $trusted = TrustedCorePackages::contains($packageName);
        $resolver = function () use ($packageName): bool {
            $process = new Process([
                (string) config('capell-installer.composer_binary', 'composer'), 'require', $packageName . ':*',
                '--dry-run', '--no-audit', '--no-interaction', '--no-progress', '--no-scripts', '--with-all-dependencies',
            ], base_path(), ComposerProcessEnvironment::forInstall($_SERVER));
            $process->setTimeout(120);
            $process->run();

            return $process->isSuccessful();
        };

        if (! $this->sessions->cacheStoreIsUsable()) {
            return $trusted && $resolver();
        }

        try {
            if (! $trusted && ! Cache::has($key)) {
                return false;
            }

            return Cache::remember($key, now()->addHour(), $resolver);
        } catch (Throwable) {
            return $trusted && $resolver();
        }
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
