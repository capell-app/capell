@php
    use Capell\Installer\Providers\InstallerServiceProvider;

    $installerPackagePath = dirname((new ReflectionClass(InstallerServiceProvider::class))->getFileName(), 3);
    $installerStylesheetPath = $installerPackagePath . '/resources/css/installer.css';
    $installerStylesheet = is_file($installerStylesheetPath) ? file_get_contents($installerStylesheetPath) : '';
    $installerFavicon = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="16" fill="#17130b"/><path fill="#f8be3d" d="M54.55 18.42c-9.85 0-18.62 3.01-25.25 8.69-7.8 6.67-12.28 17.17-12.28 28.83 0 8.59 2.82 16.05 8.15 21.58 6.15 6.38 15.53 9.75 27.13 9.75 4.74 0 9.31-.61 13.34-1.15 3.12-.42 5.82-.78 7.92-.78h2.39l3.15-19.23c.23-1.39-.65-2.72-2.02-3.05-1.2-.29-2.43.31-2.96 1.43-4.14 8.76-10.93 13.2-20.18 13.2-6.65 0-12.11-2.26-15.79-6.54-4.02-4.66-6.06-11.77-6.06-21.12 0-9.46 2.28-17.06 6.6-21.97 3.63-4.14 8.75-6.24 15.23-6.24 9.44 0 17.32 5.13 20.58 13.38.49 1.23 1.82 1.91 3.11 1.58 1.26-.32 2.09-1.52 1.94-2.82L77.46 15.6h-2.57c-2.19 0-5.34-.39-8.98-.85-3.77-.47-8.04-1.01-11.36-1.01z"/></svg>');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta
            name="viewport"
            content="width=device-width,initial-scale=1"
        />
        <meta
            name="robots"
            content="noindex,nofollow"
        />
        <meta
            name="csrf-token"
            content="{{ csrf_token() }}"
        />
        <title>@yield('title')</title>
        <link
            rel="icon"
            type="image/svg+xml"
            href="{{ $installerFavicon }}"
        />
        <style>
            {!! $installerStylesheet !!}
        </style>
        @yield('head')
    </head>

    <body class="installer-screen @yield('bodyClass')">
        @yield('content')
    </body>
</html>
