@extends('capell-installer::layouts.installer')

@section('title', __('capell-installer::installer.page_title'))
@section('bodyClass', 'installer-screen-install')

@section('content')
    <main class="panel">
        <header class="panel-header">
            <h1>{{ __('capell-installer::installer.page_title') }}</h1>
            <p>{{ __('capell-installer::installer.bootstrap_required') }}</p>
        </header>
        <form
            method="post"
            action="{{ route('capell-installer.unlock') }}"
            class="panel-body"
        >
            @csrf
            <label
                for="bootstrap-secret"
                >{{ __('capell-installer::installer.bootstrap_secret') }}</label
            >
            <input
                id="bootstrap-secret"
                type="password"
                name="bootstrap_secret"
                autocomplete="off"
                required
            />
            <p>{{ __('capell-installer::installer.bootstrap_secret_help') }}</p>
            <button
                type="submit"
                class="button primary"
            >
                {{ __('capell-installer::installer.unlock') }}
            </button>
        </form>
    </main>
@endsection
