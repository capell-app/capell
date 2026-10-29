@extends('capell-installer::layouts.installer')

@section('title', __('capell-installer::installer.page_title'))
@section('bodyClass', 'installer-screen-install installer-screen-bootstrap')

@section('content')
    <main class="panel">
        <header class="panel-header">
            <h1>{{ __('capell-installer::installer.page_title') }}</h1>
            <p>{{ __('capell-installer::installer.bootstrap_intro') }}</p>
        </header>
        <form
            method="post"
            action="{{ route('capell-installer.unlock') }}"
            class="panel-body"
        >
            @csrf
            @if ($bootstrapError ?? false)
                <p class="field-error" role="alert">{{ __('capell-installer::installer.bootstrap_required') }}</p>
            @endif
            <div class="field">
                <label
                    for="bootstrap-secret"
                    >{{ __('capell-installer::installer.bootstrap_secret') }}</label
                >
                <input
                    id="bootstrap-secret"
                    type="password"
                    name="bootstrap_secret"
                    autocomplete="off"
                    aria-describedby="bootstrap-help"
                    required
                />
                <p id="bootstrap-help" class="field-help">{{ __('capell-installer::installer.bootstrap_secret_help') }}</p>
            </div>
            <p>
                {{ __('capell-installer::installer.bootstrap_guidance') }}
                <a
                    href="https://docs.capell.app/core/getting-started/install/#browser-installer"
                    >{{ __('capell-installer::installer.bootstrap_docs') }}</a
                >
            </p>
            <button
                type="submit"
                class="button primary"
            >
                {{ __('capell-installer::installer.unlock') }}
            </button>
        </form>
    </main>
@endsection
