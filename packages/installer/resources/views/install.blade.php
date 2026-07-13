@extends('capell-installer::layouts.installer')

@section('title', __('capell-installer::installer.page_title'))
@section('bodyClass', 'installer-screen-install')

@section('content')
    <main
        class="panel"
        role="main"
    >
        <header class="panel-header">
            <h1 id="panel-heading">
                {{ __('capell-installer::installer.page_title') }}
            </h1>
            <div
                class="brand-logo"
                aria-label="Capell"
                role="img"
            >
                @if (view()->exists('capell-installer::img.logo'))
                    @include('capell-installer::img.logo')
                @elseif (view()->exists('capell-admin::img.logo'))
                    @include('capell-admin::img.logo')
                @else
                    <span>Capell</span>
                @endif
            </div>
            @unless (($capellAlreadyInstalled ?? false) && ! ($canReinstall ?? false))
                <p id="panel-subheading">
                    {{ __('capell-installer::installer.subheading') }}
                </p>
            @endunless
        </header>

        <div
            class="errors"
            id="errors"
            role="alert"
            hidden
        >
            <span
                class="errors-icon"
                aria-hidden="true"
            >
                !
            </span>
            <div class="errors-content">
                <strong>
                    {{ __('capell-installer::installer.fix_errors') }}
                </strong>
                <ul id="errors-list"></ul>
            </div>
        </div>

        @if ($installId !== null)
            <div class="running-banner">
                {{ __('capell-installer::installer.install_in_progress') }}
                <a
                    href="{{ route('capell-installer.progress', ['installId' => $installId]) }}"
                >
                    {{ __('capell-installer::installer.view_progress') }}
                </a>
                &mdash;
                <form
                    method="POST"
                    action="{{ $cancelUrl }}"
                    style="display: inline"
                    onsubmit="
                        return confirm(
                            @js(__('capell-installer::installer.cancel_confirm')),
                        )
                    "
                >
                    @csrf
                    <button
                        type="submit"
                        style="
                            background: none;
                            border: none;
                            padding: 0;
                            color: inherit;
                            text-decoration: underline;
                            cursor: pointer;
                            font: inherit;
                        "
                    >
                        {{ __('capell-installer::installer.cancel_install') }}
                    </button>
                </form>
            </div>
        @endif

        @if (($capellAlreadyInstalled ?? false) && ! ($canReinstall ?? false))
            <div
                class="panel-body completion-panel"
                id="already-installed-panel"
            >
                <section class="section preflight-panel">
                    <div class="preflight-header">
                        <h2 class="section-title">
                            {{ __('capell-installer::installer.already_installed_heading') }}
                        </h2>
                        <span class="preflight-status pass">
                            {{ __('capell-installer::installer.status_complete') }}
                        </span>
                    </div>
                    <p class="section-help">
                        {{ __('capell-installer::installer.already_installed_message') }}
                    </p>
                </section>
            </div>

            @if ($canRemoveInstaller ?? false)
                <footer class="panel-footer">
                    <form
                        method="POST"
                        action="{{ route('capell-installer.destroy') }}"
                        onsubmit="
                            return confirm(
                                @js(__('capell-installer::installer.remove_installer_confirm')),
                            )
                        "
                    >
                        @csrf
                        <button
                            class="button primary"
                            type="submit"
                        >
                            {{ __('capell-installer::installer.remove_installer') }}
                        </button>
                    </form>
                </footer>
            @endif
        @else
            <div
                class="form-view"
                id="form-view"
            >
                @php
                    $preflightGroups = $preflightReport['groups'] ?? [
                        'blocking' => $preflightReport['checks'] ?? [],
                        'advisory' => [],
                    ];
                    $preflightChecks = collect($preflightGroups)->flatten(1);
                    $preflightPassed = $preflightChecks
                        ->filter(fn (array $check): bool => ($check['status'] ?? 'warning') === 'pass')
                        ->count();
                    $preflightStatus = $preflightReport['status'] ?? 'warning';
                    $availablePackageCount = count($corePackages) + count($installedPackages) + count($downloadablePackages);
                    $visibleCorePackages = collect($corePackages)
                        ->filter(fn (array $package): bool => (bool) ($package['defaultCore'] ?? false))
                        ->values()
                        ->all();
                    $selectedPackageCount = count(old('packages', $defaultPackageNames))
                        + count(old('extra_packages', $installableExtraPackageNames));
                    $defaultAdminUserMode = $existingUsers ? 'existing' : 'create';
                    $adminUserMode = old('admin_user_mode', $defaultAdminUserMode);
                    $environment = $preflightReport['environment'] ?? [];
                    $environmentStats = [
                        [
                            'label' => __('capell-installer::installer.environment_os'),
                            'value' => $environment['os'] ?? PHP_OS_FAMILY,
                        ],
                        [
                            'label' => __('capell-installer::installer.environment_php'),
                            'value' => $environment['php'] ?? PHP_VERSION,
                        ],
                        [
                            'label' => __('capell-installer::installer.environment_laravel'),
                            'value' => $environment['laravel'] ?? app()->version(),
                        ],
                    ];

                    foreach (['filament', 'livewire'] as $packageKey) {
                        if (! empty($environment[$packageKey])) {
                            $environmentStats[] = [
                                'label' => __('capell-installer::installer.environment_' . $packageKey),
                                'value' => $environment[$packageKey],
                            ];
                        }
                    }
                @endphp

                <form
                    id="install-form"
                    method="post"
                    action="{{ route('capell-installer.store') }}"
                    autocomplete="on"
                    novalidate
                >
                    @csrf

                    <input
                        type="hidden"
                        name="packages[]"
                        value="capell-app/installer"
                    />

                    <div class="panel-body">
                        <aside
                            class="installer-rail"
                            aria-label="{{ __('capell-installer::installer.installer_flow_preview') }}"
                        >
                            <div class="installer-rail-panel">
                                <ul
                                    class="installer-steps"
                                    aria-label="Installer sections"
                                >
                                    <li
                                        class="installer-step active"
                                        data-step-trigger="readiness"
                                        tabindex="0"
                                    >
                                        {{ __('capell-installer::installer.section_preflight') }}
                                    </li>
                                    <li
                                        class="installer-step"
                                        data-step-trigger="site"
                                        tabindex="0"
                                    >
                                        {{ __('capell-installer::installer.section_setup') }}
                                    </li>
                                    <li
                                        class="installer-step"
                                        data-step-trigger="packages"
                                        tabindex="0"
                                    >
                                        {{ __('capell-installer::installer.workspace_packages') }}
                                    </li>
                                    <li
                                        class="installer-step"
                                        data-step-trigger="options"
                                        tabindex="0"
                                    >
                                        {{ __('capell-installer::installer.section_options') }}
                                    </li>
                                </ul>
                            </div>

                            <div class="installer-rail-panel installer-meta">
                                @foreach ($environmentStats as $environmentStat)
                                    <div class="installer-meta-row">
                                        <strong>
                                            {{ $environmentStat['label'] }}
                                        </strong>
                                        <span>
                                            {{ $environmentStat['value'] }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </aside>

                        <div class="installer-workspace">
                            <section
                                class="section preflight-panel"
                                data-installer-step="readiness"
                            >
                                <div class="preflight-layout">
                                    <div class="preflight-summary">
                                        <div class="preflight-score-row">
                                            <h2 class="section-title">
                                                {{ __('capell-installer::installer.section_preflight') }}
                                            </h2>
                                            <span
                                                @class([
                                                    'preflight-status',
                                                    $preflightStatus,
                                                ])
                                            >
                                                {{ __('capell-installer::installer.preflight_status_' . $preflightStatus) }}
                                            </span>
                                        </div>

                                        <p class="summary-title">
                                            {{
                                                __('capell-installer::installer.preflight_passing_summary', [
                                                    'passed' => $preflightPassed,
                                                    'total' => $preflightChecks->count(),
                                                ])
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        class="preflight-checklist"
                                        aria-label="{{ __('capell-installer::installer.preflight_checklist') }}"
                                    >
                                        @foreach ($preflightChecks as $check)
                                            <article
                                                @class([
                                                    'preflight-check',
                                                    $check['status'] ?? 'warning',
                                                ])
                                            >
                                                <span
                                                    class="preflight-dot"
                                                    aria-hidden="true"
                                                ></span>
                                                <div class="preflight-copy">
                                                    <strong>
                                                        {{ $check['label'] }}
                                                    </strong>
                                                    <p>
                                                        {{ $check['message'] }}
                                                    </p>

                                                    @if (! empty($check['remediation']))
                                                        <p
                                                            class="preflight-remediation"
                                                        >
                                                            {{ $check['remediation'] }}
                                                        </p>
                                                    @endif
                                                </div>
                                            </article>
                                        @endforeach
                                    </div>
                                </div>
                            </section>

                            <section
                                class="section site-setup-section"
                                data-installer-step="site"
                                hidden
                            >
                                <p class="section-help">
                                    {{ __('capell-installer::installer.section_setup_help') }}
                                </p>

                                <div class="site-setup-grid">
                                    <div
                                        class="field"
                                        data-field="site_url"
                                    >
                                        <label
                                            class="field-label"
                                            for="site_url"
                                        >
                                            {{ __('capell-installer::installer.field_site_url') }}
                                        </label>
                                        <input
                                            id="site_url"
                                            type="url"
                                            name="site_url"
                                            required
                                            value="{{ old('site_url', $defaultSiteUrl) }}"
                                        />
                                        <span class="field-error"></span>
                                    </div>

                                    <div
                                        class="field"
                                        data-field="language"
                                    >
                                        <label
                                            class="field-label"
                                            for="language"
                                        >
                                            {{ __('capell-installer::installer.field_language') }}
                                        </label>
                                        <select
                                            id="language"
                                            name="language"
                                            required
                                            data-language-select
                                        >
                                            @foreach ($languages as $code => $label)
                                                <option
                                                    value="{{ $code }}"
                                                    @selected(old('language', $defaultLocale) === $code)
                                                >
                                                    {{ $label }}
                                                </option>
                                            @endforeach

                                            <option
                                                value="__custom"
                                                @selected(old('language') === '__custom')
                                            >
                                                {{ __('capell-installer::installer.field_language_custom_option') }}
                                            </option>
                                        </select>
                                        <span class="field-error"></span>
                                    </div>

                                    <div
                                        @class([
                                            'field',
                                            'site-custom-language',
                                            'hidden' => old('language') !== '__custom',
                                        ])
                                        data-custom-language-fields
                                        data-field="custom_language_code"
                                    >
                                        <label
                                            class="field-label"
                                            for="custom_language_code"
                                        >
                                            {{ __('capell-installer::installer.field_custom_language_code') }}
                                        </label>
                                        <input
                                            id="custom_language_code"
                                            type="text"
                                            name="custom_language_code"
                                            value="{{ old('custom_language_code') }}"
                                            inputmode="latin"
                                            pattern="[a-z]{2,3}"
                                            maxlength="3"
                                            list="installer-language-code-options"
                                            @if (old('language') === '__custom') required @endif
                                        />
                                        <datalist
                                            id="installer-language-code-options"
                                        >
                                            @foreach ($customLanguageSuggestions as $code => $label)
                                                <option
                                                    value="{{ $code }}"
                                                    label="{{ $label }}"
                                                ></option>
                                            @endforeach
                                        </datalist>
                                        <small class="field-help">
                                            {{ __('capell-installer::installer.field_custom_language_code_help') }}
                                        </small>
                                        <span class="field-error"></span>
                                    </div>
                                </div>
                            </section>

                            <section
                                class="section admin-account-section"
                                data-installer-step="site"
                                hidden
                            >
                                <div class="admin-account-layout">
                                    <div class="admin-account-form">
                                        <h2 class="section-title">
                                            {{ __('capell-installer::installer.section_admin_user') }}
                                        </h2>
                                        <p class="section-help">
                                            {{ __('capell-installer::installer.section_admin_user_help') }}
                                        </p>

                                        @php
                                            $selectedExistingUserId = old('existing_user_id', $existingUsers[0]['id'] ?? null);
                                        @endphp

                                        @if ($existingUsers)
                                            <div class="field">
                                                <label class="checkbox-row">
                                                    <input
                                                        type="radio"
                                                        name="admin_user_mode"
                                                        value="create"
                                                        data-admin-user-mode
                                                        @checked($adminUserMode !== 'existing')
                                                    />
                                                    <span class="text">
                                                        <strong>
                                                            {{ __('capell-installer::installer.field_admin_user_mode_create') }}
                                                        </strong>
                                                    </span>
                                                </label>

                                                <label class="checkbox-row">
                                                    <input
                                                        type="radio"
                                                        name="admin_user_mode"
                                                        value="existing"
                                                        data-admin-user-mode
                                                        @checked($adminUserMode === 'existing')
                                                    />
                                                    <span class="text">
                                                        <strong>
                                                            {{ __('capell-installer::installer.field_admin_user_mode_existing') }}
                                                        </strong>
                                                    </span>
                                                </label>
                                            </div>

                                            <div
                                                @class([
                                                    'admin-user-fields field',
                                                    'hidden' => $adminUserMode !== 'existing',
                                                ])
                                                data-admin-user-fields="existing"
                                            >
                                                <div
                                                    data-field="existing_user_id"
                                                >
                                                    <label
                                                        class="field-label"
                                                        for="existing_user_id"
                                                    >
                                                        {{ __('capell-installer::installer.field_existing_user') }}
                                                    </label>
                                                    <select
                                                        id="existing_user_id"
                                                        name="existing_user_id"
                                                        @if ($adminUserMode === 'existing') required @endif
                                                    >
                                                        <option
                                                            value=""
                                                        ></option>
                                                        @foreach ($existingUsers as $existingUser)
                                                            <option
                                                                value="{{ $existingUser['id'] }}"
                                                                @selected((string) $selectedExistingUserId === (string) $existingUser['id'])
                                                            >
                                                                {{ $existingUser['label'] }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    <span
                                                        class="field-error"
                                                    ></span>
                                                </div>
                                            </div>
                                        @else
                                            <input
                                                type="hidden"
                                                name="admin_user_mode"
                                                value="create"
                                            />
                                        @endif

                                        <div
                                            @class([
                                                'admin-user-fields',
                                                'hidden' => $adminUserMode === 'existing' && $existingUsers,
                                            ])
                                            data-admin-user-fields="create"
                                        >
                                            <div
                                                class="field"
                                                data-field="new_user_name"
                                            >
                                                <label
                                                    class="field-label"
                                                    for="new_user_name"
                                                >
                                                    {{ __('capell-installer::installer.field_user_name') }}
                                                </label>
                                                <input
                                                    id="new_user_name"
                                                    type="text"
                                                    name="new_user_name"
                                                    @if ($adminUserMode !== 'existing' || ! $existingUsers) required @endif
                                                    value="{{ old('new_user_name', $defaultAdminUser['name'] ?? '') }}"
                                                    autocomplete="name"
                                                />
                                                <span
                                                    class="field-error"
                                                ></span>
                                            </div>

                                            <div class="grid-2 field-group">
                                                <div
                                                    class="field"
                                                    data-field="new_user_email"
                                                >
                                                    <label
                                                        class="field-label"
                                                        for="new_user_email"
                                                    >
                                                        {{ __('capell-installer::installer.field_user_email') }}
                                                    </label>
                                                    <input
                                                        id="new_user_email"
                                                        type="email"
                                                        name="new_user_email"
                                                        @if ($adminUserMode !== 'existing' || ! $existingUsers) required @endif
                                                        value="{{ old('new_user_email', $defaultAdminUser['email'] ?? '') }}"
                                                        autocomplete="email"
                                                    />
                                                    <span
                                                        class="field-error"
                                                    ></span>
                                                </div>

                                                <div
                                                    class="field"
                                                    data-field="new_user_password"
                                                >
                                                    <label
                                                        class="field-label"
                                                        for="new_user_password"
                                                    >
                                                        {{ __('capell-installer::installer.field_user_password') }}
                                                    </label>
                                                    <input
                                                        id="new_user_password"
                                                        type="password"
                                                        name="new_user_password"
                                                        @if ($adminUserMode !== 'existing' || ! $existingUsers) required @endif
                                                        value="{{ old('new_user_password', $defaultAdminUser['password'] ?? '') }}"
                                                        minlength="8"
                                                        autocomplete="new-password"
                                                    />
                                                    <span
                                                        class="field-error"
                                                    ></span>
                                                </div>
                                            </div>
                                        </div>

                                        @if ($showRoleUsersToggle)
                                            <div class="field">
                                                <label class="checkbox-row">
                                                    <input
                                                        type="checkbox"
                                                        name="create_role_users"
                                                        value="1"
                                                        data-role-users-checkbox
                                                        @checked(old('create_role_users', '1') === '1')
                                                    />
                                                    <span class="text">
                                                        <strong>
                                                            {{ __('capell-installer::installer.option_create_role_users') }}
                                                        </strong>
                                                        <small>
                                                            {{ __('capell-installer::installer.option_create_role_users_help') }}
                                                        </small>
                                                    </span>
                                                </label>
                                            </div>
                                        @endif
                                    </div>

                                    <aside
                                        class="admin-account-guide"
                                        aria-label="{{ __('capell-installer::installer.admin_access_panel_title') }}"
                                    >
                                        <p class="summary-label">
                                            {{ __('capell-installer::installer.admin_access_panel_title') }}
                                        </p>
                                        <h3>
                                            {{ __('capell-installer::installer.admin_access_primary_title') }}
                                        </h3>
                                        <p>
                                            {{ __('capell-installer::installer.admin_access_panel_body') }}
                                        </p>
                                        <dl class="admin-access-list">
                                            <div>
                                                <dt>
                                                    {{ __('capell-installer::installer.admin_access_primary_title') }}
                                                </dt>
                                                <dd>
                                                    {{ __('capell-installer::installer.admin_access_primary_body') }}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>
                                                    {{ __('capell-installer::installer.admin_access_roles_title') }}
                                                </dt>
                                                <dd>
                                                    {{ __('capell-installer::installer.admin_access_roles_body') }}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>
                                                    {{ __('capell-installer::installer.admin_access_security_title') }}
                                                </dt>
                                                <dd>
                                                    {{ __('capell-installer::installer.admin_access_security_body') }}
                                                </dd>
                                            </div>
                                        </dl>
                                    </aside>
                                </div>
                            </section>

                            <section
                                class="packages-intro"
                                data-installer-step="packages"
                                hidden
                            >
                                <h2>
                                    {{ __('capell-installer::installer.packages_configure_heading') }}
                                </h2>
                                <p>
                                    {{ __('capell-installer::installer.packages_configure_body') }}
                                </p>
                            </section>

                            <input
                                type="hidden"
                                name="package_selection_mode"
                                value="custom"
                            />

                            @if ($visibleCorePackages)
                                <section
                                    class="section package-section"
                                    data-installer-step="packages"
                                    data-theme-selector
                                    data-package-selection-list
                                    hidden
                                >
                                    <div class="package-section-header">
                                        <div class="package-section-copy">
                                            <h2 class="section-title">
                                                {{ __('capell-installer::installer.section_core_extensions') }}
                                            </h2>
                                            <p class="section-help">
                                                {{ __('capell-installer::installer.section_core_extensions_help') }}
                                            </p>
                                        </div>
                                    </div>

                                    <div
                                        class="package-list field"
                                        data-package-list
                                    >
                                        @foreach ($visibleCorePackages as $package)
                                            @php
                                                $reqs = $package['requirements'] ?? [];
                                            @endphp

                                            <label
                                                class="checkbox-row package-option"
                                                data-package-row="{{ $package['name'] }}"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="packages[]"
                                                    value="{{ $package['name'] }}"
                                                    data-package-checkbox="{{ $package['name'] }}"
                                                    data-package-core="true"
                                                    data-package-default-core="true"
                                                    data-package-extension="false"
                                                    @checked(in_array($package['name'], old('packages', $defaultPackageNames), true))
                                                />
                                                <span class="text">
                                                    <strong>
                                                        {{ $package['label'] }}
                                                    </strong>
                                                    @if ($package['description'])
                                                        <span>
                                                            {{ $package['description'] }}
                                                        </span>
                                                    @endif

                                                    @if (! empty($reqs))
                                                        <span
                                                            class="package-meta"
                                                        >
                                                            {{ __('capell-installer::installer.requires') }}:
                                                            {{ implode(', ', $reqs) }}
                                                        </span>
                                                    @endif

                                                    <span
                                                        class="required-badge"
                                                        data-required-badge="{{ $package['name'] }}"
                                                    ></span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            @if ($installedPackages || $downloadablePackages)
                                <section
                                    class="section package-section"
                                    data-installer-step="packages"
                                    hidden
                                >
                                    <div class="package-section-header">
                                        <div class="package-section-copy">
                                            <h2 class="section-title">
                                                {{ __('capell-installer::installer.section_extra_extensions') }}
                                            </h2>
                                            <p
                                                class="section-help marketplace-later-note"
                                                data-marketplace-later-note
                                                hidden
                                            >
                                                {{ __('capell-installer::installer.marketplace_later_note') }}
                                            </p>
                                        </div>

                                        <label class="package-select-all">
                                            <input
                                                type="checkbox"
                                                data-package-select-all="extension"
                                            />
                                            <span data-package-select-all-label>
                                                {{ __('capell-installer::installer.package_select_all') }}
                                            </span>
                                        </label>
                                    </div>
                                </section>
                            @endif

                            @if ($installedPackages)
                                <section
                                    class="section package-section"
                                    data-installer-step="packages"
                                    data-theme-selector
                                    data-package-selection-list
                                    hidden
                                >
                                    <div class="package-section-header">
                                        <div class="package-section-copy">
                                            <h2 class="section-title">
                                                {{ __('capell-installer::installer.section_installed_packages') }}
                                            </h2>
                                            <p class="section-help">
                                                {{ __('capell-installer::installer.section_installed_packages_help') }}
                                            </p>
                                        </div>
                                    </div>

                                    <div
                                        class="package-list field"
                                        data-package-list
                                    >
                                        @foreach ($installedPackages as $package)
                                            @php
                                                $reqs = $package['requirements'] ?? [];
                                            @endphp

                                            <label
                                                class="checkbox-row package-option"
                                                data-package-row="{{ $package['name'] }}"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="packages[]"
                                                    value="{{ $package['name'] }}"
                                                    data-package-checkbox="{{ $package['name'] }}"
                                                    data-package-core="{{ ($package['defaultCore'] ?? false) ? 'true' : 'false' }}"
                                                    data-package-default-core="{{ ($package['defaultCore'] ?? false) ? 'true' : 'false' }}"
                                                    data-package-default="{{ ($package['defaultSelected'] ?? false) ? 'true' : 'false' }}"
                                                    data-package-extension="true"
                                                    @checked(in_array($package['name'], old('packages', $defaultPackageNames), true))
                                                />
                                                <span class="text">
                                                    <strong>
                                                        {{ $package['label'] }}
                                                    </strong>
                                                    @if ($package['description'])
                                                        <span>
                                                            {{ $package['description'] }}
                                                        </span>
                                                    @endif

                                                    @if (! empty($reqs))
                                                        <span
                                                            class="package-meta"
                                                        >
                                                            {{ __('capell-installer::installer.requires') }}:
                                                            {{ implode(', ', $reqs) }}
                                                        </span>
                                                    @endif

                                                    <span
                                                        class="required-badge"
                                                        data-required-badge="{{ $package['name'] }}"
                                                    ></span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            @if ($downloadablePackages)
                                <section
                                    class="section package-section"
                                    data-installer-step="packages"
                                    data-package-selection-list
                                    hidden
                                >
                                    <div class="package-section-header">
                                        <div class="package-section-copy">
                                            <h2 class="section-title">
                                                {{ __('capell-installer::installer.section_downloadable_packages') }}
                                            </h2>
                                            <p class="section-help">
                                                {{ __('capell-installer::installer.section_downloadable_packages_help') }}
                                            </p>
                                        </div>
                                    </div>

                                    <div
                                        class="package-list field"
                                        data-package-list
                                    >
                                        @foreach ($downloadablePackages as $package)
                                            @php
                                                $reqs = $package['requirements'] ?? [];
                                            @endphp

                                            <label
                                                class="checkbox-row package-option"
                                                data-package-row="{{ $package['name'] }}"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="extra_packages[]"
                                                    value="{{ $package['name'] }}"
                                                    data-package-checkbox="{{ $package['name'] }}"
                                                    data-package-core="{{ ($package['defaultCore'] ?? false) ? 'true' : 'false' }}"
                                                    data-package-default-core="{{ ($package['defaultCore'] ?? false) ? 'true' : 'false' }}"
                                                    data-package-default="{{ ($package['defaultSelected'] ?? false) ? 'true' : 'false' }}"
                                                    data-package-extension="true"
                                                    @checked(in_array($package['name'], old('extra_packages', $installableExtraPackageNames), true))
                                                />
                                                <span class="text">
                                                    <strong>
                                                        {{ $package['label'] }}
                                                    </strong>
                                                    @if ($package['description'])
                                                        <span>
                                                            {{ $package['description'] }}
                                                        </span>
                                                    @endif

                                                    @if (! empty($reqs))
                                                        <span
                                                            class="package-meta"
                                                        >
                                                            {{ __('capell-installer::installer.requires') }}:
                                                            {{ implode(', ', $reqs) }}
                                                        </span>
                                                    @endif

                                                    <span
                                                        class="required-badge"
                                                        data-required-badge="{{ $package['name'] }}"
                                                    ></span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            @if (($showThemeSelector ?? false) && ! empty($themeOptions ?? []))
                                <section
                                    class="section"
                                    data-installer-step="packages"
                                    hidden
                                >
                                    <h2 class="section-title">
                                        {{ __('capell-installer::installer.section_theme') }}
                                    </h2>
                                    <p class="section-help">
                                        {{ __('capell-installer::installer.section_theme_help') }}
                                    </p>

                                    <div
                                        class="theme-option-grid field"
                                        data-field="theme"
                                    >
                                        @foreach ($themeOptions as $themeKey => $themeOption)
                                            @php
                                                $previewImageUrl = $themeOption['previewImageUrl'] ?? null;
                                            @endphp

                                            <label
                                                class="theme-option-card"
                                                data-theme-card="{{ $themeKey }}"
                                            >
                                                <input
                                                    type="radio"
                                                    name="theme"
                                                    value="{{ $themeKey }}"
                                                    data-theme-option
                                                    data-theme-package="{{ $themePackageNames[$themeKey] ?? '' }}"
                                                    @checked(old('theme', $defaultThemeKey ?? 'default') === $themeKey)
                                                />
                                                <span
                                                    class="theme-option-preview"
                                                    aria-hidden="true"
                                                >
                                                    @if (is_string($previewImageUrl) && $previewImageUrl !== '')
                                                        <img
                                                            src="{{ $previewImageUrl }}"
                                                            alt=""
                                                            loading="lazy"
                                                        />
                                                    @else
                                                        <span
                                                            class="theme-option-preview-fallback"
                                                        >
                                                            {{ strtoupper(substr((string) ($themeOption['name'] ?? $themeKey), 0, 1)) }}
                                                        </span>
                                                    @endif
                                                </span>
                                                <span class="text">
                                                    <strong>
                                                        {{ $themeOption['name'] ?? $themeKey }}
                                                    </strong>
                                                    @if (! empty($themeOption['description']))
                                                        <span>
                                                            {{ $themeOption['description'] }}
                                                        </span>
                                                    @endif
                                                </span>
                                            </label>
                                        @endforeach

                                        <span class="field-error"></span>
                                    </div>
                                </section>
                            @endif

                            @unless ($developerToolingInstalled)
                                <section
                                    class="section"
                                    data-installer-step="options"
                                    hidden
                                >
                                    <h2 class="section-title">
                                        {{ __('capell-installer::installer.section_developer_tooling') }}
                                    </h2>
                                    <p class="section-help">
                                        {{ __('capell-installer::installer.section_developer_tooling_help') }}
                                    </p>

                                    <div class="field">
                                        <label class="checkbox-row">
                                            <input
                                                type="checkbox"
                                                name="install_developer_tooling"
                                                value="1"
                                                data-developer-tooling-checkbox
                                                @checked(old('install_developer_tooling') === '1')
                                            />
                                            <span class="text">
                                                <strong>
                                                    {{ __('capell-installer::installer.option_install_developer_tooling') }}
                                                </strong>
                                                <span>
                                                    {{ __('capell-installer::installer.option_install_developer_tooling_help') }}
                                                </span>
                                            </span>
                                        </label>
                                    </div>

                                    <div
                                        class="field"
                                        data-boost-tooling-options
                                    >
                                        <label
                                            class="checkbox-row"
                                            style="padding-left: 28px"
                                        >
                                            <input
                                                type="checkbox"
                                                name="configure_boost_developer_tooling"
                                                value="1"
                                                data-boost-tooling-checkbox
                                                @checked(old('configure_boost_developer_tooling') === '1')
                                            />
                                            <span class="text">
                                                <strong>
                                                    {{ __('capell-installer::installer.option_configure_boost_developer_tooling') }}
                                                </strong>
                                                <span>
                                                    {{ __('capell-installer::installer.option_configure_boost_developer_tooling_help') }}
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                </section>
                            @endunless

                            @php
                                $adminPanelChangesMode = old(
                                    'admin_panel_changes_mode',
                                    old('integrate_admin_panel', '1') === '1' ? 'auto' : 'manual',
                                );
                                $adminPackageIsSelected = in_array(
                                    'capell-app/admin',
                                    [
                                        ...old('packages', $defaultPackageNames),
                                        ...old('extra_packages', $installableExtraPackageNames),
                                    ],
                                    true,
                                );
                            @endphp

                            <section
                                class="section"
                                data-installer-step="options"
                                hidden
                            >
                                <h2 class="section-title">
                                    {{ __('capell-installer::installer.section_options') }}
                                </h2>

                                <div class="field">
                                    <label class="checkbox-row">
                                        <input
                                            type="checkbox"
                                            name="seed_default_data"
                                            value="1"
                                            @checked(old('seed_default_data', '1') === '1')
                                        />
                                        <span class="text">
                                            <strong>
                                                {{ __('capell-installer::installer.option_seed_default_data') }}
                                            </strong>
                                            <span>
                                                {{ __('capell-installer::installer.option_seed_default_data_help') }}
                                            </span>
                                        </span>
                                    </label>

                                    @if (($capellAlreadyInstalled ?? false) && ($canReinstall ?? false))
                                        <label class="checkbox-row">
                                            <input
                                                type="checkbox"
                                                name="fresh_install"
                                                value="1"
                                                @checked(old('fresh_install') === '1')
                                            />
                                            <span class="text">
                                                <strong>
                                                    {{ __('capell-installer::installer.option_fresh_install') }}
                                                </strong>
                                                <span>
                                                    {{ __('capell-installer::installer.option_fresh_install_help') }}
                                                </span>
                                            </span>
                                        </label>
                                    @endif

                                    @if ($showFilamentPanelToggle)
                                        <label class="checkbox-row">
                                            <input
                                                type="checkbox"
                                                name="install_filament_panel"
                                                value="1"
                                                @checked(old('install_filament_panel', '1') === '1')
                                            />
                                            <span class="text">
                                                <strong>
                                                    {{ __('capell-installer::installer.option_install_filament_panel') }}
                                                </strong>
                                                <span>
                                                    {{ __('capell-installer::installer.option_install_filament_panel_help') }}
                                                </span>
                                            </span>
                                        </label>
                                    @endif

                                    @if ($showWelcomeRouteToggle)
                                        <label class="checkbox-row">
                                            <input
                                                type="checkbox"
                                                name="install_welcome_route"
                                                value="1"
                                                @checked(old('install_welcome_route', '1') === '1')
                                            />
                                            <span class="text">
                                                <strong>
                                                    {{ __('capell-installer::installer.option_install_welcome_route') }}
                                                </strong>
                                                <span>
                                                    {{ __('capell-installer::installer.option_install_welcome_route_help') }}
                                                </span>
                                            </span>
                                        </label>
                                    @endif
                                </div>

                                <div class="field">
                                    @if ($showDemoToggle)
                                        <label class="checkbox-row">
                                            <input
                                                type="checkbox"
                                                name="demo_content"
                                                value="1"
                                                @checked(old('demo_content') === '1')
                                            />
                                            <span class="text">
                                                <strong>
                                                    {{ __('capell-installer::installer.option_demo_content') }}
                                                </strong>
                                                <span>
                                                    {{ __('capell-installer::installer.option_demo_content_help') }}
                                                </span>
                                            </span>
                                        </label>
                                    @endif

                                    <label class="checkbox-row">
                                        <input
                                            type="checkbox"
                                            name="rebuild_resources"
                                            value="1"
                                            @checked(old('rebuild_resources', '1') === '1')
                                        />
                                        <span class="text">
                                            <strong>
                                                {{ __('capell-installer::installer.option_rebuild_resources') }}
                                            </strong>
                                            <span>
                                                {{ __('capell-installer::installer.option_rebuild_resources_help') }}
                                            </span>
                                        </span>
                                    </label>

                                    <label class="checkbox-row">
                                        <input
                                            type="checkbox"
                                            name="run_as_job"
                                            value="1"
                                            @checked(old('run_as_job') === '1')
                                        />
                                        <span class="text">
                                            <strong>
                                                {{ __('capell-installer::installer.option_run_as_job') }}
                                            </strong>
                                            <span>
                                                {{ __('capell-installer::installer.option_run_as_job_help') }}
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </section>

                            <fieldset
                                @class([
                                    'admin-panel-changes',
                                    'hidden' => ! $adminPackageIsSelected,
                                ])
                                data-installer-step="options"
                                data-admin-panel-changes
                                data-admin-package-name="capell-app/admin"
                                hidden
                            >
                                <div class="section-title">
                                    {{ __('capell-installer::installer.section_admin_integration') }}
                                </div>

                                <div class="admin-panel-changes-layout">
                                    <div>
                                        <p class="section-help">
                                            {{ __('capell-installer::installer.section_admin_integration_help') }}
                                        </p>

                                        <div class="field">
                                            <label class="checkbox-row">
                                                <input
                                                    type="radio"
                                                    name="admin_panel_changes_mode"
                                                    value="auto"
                                                    data-admin-panel-changes-mode
                                                    @checked($adminPanelChangesMode === 'auto')
                                                />
                                                <span class="text">
                                                    <strong>
                                                        {{ __('capell-installer::installer.option_admin_panel_changes_auto') }}
                                                    </strong>
                                                    <span>
                                                        {{ __('capell-installer::installer.option_admin_panel_changes_auto_help') }}
                                                    </span>
                                                </span>
                                            </label>

                                            <label class="checkbox-row">
                                                <input
                                                    type="radio"
                                                    name="admin_panel_changes_mode"
                                                    value="manual"
                                                    data-admin-panel-changes-mode
                                                    @checked($adminPanelChangesMode === 'manual')
                                                />
                                                <span class="text">
                                                    <strong>
                                                        {{ __('capell-installer::installer.option_admin_panel_changes_manual') }}
                                                    </strong>
                                                    <span>
                                                        {{ __('capell-installer::installer.option_admin_panel_changes_manual_help') }}
                                                    </span>
                                                </span>
                                            </label>
                                        </div>

                                        <p
                                            @class([
                                                'section-help',
                                                'hidden' => $adminPanelChangesMode !== 'manual',
                                            ])
                                            data-admin-panel-manual-help
                                        >
                                            <a
                                                href="{{ __('capell-installer::installer.admin_setup_docs_url') }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {{ __('capell-installer::installer.admin_setup_docs_link') }}
                                            </a>
                                        </p>
                                    </div>

                                    <div class="admin-panel-changes-included">
                                        <p class="section-help">
                                            {{ __('capell-installer::installer.admin_panel_changes_included') }}
                                        </p>
                                        <ul class="admin-panel-change-list">
                                            <li>
                                                {{ __('capell-installer::installer.option_integrate_admin_panel_help') }}
                                            </li>
                                            <li>
                                                {{ __('capell-installer::installer.option_admin_add_colors_help') }}
                                            </li>
                                            <li>
                                                {{ __('capell-installer::installer.option_admin_add_widgets_help') }}
                                            </li>
                                            <li>
                                                {{ __('capell-installer::installer.option_admin_add_navigation_help') }}
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <footer class="panel-footer installer-actions">
                            <div class="panel-footer-inner">
                                <button
                                    class="button secondary"
                                    type="button"
                                    data-step-back
                                >
                                    <span aria-hidden="true">&larr;</span>
                                    {{ __('capell-installer::installer.installer_back') }}
                                </button>
                                <button
                                    class="button primary"
                                    type="button"
                                    data-step-continue
                                >
                                    {{ __('capell-installer::installer.installer_continue') }}
                                    <span aria-hidden="true">&rarr;</span>
                                </button>
                                <button
                                    id="submit-button"
                                    class="button primary submit"
                                    type="submit"
                                    hidden
                                >
                                    <span class="spinner"></span>
                                    <span
                                        class="label"
                                        data-submit-label
                                    >
                                        {{ __('capell-installer::installer.submit') }}
                                    </span>
                                    <span
                                        class="submit-arrow"
                                        aria-hidden="true"
                                    >
                                        &rarr;
                                    </span>
                                </button>
                            </div>
                        </footer>
                    </div>
                </form>
            </div>

            <div
                class="progress-view"
                id="progress-view"
                aria-live="polite"
            >
                <div
                    class="progress-status"
                    id="progress-status"
                >
                    <span class="status-dot"></span>
                    <span id="progress-status-label">
                        {{ __('capell-installer::installer.starting') }}
                    </span>

                    <span
                        class="current-step-strip"
                        id="current-step-strip"
                        hidden
                    >
                        <span class="current-step-separator"></span>
                        <span class="current-step-label">
                            {{ __('capell-installer::installer.current_step') }}
                        </span>
                        <strong id="current-step-name">
                            {{ __('capell-installer::installer.starting') }}
                        </strong>
                        <span class="current-step-spinner"></span>
                    </span>
                </div>

                <div
                    class="progress-loader"
                    id="progress-loader"
                    aria-hidden="true"
                >
                    <span></span>
                </div>

                <section
                    class="failure-panel"
                    id="failure-panel"
                    role="alert"
                    hidden
                >
                    <div class="failure-copy">
                        <span
                            class="failure-icon"
                            aria-hidden="true"
                        >
                            !
                        </span>
                        <div>
                            <h2 id="failure-title">
                                {{ __('capell-installer::installer.installation_failed_heading') }}
                            </h2>
                            <p id="failure-message"></p>
                        </div>
                    </div>
                    <button
                        class="button danger"
                        id="failure-retry-button"
                        type="button"
                    >
                        {{ __('capell-installer::installer.restart_install') }}
                    </button>
                </section>

                <div
                    class="progress-steps multi-step-progress"
                    id="progress-steps"
                ></div>

                <details
                    class="technical-log-panel"
                    id="technical-log-panel"
                    open
                >
                    <summary>
                        <span>
                            {{ __('capell-installer::installer.technical_logs') }}
                        </span>
                        <span class="technical-log-actions">
                            <button
                                aria-label="{{ __('capell-installer::installer.download_report') }}"
                                class="progress-report-button"
                                data-download-filename=""
                                data-report-download-button
                                form="report-link"
                                hidden
                                title="{{ __('capell-installer::installer.download_report') }}"
                                type="submit"
                            >
                                <svg
                                    aria-hidden="true"
                                    fill="none"
                                    height="18"
                                    viewBox="0 0 24 24"
                                    width="18"
                                >
                                    <path
                                        d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"
                                        stroke="currentColor"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                    />
                                    <path
                                        d="M14 2v5h5M12 18v-6m0 6 3-3m-3 3-3-3"
                                        stroke="currentColor"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                    />
                                </svg>
                            </button>
                            <span
                                class="technical-log-chevron"
                                aria-hidden="true"
                            ></span>
                        </span>
                    </summary>
                    <form
                        action="#"
                        class="progress-report-link"
                        id="report-link"
                        method="GET"
                        target="_blank"
                        hidden
                    ></form>
                    <pre
                        class="log"
                        id="log"
                    ><span class="line empty">{{ __('capell-installer::installer.waiting_for_output') }}</span></pre>
                </details>

                <div class="progress-actions">
                    <a
                        class="button secondary"
                        href="{{ route('capell-installer.show') }}"
                        id="back-link"
                        hidden
                    >
                        {{ __('capell-installer::installer.back_to_installer') }}
                    </a>
                    <a
                        class="button primary"
                        href="{{ url('/admin') }}"
                        id="admin-link"
                        hidden
                    >
                        {{ __('capell-installer::installer.go_to_admin') }}
                    </a>
                </div>
            </div>
            @php
                $installerProviderClass = 'Capell\\Installer\\Providers\\InstallerServiceProvider';
                $installerPackagePath = dirname((new ReflectionClass($installerProviderClass))->getFileName(), 3);
                $installerScriptPath = $installerPackagePath . '/resources/js/install.js';
                $installerScript = is_file($installerScriptPath) ? file_get_contents($installerScriptPath) : '';
                $installerConfig = [
                    'requirementsMap' => $requirementsMap,
                    'themePackageNames' => $themePackageNames ?? [],
                    'installedThemeKeys' => $installedThemeKeys ?? [],
                    'messages' => [
                        'sessionExpired' => __('capell-installer::installer.session_expired'),
                        'waitingForOutput' => __('capell-installer::installer.waiting_for_output'),
                        'unknownError' => __('capell-installer::installer.unknown_error'),
                        'networkError' => __('capell-installer::installer.network_error'),
                        'serverTimeoutError' => __('capell-installer::installer.server_timeout_error'),
                        'requiredByPackages' => __('capell-installer::installer.required_by_packages'),
                        'packageSelectAll' => __('capell-installer::installer.package_select_all'),
                        'packageUnselectAll' => __('capell-installer::installer.package_unselect_all'),
                        'submitLabel' => __('capell-installer::installer.submit'),
                        'installPackageLabel' => __('capell-installer::installer.install_package', ['count' => '__count__']),
                        'installPackagesLabel' => __('capell-installer::installer.install_packages', ['count' => '__count__']),
                        'installingPackageLabel' => __('capell-installer::installer.installing_package', ['count' => '__count__']),
                        'installingPackagesLabel' => __('capell-installer::installer.installing_packages', ['count' => '__count__']),
                        'createAdminSummary' => __('capell-installer::installer.field_admin_user_mode_create'),
                        'existingAdminSummary' => __('capell-installer::installer.field_admin_user_mode_existing'),
                        'backgroundJobSummary' => __('capell-installer::installer.option_run_as_job'),
                        'directExecutionSummary' => __('capell-installer::installer.workspace_execution_direct'),
                        'installationFailedHeading' => __('capell-installer::installer.installation_failed_heading'),
                        'installationProblemMessage' => __('capell-installer::installer.installation_problem_message'),
                        'progressCompletedSteps' => __('capell-installer::installer.progress_completed_steps', [
                            'completed' => '__completed__',
                            'total' => '__total__',
                        ]),
                        'progressPreviousStep' => __('capell-installer::installer.progress_previous_step'),
                        'progressCurrentStep' => __('capell-installer::installer.current_step'),
                        'progressNextStep' => __('capell-installer::installer.progress_next_step'),
                        'statuses' => [
                            'queued' => __('capell-installer::installer.status_queued'),
                            'running' => __('capell-installer::installer.status_running'),
                            'complete' => __('capell-installer::installer.status_complete'),
                            'failed' => __('capell-installer::installer.status_failed'),
                        ],
                    ],
                ];
            @endphp

            <script
                id="capell-installer-config"
                type="application/json"
            >
                {!! Js::encode($installerConfig) !!}
            </script>
            <script>
                {!! $installerScript !!}
            </script>
        @endif
    </main>
@endsection
