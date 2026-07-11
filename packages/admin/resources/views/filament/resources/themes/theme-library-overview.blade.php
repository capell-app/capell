@php
    $component = $livewire ?? (isset($getLivewire) ? $getLivewire() : null);
    $library = is_object($component) && method_exists($component, 'getThemeLibraryData')
        ? $component->getThemeLibraryData()
        : ['installed' => [], 'available' => [], 'pending' => 0, 'pendingInstalls' => [], 'warnings' => []];
    $installed = $library['installed'] ?? [];
    $available = $library['available'] ?? [];
    $pendingInstalls = $library['pendingInstalls'] ?? [];
    $warnings = $library['warnings'] ?? [];
    $pending = (int) ($library['pending'] ?? 0);
    $canCreateThemes = is_object($component) && method_exists($component, 'canCreateThemes')
        ? $component->canCreateThemes()
        : false;
@endphp

@once
    <style>
        .capell-theme-library-overview {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-bottom: 1rem;
        }

        .capell-theme-library-overview__panel {
            background: rgb(255 255 255);
            border: 1px solid rgb(226 232 240);
            border-radius: 0.5rem;
            padding: 0.875rem;
        }

        .capell-theme-library-overview__label {
            color: rgb(71 85 105);
            font-size: 0.75rem;
            font-weight: 650;
            letter-spacing: 0;
            line-height: 1.2;
            margin: 0;
        }

        .capell-theme-library-overview__value {
            color: rgb(15 23 42);
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.1;
            margin-top: 0.375rem;
        }

        .capell-theme-library-overview__note {
            color: rgb(100 116 139);
            font-size: 0.8125rem;
            line-height: 1.35;
            margin-top: 0.25rem;
        }

        .capell-theme-library-overview__sections {
            display: grid;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .capell-theme-library-overview__section {
            background: rgb(255 255 255);
            border: 1px solid rgb(226 232 240);
            border-radius: 0.5rem;
            padding: 1rem;
        }

        .capell-theme-library-overview__section h2 {
            color: rgb(15 23 42);
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.35;
            margin: 0 0 0.75rem;
        }

        .capell-theme-library-overview__list {
            display: grid;
            gap: 0.75rem;
        }

        .capell-theme-library-overview__item {
            align-items: flex-start;
            border: 1px solid rgb(226 232 240);
            border-radius: 0.5rem;
            display: flex;
            gap: 0.75rem;
            justify-content: space-between;
            padding: 0.75rem;
        }

        .capell-theme-library-overview__item-main {
            min-width: 0;
        }

        .capell-theme-library-overview__item-title {
            color: rgb(15 23 42);
            font-weight: 700;
        }

        .capell-theme-library-overview__button {
            align-items: center;
            background: rgb(194 65 12);
            border: 1px solid rgb(194 65 12);
            border-radius: 0.375rem;
            color: rgb(255 255 255);
            cursor: pointer;
            display: inline-flex;
            flex-shrink: 0;
            font-size: 0.8125rem;
            font-weight: 650;
            justify-content: center;
            line-height: 1;
            min-height: 2.75rem;
            padding: 0.625rem 0.875rem;
        }

        .capell-theme-library-overview__button:hover:not(:disabled) {
            background: rgb(154 52 18);
            border-color: rgb(154 52 18);
        }

        .capell-theme-library-overview__button:disabled {
            background: rgb(241 245 249);
            border-color: rgb(203 213 225);
            color: rgb(100 116 139);
            cursor: not-allowed;
        }

        .capell-theme-library-overview__code {
            background: rgb(15 23 42);
            border-radius: 0.375rem;
            color: rgb(248 250 252);
            display: block;
            font-family:
                ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.75rem;
            margin-top: 0.5rem;
            overflow-x: auto;
            padding: 0.5rem;
            white-space: nowrap;
        }

        .capell-theme-library-overview__sr-only {
            clip: rect(0, 0, 0, 0);
            border: 0;
            height: 1px;
            margin: -1px;
            overflow: hidden;
            padding: 0;
            position: absolute;
            white-space: nowrap;
            width: 1px;
        }

        @media (max-width: 900px) {
            .capell-theme-library-overview {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 540px) {
            .capell-theme-library-overview {
                grid-template-columns: 1fr;
            }

            .capell-theme-library-overview__item {
                flex-direction: column;
            }

            .capell-theme-library-overview__button {
                width: 100%;
            }
        }

        :is(.dark .capell-theme-library-overview__panel) {
            background: rgb(17 24 39);
            border-color: rgb(51 65 85);
        }

        :is(.dark .capell-theme-library-overview__section),
        :is(.dark .capell-theme-library-overview__item) {
            background: rgb(17 24 39);
            border-color: rgb(51 65 85);
        }

        :is(.dark .capell-theme-library-overview__button:disabled) {
            background: rgb(31 41 55);
            border-color: rgb(75 85 99);
            color: rgb(156 163 175);
        }

        :is(.dark .capell-theme-library-overview__label),
        :is(.dark .capell-theme-library-overview__note) {
            color: rgb(148 163 184);
        }

        :is(.dark .capell-theme-library-overview__value),
        :is(.dark .capell-theme-library-overview__section h2),
        :is(.dark .capell-theme-library-overview__item-title) {
            color: rgb(248 250 252);
        }
    </style>
@endonce

<section
    class="capell-theme-library-overview"
    aria-label="{{ __('capell-admin::theme-library.title') }}"
>
    <div class="capell-theme-library-overview__panel">
        <p class="capell-theme-library-overview__label">
            {{ __('capell-admin::theme-library.sections.installed') }}
        </p>
        <div class="capell-theme-library-overview__value">
            {{ count($installed) }}
        </div>
    </div>

    <div class="capell-theme-library-overview__panel">
        <p class="capell-theme-library-overview__label">
            {{ __('capell-admin::theme-library.sections.available') }}
        </p>
        <div class="capell-theme-library-overview__value">
            {{ count($available) }}
        </div>
        @if (count($available) > 0)
            <div class="capell-theme-library-overview__note">
                {{ collect($available)->pluck('title')->take(2)->implode(', ') }}
            </div>
        @endif
    </div>

    <div class="capell-theme-library-overview__panel">
        <p class="capell-theme-library-overview__label">
            {{ __('capell-admin::theme-library.sections.pending') }}
        </p>
        <div class="capell-theme-library-overview__value">{{ $pending }}</div>
    </div>

    <div class="capell-theme-library-overview__panel">
        <p class="capell-theme-library-overview__label">
            {{ __('capell-admin::theme-library.sections.diagnostics') }}
        </p>
        <div class="capell-theme-library-overview__value">
            {{ count($warnings) }}
        </div>
        @if (count($warnings) > 0)
            <div class="capell-theme-library-overview__note">
                {{ collect($warnings)->pluck('title')->take(2)->implode(', ') }}
            </div>
        @endif
    </div>
</section>

@if (count($pendingInstalls) > 0)
    <div class="capell-theme-library-overview__sections">
        <section class="capell-theme-library-overview__section">
            <h2>
                {{ __('capell-admin::theme-library.sections.pending') }}
            </h2>
            <div class="capell-theme-library-overview__list">
                @foreach (collect($pendingInstalls)->take(5) as $install)
                    <article class="capell-theme-library-overview__item">
                        <div class="capell-theme-library-overview__item-title">
                            {{ $install['name'] }}
                        </div>
                        <div class="capell-theme-library-overview__note">
                            {{ $install['package'] }}
                        </div>
                        @if (($install['command'] ?? '') !== '')
                            <code class="capell-theme-library-overview__code">
                                {{ $install['command'] }}
                            </code>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    </div>
@endif
