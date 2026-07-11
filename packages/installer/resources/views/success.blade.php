@extends('capell-installer::layouts.installer')

@section('title', __('capell-installer::installer.launchpad_heading'))
@section('bodyClass', 'installer-screen-success')

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
                @if (view()->exists('capell-admin::img.logo'))
                    @include('capell-admin::img.logo')
                @else
                    <span>Capell</span>
                @endif
            </div>
        </header>

        <div class="panel-body completion-panel">
            <section class="section completion-security-panel">
                <div class="completion-security-copy">
                    <h2>
                        {{ __('capell-installer::installer.remove_installer_recommendation_heading') }}
                    </h2>
                    <p>
                        {{ __('capell-installer::installer.remove_installer_recommendation_body') }}
                    </p>
                </div>

                @if ($canRemoveInstaller ?? false)
                    <form
                        method="POST"
                        action="{{ route('capell-installer.destroy') }}"
                        data-remove-installer-form
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
                @endif
            </section>

            <section class="section completion-success-panel">
                <div class="completion-primary-column">
                    <span class="launchpad-success-badge">
                        {{ __('capell-installer::installer.status_complete') }}
                    </span>

                    <div class="completion-copy">
                        <h2>
                            {{ __('capell-installer::installer.launchpad_heading') }}
                        </h2>
                    </div>

                    @if ($primaryAdmin !== null)
                        <div class="launchpad-account-summary">
                            <strong>
                                {{ __('capell-installer::installer.launchpad_primary_admin') }}
                            </strong>
                            <span>{{ $primaryAdmin }}</span>
                        </div>
                    @else
                        <p class="completion-security-note">
                            {{ __('capell-installer::installer.launchpad_details_hidden') }}
                        </p>
                    @endif
                </div>

                <div class="completion-launch-column">
                    <div class="completion-checklist">
                        <h3>
                            {{ __('capell-installer::installer.launchpad_checklist_title') }}
                        </h3>
                        <p>
                            {{ __('capell-installer::installer.launchpad_message') }}
                        </p>

                        <ul>
                            <li>
                                {{ __('capell-installer::installer.launchpad_check_pages') }}
                            </li>
                            <li>
                                {{ __('capell-installer::installer.launchpad_check_site_settings') }}
                            </li>

                            @if ($roleUsersCreated)
                                <li>
                                    {{ __('capell-installer::installer.launchpad_check_roles') }}
                                </li>
                            @endif
                        </ul>
                    </div>

                    <div class="completion-actions">
                        <a
                            class="button secondary"
                            href="{{ url('/admin') }}"
                        >
                            {{ __('capell-installer::installer.go_to_admin') }}
                        </a>
                        <a
                            class="button secondary"
                            href="{{ url('/') }}"
                        >
                            {{ __('capell-installer::installer.return_to_site') }}
                        </a>
                    </div>
                </div>
            </section>
        </div>
    </main>
@endsection
