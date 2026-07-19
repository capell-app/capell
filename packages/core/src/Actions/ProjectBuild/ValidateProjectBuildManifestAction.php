<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestConstraints;
use Closure;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static ProjectBuildManifestData run(array<string, mixed>|ProjectBuildManifestData $manifest) */
final class ValidateProjectBuildManifestAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed>|ProjectBuildManifestData $manifest */
    public function handle(array|ProjectBuildManifestData $manifest): ProjectBuildManifestData
    {
        $payload = $manifest instanceof ProjectBuildManifestData ? $manifest->toArray() : $manifest;
        $rootKeys = ['schemaVersion', 'buildId', 'createdAt', 'siteSpec', 'artifacts', 'packages', 'sites', 'routes', 'compatibility', 'signature'];
        if (array_is_list($payload) || array_diff(array_keys($payload), $rootKeys) !== []) {
            throw ValidationException::withMessages(['manifest' => 'The project build manifest contains unsupported root properties.']);
        }

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
        $artifactKeyRules = ['required', 'string', $this->regex(ProjectBuildManifestConstraints::ARTIFACT_KEY_PATTERN)];
        $artifactTypeRules = ['required', 'string', $this->regex(ProjectBuildManifestConstraints::ARTIFACT_TYPE_PATTERN)];
        $artifactPathRules = [
            'required',
            'string',
            'max:' . ProjectBuildManifestConstraints::MAX_ARTIFACT_PATH_LENGTH,
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || preg_match('~' . ProjectBuildManifestConstraints::ARTIFACT_PATH_PATTERN . '~D', $value) !== 1) {
                    $fail("The {$attribute} field must be a safe relative POSIX path.");
                }
            },
        ];

        return [
            'schemaVersion' => ['required', 'integer', 'in:' . ProjectBuildManifestConstraints::CURRENT_SCHEMA_VERSION],
            'buildId' => ['required', 'uuid'],
            'createdAt' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || preg_match('~' . ProjectBuildManifestConstraints::DATE_TIME_PATTERN . '~D', $value) !== 1) {
                        $fail("The {$attribute} field must be an RFC 3339 date-time.");

                        return;
                    }

                    $hasFraction = str_contains($value, '.');
                    $usesZulu = str_ends_with($value, 'Z');
                    $format = match (true) {
                        $hasFraction && $usesZulu => '!Y-m-d\TH:i:s.u\Z',
                        $hasFraction => '!Y-m-d\TH:i:s.uP',
                        $usesZulu => '!Y-m-d\TH:i:s\Z',
                        default => '!Y-m-d\TH:i:sP',
                    };

                    try {
                        $date = DateTimeImmutable::createFromFormat($format, $value);
                        $errors = DateTimeImmutable::getLastErrors();
                        if (! $date instanceof DateTimeImmutable || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                            $fail("The {$attribute} field must be an RFC 3339 date-time.");
                        }
                    } catch (Exception) {
                        $fail("The {$attribute} field must be an RFC 3339 date-time.");
                    }
                },
            ],
            'siteSpec' => ['required', 'array:schemaVersion,key,type,path,digest,sizeBytes,mediaType'],
            'siteSpec.schemaVersion' => ['required', 'integer', 'in:' . ProjectBuildManifestConstraints::CURRENT_SITE_SPEC_SCHEMA_VERSION],
            'siteSpec.key' => $artifactKeyRules,
            'siteSpec.type' => ['required', 'in:site-spec'],
            'siteSpec.path' => $artifactPathRules,
            'siteSpec.digest' => ['required', $this->regex(ProjectBuildManifestConstraints::DIGEST_PATTERN)],
            'siteSpec.sizeBytes' => ['required', 'integer', 'min:1', 'max:' . ProjectBuildManifestConstraints::MAX_ARTIFACT_SIZE_BYTES],
            'siteSpec.mediaType' => ['required', 'string', $this->regex(ProjectBuildManifestConstraints::MEDIA_TYPE_PATTERN)],
            'artifacts' => ['present', 'array', 'list', 'max:' . ProjectBuildManifestConstraints::MAX_ARTIFACTS],
            'artifacts.*' => $artifactRules,
            'artifacts.*.key' => $artifactKeyRules,
            'artifacts.*.type' => $artifactTypeRules,
            'artifacts.*.path' => $artifactPathRules,
            'artifacts.*.digest' => ['required', $this->regex(ProjectBuildManifestConstraints::DIGEST_PATTERN)],
            'artifacts.*.sizeBytes' => ['required', 'integer', 'min:1', 'max:' . ProjectBuildManifestConstraints::MAX_ARTIFACT_SIZE_BYTES],
            'artifacts.*.mediaType' => ['required', 'string', $this->regex(ProjectBuildManifestConstraints::MEDIA_TYPE_PATTERN)],
            'packages' => ['present', 'array', 'list', 'max:' . ProjectBuildManifestConstraints::MAX_PACKAGES],
            'packages.*' => ['required', 'array:name,version,releaseIdentity,installOrder'],
            'packages.*.name' => ['required', $this->regex(ProjectBuildManifestConstraints::PACKAGE_NAME_PATTERN)],
            'packages.*.version' => ['required', $this->regex(ProjectBuildManifestConstraints::PACKAGE_VERSION_PATTERN)],
            'packages.*.releaseIdentity' => ['required', $this->regex(ProjectBuildManifestConstraints::RELEASE_IDENTITY_PATTERN)],
            'packages.*.installOrder' => ['required', 'integer', 'min:0'],
            'sites' => ['required', 'array', 'list', 'min:1', 'max:' . ProjectBuildManifestConstraints::MAX_SITES],
            'sites.*' => ['required', 'array:key,defaultLocale,locales'],
            'sites.*.key' => ['required', $this->regex(ProjectBuildManifestConstraints::SITE_KEY_PATTERN)],
            'sites.*.defaultLocale' => ['required', $this->regex(ProjectBuildManifestConstraints::LOCALE_PATTERN)],
            'sites.*.locales' => ['required', 'array', 'list', 'min:1', 'max:' . ProjectBuildManifestConstraints::MAX_LOCALES_PER_SITE],
            'sites.*.locales.*' => ['required', $this->regex(ProjectBuildManifestConstraints::LOCALE_PATTERN)],
            'routes' => ['required', 'array', 'list', 'min:1', 'max:' . ProjectBuildManifestConstraints::MAX_ROUTES],
            'routes.*' => ['required', 'array:siteKey,locale,path'],
            'routes.*.siteKey' => ['required', 'string'],
            'routes.*.locale' => ['required', 'string'],
            'routes.*.path' => ['required', 'string', 'max:' . ProjectBuildManifestConstraints::MAX_ROUTE_PATH_LENGTH, $this->regex(ProjectBuildManifestConstraints::ROUTE_PATH_PATTERN)],
            'compatibility' => ['required', 'array:capell,php,platforms'],
            'compatibility.capell' => ['required', 'string', 'max:' . ProjectBuildManifestConstraints::MAX_COMPATIBILITY_LENGTH],
            'compatibility.php' => ['required', 'string', 'max:' . ProjectBuildManifestConstraints::MAX_COMPATIBILITY_LENGTH],
            'compatibility.platforms' => ['required', 'array', 'list', 'min:1'],
            'compatibility.platforms.*' => ['required', $this->regex(ProjectBuildManifestConstraints::PLATFORM_PATTERN), 'distinct:strict'],
            'signature' => ['required', 'array:algorithm,keyId,value'],
            'signature.algorithm' => ['required', 'in:ed25519'],
            'signature.keyId' => ['required', $this->regex(ProjectBuildManifestConstraints::KEY_ID_PATTERN)],
            'signature.value' => [
                'required',
                'string',
                $this->regex(ProjectBuildManifestConstraints::SIGNATURE_PATTERN),
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $signature = is_string($value) ? base64_decode($value, true) : false;

                    if (! is_string($signature) || strlen($signature) !== ProjectBuildManifestConstraints::ED25519_SIGNATURE_BYTES) {
                        $fail("The {$attribute} field must be a base64-encoded Ed25519 signature.");
                    }
                },
            ],
        ];
    }

    private function regex(string $pattern): string
    {
        return 'regex:#' . $pattern . '#D';
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
