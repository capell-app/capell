<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static ProjectBuildManifestData run(array<string, mixed>|ProjectBuildManifestData $manifest) */
final class ValidateProjectBuildManifestAction
{
    use AsObject;

    private const int ED25519_SIGNATURE_BYTES = 64;

    /** @param array<string, mixed>|ProjectBuildManifestData $manifest */
    public function handle(array|ProjectBuildManifestData $manifest): ProjectBuildManifestData
    {
        $payload = $manifest instanceof ProjectBuildManifestData ? $manifest->toArray() : $manifest;
        $validator = Validator::make($payload, $this->rules());
        $validator->after(function (LaravelValidator $validator) use ($payload): void {
            $this->validateRelationships($validator, $payload);
        });
        $validator->validate();

        return ProjectBuildManifestData::from($payload);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        $artifactRules = ['required', 'array:key,type,path,digest,sizeBytes,mediaType'];
        $artifactKeyRules = ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,99}$/'];
        $artifactTypeRules = ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,63}$/'];
        $artifactPathRules = [
            'required',
            'string',
            'max:255',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)
                    || str_starts_with($value, '/')
                    || str_contains($value, '\\')
                    || in_array('..', explode('/', $value), true)
                    || in_array('', explode('/', $value), true)) {
                    $fail("The {$attribute} field must be a safe relative POSIX path.");
                }
            },
        ];

        return [
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'buildId' => ['required', 'uuid'],
            'createdAt' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'siteSpec' => $artifactRules,
            'siteSpec.key' => $artifactKeyRules,
            'siteSpec.type' => ['required', 'in:site-spec'],
            'siteSpec.path' => $artifactPathRules,
            'siteSpec.digest' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'siteSpec.sizeBytes' => ['required', 'integer', 'min:1', 'max:2147483648'],
            'siteSpec.mediaType' => ['required', 'string', 'regex:/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i'],
            'artifacts' => ['present', 'array', 'max:1000'],
            'artifacts.*' => $artifactRules,
            'artifacts.*.key' => $artifactKeyRules,
            'artifacts.*.type' => $artifactTypeRules,
            'artifacts.*.path' => $artifactPathRules,
            'artifacts.*.digest' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'artifacts.*.sizeBytes' => ['required', 'integer', 'min:1', 'max:2147483648'],
            'artifacts.*.mediaType' => ['required', 'string', 'regex:/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i'],
            'packages' => ['present', 'array', 'max:250'],
            'packages.*' => ['required', 'array:name,version,releaseIdentity,installOrder'],
            'packages.*.name' => ['required', 'regex:/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/'],
            'packages.*.version' => ['required', 'regex:/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/'],
            'packages.*.releaseIdentity' => ['required', 'regex:/^[a-f0-9]{40}$/'],
            'packages.*.installOrder' => ['required', 'integer', 'min:0'],
            'sites' => ['required', 'array', 'min:1', 'max:100'],
            'sites.*' => ['required', 'array:key,defaultLocale,locales'],
            'sites.*.key' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,63}$/'],
            'sites.*.defaultLocale' => ['required', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
            'sites.*.locales' => ['required', 'array', 'min:1', 'max:100'],
            'sites.*.locales.*' => ['required', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
            'routes' => ['required', 'array', 'min:1', 'max:10000'],
            'routes.*' => ['required', 'array:siteKey,locale,path'],
            'routes.*.siteKey' => ['required', 'string'],
            'routes.*.locale' => ['required', 'string'],
            'routes.*.path' => ['required', 'string', 'max:2048', 'regex:/^\/(?:[A-Za-z0-9._~!$&\'()*+,;=:@%-]+\/?)*$/'],
            'compatibility' => ['required', 'array:capell,php,platforms'],
            'compatibility.capell' => ['required', 'string', 'max:100'],
            'compatibility.php' => ['required', 'string', 'max:100'],
            'compatibility.platforms' => ['required', 'array', 'min:1'],
            'compatibility.platforms.*' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,63}$/', 'distinct:strict'],
            'signature' => ['required', 'array:algorithm,keyId,value'],
            'signature.algorithm' => ['required', 'in:ed25519'],
            'signature.keyId' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/'],
            'signature.value' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $signature = is_string($value) ? base64_decode($value, true) : false;

                    if (! is_string($signature) || strlen($signature) !== self::ED25519_SIGNATURE_BYTES) {
                        $fail("The {$attribute} field must be a base64-encoded Ed25519 signature.");
                    }
                },
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validateRelationships(LaravelValidator $validator, array $payload): void
    {
        $siteSpec = is_array($payload['siteSpec'] ?? null) ? $payload['siteSpec'] : [];
        $artifacts = is_array($payload['artifacts'] ?? null) ? $payload['artifacts'] : [];
        $this->rejectDuplicates($validator, 'artifacts', [$siteSpec, ...$artifacts], 'key');
        $this->rejectDuplicates($validator, 'artifacts', [$siteSpec, ...$artifacts], 'path');

        $packages = is_array($payload['packages'] ?? null) ? $payload['packages'] : [];
        $this->rejectDuplicates($validator, 'packages', $packages, 'name');
        $this->rejectDuplicates($validator, 'packages', $packages, 'installOrder');

        $sites = is_array($payload['sites'] ?? null) ? $payload['sites'] : [];
        $this->rejectDuplicates($validator, 'sites', $sites, 'key');
        $sitesByKey = collect($sites)
            ->filter(static fn (mixed $site): bool => is_array($site))
            ->keyBy('key');
        foreach ($sites as $index => $site) {
            if (! is_array($site)) {
                continue;
            }

            $locales = is_array($site['locales'] ?? null) ? $site['locales'] : [];
            if (count($locales) !== count(array_unique($locales, SORT_REGULAR))) {
                $validator->errors()->add("sites.{$index}.locales", 'Site locales must be unique.');
            }

            if (is_string($site['defaultLocale'] ?? null) && ! in_array($site['defaultLocale'], $locales, true)) {
                $validator->errors()->add("sites.{$index}.defaultLocale", 'The default locale must be included in the site locales.');
            }
        }

        $routes = is_array($payload['routes'] ?? null) ? $payload['routes'] : [];
        $routeIdentities = [];
        foreach ($routes as $index => $route) {
            if (! is_array($route)) {
                continue;
            }

            $siteKey = $route['siteKey'] ?? null;
            $locale = $route['locale'] ?? null;
            $path = $route['path'] ?? null;
            $site = is_string($siteKey) ? $sitesByKey->get($siteKey) : null;
            if (! is_array($site)) {
                $validator->errors()->add("routes.{$index}.siteKey", 'The route references an unknown site.');
            } elseif (! is_string($locale) || ! in_array($locale, $site['locales'] ?? [], true)) {
                $validator->errors()->add("routes.{$index}.locale", 'The route locale is not enabled for its site.');
            }

            $identity = implode('|', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', [$siteKey, $locale, $path]));
            if (isset($routeIdentities[$identity])) {
                $validator->errors()->add("routes.{$index}", 'Route identities must be unique.');
            }
            $routeIdentities[$identity] = true;
        }
    }

    /** @param array<int, mixed> $items */
    private function rejectDuplicates(LaravelValidator $validator, string $field, array $items, string $key): void
    {
        $seen = [];
        foreach ($items as $index => $item) {
            if (! is_array($item) || ! is_scalar($item[$key] ?? null)) {
                continue;
            }

            $value = (string) $item[$key];
            if (isset($seen[$value])) {
                $validator->errors()->add("{$field}.{$index}.{$key}", ucfirst($key) . ' values must be unique.');
            }
            $seen[$value] = true;
        }
    }
}
