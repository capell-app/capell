<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Requests;

use Capell\Core\Facades\CapellCore;
use Capell\Installer\Support\InstallerOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;

final class StoreInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $options = $this->options();
        $input = $this->all();
        $packageKeys = CapellCore::getPackages(sortByDependencies: true)->keys()->all();
        $downloadablePackageKeys = collect($options->downloadablePackages())
            ->pluck('name')
            ->filter(fn (mixed $packageName): bool => is_string($packageName) && $packageName !== '')
            ->values()
            ->all();
        $userRules = $options->adminUserValidationRules((string) ($input['admin_user_mode'] ?? 'create'));

        return [
            'site_url' => ['required', 'url'],
            'language' => ['required', 'string', Rule::in(array_merge(array_keys($options->languageOptions()), ['__custom']))],
            'custom_language_code' => [
                Rule::requiredIf(($input['language'] ?? null) === '__custom'),
                'nullable',
                'string',
                'regex:/^[a-z]{2,3}$/',
            ],
            'package_selection_mode' => ['nullable', 'string', Rule::in(['core', 'all', 'custom'])],
            'packages' => ['array'],
            'packages.*' => ['string', 'in:' . implode(',', $packageKeys)],
            'theme' => [
                'nullable',
                'string',
                Rule::in(array_keys($options->themeNamesForSelection(
                    (array) ($input['packages'] ?? []),
                    (array) ($input['extra_packages'] ?? []),
                ))),
            ],
            'extra_packages' => ['array'],
            'extra_packages.*' => ['string', 'regex:/^[a-z0-9]([a-z0-9_.-]*[a-z0-9])?\/[a-z0-9]([a-z0-9_.-]*[a-z0-9])?$/', Rule::in($downloadablePackageKeys)],
            'install_developer_tooling' => ['nullable', 'boolean'],
            'configure_boost_developer_tooling' => ['nullable', 'boolean'],
            'admin_user_mode' => ['nullable', 'string', Rule::in(['create', 'existing'])],
            'existing_user_id' => $userRules['existing_user_id'],
            'new_user_name' => $userRules['new_user_name'],
            'new_user_email' => $userRules['new_user_email'],
            'new_user_password' => $userRules['new_user_password'],
            'create_role_users' => ['nullable', 'boolean'],
            'role_user_password' => ['nullable', 'string', 'min:8'],
            'demo_content' => ['nullable', 'boolean'],
            'seed_default_data' => ['nullable', 'boolean'],
            'install_filament_panel' => ['nullable', 'boolean'],
            'install_welcome_route' => ['nullable', 'boolean'],
            'admin_panel_changes_mode' => ['nullable', 'string', Rule::in(['auto', 'manual'])],
            'integrate_admin_panel' => ['nullable', 'boolean'],
            'admin_add_colors' => ['nullable', 'boolean'],
            'admin_add_widgets' => ['nullable', 'boolean'],
            'admin_add_navigation' => ['nullable', 'boolean'],
            'generate_sitemap' => ['nullable', 'boolean'],
            'rebuild_resources' => ['nullable', 'boolean'],
            'fresh_install' => ['nullable', 'boolean'],
            'run_as_job' => ['nullable', 'boolean'],
            'install_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    #[Override]
    public function attributes(): array
    {
        return [
            'existing_user_id' => 'existing user',
            'new_user_name' => 'name',
            'new_user_email' => 'email',
            'new_user_password' => 'password',
        ];
    }

    /** @return array<string, mixed> */
    public function normalisedInput(): array
    {
        $validated = $this->validated();

        if (($validated['language'] ?? null) === '__custom') {
            $validated['language'] = $this->options()
                ->normaliseLanguageCode((string) $validated['custom_language_code']);
        }

        return $validated;
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        $options = $this->options();

        $this->replace($options->withDefaultAdminUserInput(
            $this->all(),
            (string) $this->input('admin_user_mode', 'create'),
        ));
    }

    private function options(): InstallerOptions
    {
        return resolve(InstallerOptions::class);
    }
}
