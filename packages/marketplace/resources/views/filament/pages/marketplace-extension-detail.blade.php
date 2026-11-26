<x-filament-panels::page>
    @php
        $detail = $this->detail();
    @endphp

    @if ($this->detailLoadError !== null)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('capell-marketplace::marketplace.detail.unavailable_heading') }}
            </x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $this->detailLoadError }}</p>
        </x-filament::section>
    @elseif ($detail)
        @php
            $screenshots = collect($detail->images)
                ->filter(fn (array $image): bool => is_string($image['url'] ?? null))
                ->values();
            $suite = $this->suitePresentation();
            $manualInstallCommands = $this->manualInstallCommands();
            $ratingAverage = $this->ratingAverage();
            $ratingsCount = $this->ratingsCount();
            $categoryLabels = $this->categoryLabels();
            $compatibility = $this->compatibilityRequirements();
            $releasedAt = $this->releasedAtLabel();
            $panel = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
            $sectionHeading = 'text-lg font-semibold text-gray-950 dark:text-white';
            $muted = 'text-sm leading-6 text-gray-600 dark:text-gray-300';
            $chip = 'inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200';
            $sections = array_filter([
                'overview' => __('capell-marketplace::marketplace.detail.overview_heading'),
                'screenshots' => $screenshots->isNotEmpty() ? __('capell-marketplace::marketplace.detail.screenshots_heading') : null,
                'changelog' => $detail->versionHistory !== [] ? __('capell-marketplace::marketplace.detail.version_history_heading') : null,
                'reviews' => __('capell-marketplace::marketplace.detail.reviews_heading'),
                'requirements' => __('capell-marketplace::marketplace.detail.requirements_heading'),
                'permissions' => __('capell-marketplace::marketplace.detail.permissions_heading'),
            ]);
        @endphp

        <div class="space-y-6">
            @include('capell-marketplace::filament.pages.partials.extension-health-alerts', [
                'alerts' => $this->criticalHealthAlerts(),
            ])

            <div
                class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start"
            >
                {{-- Hero: who made it, what it is, and how it is received. --}}
                <header
                    class="flex flex-col gap-5 sm:flex-row sm:items-start lg:col-start-1"
                >
                    @if ($detail->imageUrl !== null)
                        <img
                            src="{{ $detail->imageUrl }}"
                            alt="{{ __('capell-marketplace::marketplace.detail.icon_alt', ['name' => $detail->name]) }}"
                            class="size-20 shrink-0 rounded-2xl object-cover shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10"
                        />
                    @else
                        <div
                            aria-hidden="true"
                            class="bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300 flex size-20 shrink-0 items-center justify-center rounded-2xl ring-1 ring-primary-600/10"
                        >
                            <x-filament::icon
                                icon="heroicon-o-puzzle-piece"
                                class="size-10"
                            />
                        </div>
                    @endif

                    <div class="min-w-0 space-y-3">
                        <div class="space-y-1">
                            <h1
                                class="text-2xl font-semibold tracking-tight text-balance text-gray-950 sm:text-3xl dark:text-white"
                            >
                                {{ $detail->name }}
                            </h1>

                            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                                @if ($detail->publisherName !== null)
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        {{ __('capell-marketplace::marketplace.detail.by_publisher', ['publisher' => $detail->publisherName]) }}
                                        @if ($detail->publisherVerified)
                                            <x-filament::icon
                                                icon="heroicon-s-check-badge"
                                                class="text-primary-600 dark:text-primary-400 size-4"
                                                :label="__('capell-marketplace::marketplace.detail.publisher_verified')"
                                            />
                                        @endif
                                    </span>
                                @endif

                                <span>{{ $this->compatibilityLabel() }}</span>

                                @if ($detail->latestVersion !== null)
                                    <span
                                        class="tabular-nums"
                                        >{{ __('capell-marketplace::marketplace.detail.version_label', ['version' => $detail->latestVersion]) }}</span
                                    >
                                @endif

                                @if ($releasedAt !== null)
                                    <span
                                        >{{ __('capell-marketplace::marketplace.detail.released_label', ['date' => $releasedAt]) }}</span
                                    >
                                @endif
                            </p>
                        </div>

                        <div
                            class="flex items-center gap-2 text-sm"
                            data-capell-marketplace-extension-rating
                        >
                            @if ($ratingAverage !== null && $ratingsCount > 0)
                                <span
                                    class="flex"
                                    aria-hidden="true"
                                >
                                    @foreach (range(1, 5) as $star)
                                        <x-filament::icon
                                            :icon="$star <= round($ratingAverage) ? 'heroicon-s-star' : 'heroicon-o-star'"
                                            class="{{ $star <= round($ratingAverage) ? 'text-warning-500' : 'text-gray-300 dark:text-gray-600' }} size-4"
                                        />
                                    @endforeach
                                </span>
                                <span
                                    class="font-medium text-gray-950 tabular-nums dark:text-white"
                                >
                                    <span
                                        class="sr-only"
                                        >{{ __('capell-marketplace::marketplace.detail.rating_label', ['rating' => $ratingAverage]) }}</span
                                    >
                                    <span
                                        aria-hidden="true"
                                        >{{ number_format($ratingAverage, 1) }}</span
                                    >
                                </span>
                                <a
                                    href="#reviews"
                                    class="text-gray-600 underline-offset-4 hover:underline dark:text-gray-300"
                                >
                                    {{ trans_choice('capell-marketplace::marketplace.detail.ratings_count', $ratingsCount, ['count' => $ratingsCount]) }}
                                </a>
                            @else
                                <span
                                    class="text-gray-500 dark:text-gray-400"
                                    >{{ __('capell-marketplace::marketplace.detail.no_ratings') }}</span
                                >
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($categoryLabels as $category)
                                <span class="{{ $chip }}">{{ $category }}</span>
                            @endforeach

                            @if ($detail->securityReviewed)
                                <span
                                    class="{{ $chip }} bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300"
                                >
                                    <x-filament::icon
                                        icon="heroicon-m-shield-check"
                                        class="size-3.5"
                                    />
                                    {{ __('capell-marketplace::marketplace.detail.security_reviewed') }}
                                </span>
                            @endif

                            <span
                                class="{{ $chip }}"
                                >{{ $detail->maturityLabel }}</span
                            >
                        </div>
                    </div>
                </header>

                {{-- Install panel: price, the install decision and every action
                     that changes what is installed on this site. --}}
                <aside
                    class="lg:sticky lg:top-20 lg:col-start-2 lg:row-span-2 lg:row-start-1"
                >
                    <div
                        class="{{ $panel }} divide-y divide-gray-950/5 dark:divide-white/10"
                    >
                        <div class="space-y-1 p-5">
                            <h2 class="sr-only">
                                {{ __('capell-marketplace::marketplace.detail.install_panel_heading') }}
                            </h2>
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ __('capell-marketplace::marketplace.card.price_label') }}
                            </p>
                            <p class="text-3xl font-semibold tracking-tight text-gray-950 tabular-nums dark:text-white">
                                {{ $this->priceLabel() }}
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                {{ __('capell-marketplace::marketplace.detail.licence_status', ['status' => $this->licenceStatusLabel()]) }}
                            </p>
                        </div>

                        <section
                            class="space-y-4 p-5"
                            aria-labelledby="marketplace-install-decision-heading"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <h3
                                    id="marketplace-install-decision-heading"
                                    class="text-sm font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.can_install_heading') }}
                                </h3>
                                <x-filament::badge
                                    :color="$this->canInstall() ? 'success' : 'gray'"
                                    :icon="$this->canInstall() ? 'heroicon-m-check-circle' : 'heroicon-m-no-symbol'"
                                >
                                    {{ $this->installDecisionLabel() }}
                                </x-filament::badge>
                            </div>

                            @unless ($this->canInstall())
                                <div class="space-y-1">
                                    <h4
                                        class="text-xs font-medium text-gray-500 dark:text-gray-400"
                                    >
                                        {{ __('capell-marketplace::marketplace.detail.why_not_heading') }}
                                    </h4>
                                    <p
                                        class="{{ $muted }}"
                                    >{{ $this->installDecisionReason() }}</p>
                                </div>
                            @endunless

                            <div class="space-y-3">
                                <h4
                                    class="text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.what_next_heading') }}
                                </h4>
                                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $this->nextActionLabel() }}</p>

                                @if (! $this->canInstall() && $this->connectMarketplaceAccountAction->isVisible())
                                    <p
                                        class="{{ $muted }}"
                                    >{{ __('capell-marketplace::marketplace.detail.connect_account_body') }}</p>
                                    <div
                                        class="[&>*]:w-full"
                                        data-capell-marketplace-connect-account
                                    >
                                        {{ $this->connectMarketplaceAccountAction }}
                                    </div>
                                @endif

                                <div class="flex flex-col gap-2">
                                    @if ($this->shouldVerifySite())
                                        <x-filament::button
                                            tag="a"
                                            color="warning"
                                            icon="heroicon-o-arrow-top-right-on-square"
                                            href="{{ $this->marketplaceUrl() }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {{ __('capell-marketplace::marketplace.detail.verify_site_cta') }}
                                        </x-filament::button>
                                    @endif

                                    @if ($this->canInstall())
                                        <x-filament::button
                                            tag="a"
                                            size="lg"
                                            icon="heroicon-o-arrow-down-tray"
                                            href="{{ $this->marketplaceUrl() }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {{ __('capell-marketplace::marketplace.detail.install_available') }}
                                        </x-filament::button>
                                    @endif

                                    @if ($this->canDownload())
                                        <x-filament::button
                                            tag="a"
                                            color="gray"
                                            outlined
                                            icon="heroicon-o-cloud-arrow-down"
                                            href="{{ $this->marketplaceUrl() }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {{ __('capell-marketplace::marketplace.detail.download_available') }}
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                        </section>

                        @if ($this->canFinishInstall())
                            <div
                                data-capell-marketplace-finish-install
                                class="bg-primary-50/60 dark:bg-primary-500/10 space-y-3 p-5 text-sm"
                            >
                                <p class="font-semibold text-gray-950 dark:text-white">
                                    {{ __('capell-marketplace::marketplace.detail.finish_install_heading', ['name' => $detail->name]) }}
                                </p>
                                <p class="text-gray-700 dark:text-gray-200">
                                    {{ __('capell-marketplace::marketplace.detail.finish_install_body') }}
                                </p>
                                <x-filament::button
                                    class="w-full"
                                    wire:click="finishInstall"
                                    wire:loading.attr="disabled"
                                    wire:target="finishInstall"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.finish_install_button') }}
                                </x-filament::button>
                            </div>
                        @endif

                        @if ($this->canUpdate())
                            {{-- The confirmation carries the release notes, because
                                 consenting to an unseen change is not consent. --}}
                            <div
                                data-capell-marketplace-update-available="{{ $detail->composerName }}"
                                class="space-y-3 bg-warning-50/70 p-5 text-sm dark:bg-warning-500/10"
                            >
                                <p class="font-semibold text-warning-950 dark:text-warning-100">
                                    {{ __('capell-marketplace::marketplace.updates.confirm_heading', ['name' => $detail->name]) }}
                                </p>
                                <p class="text-warning-800 dark:text-warning-200">
                                    {{ __('capell-marketplace::marketplace.updates.confirm_body', ['version' => $detail->latestVersion]) }}
                                </p>

                                <div data-capell-marketplace-update-changelog>
                                    <p class="font-semibold text-warning-950 dark:text-warning-100">
                                        {{ __('capell-marketplace::marketplace.updates.changelog_heading') }}
                                    </p>

                                    @forelse ($this->updateChangelog() as $release)
                                        <div
                                            data-capell-marketplace-update-changelog-entry="{{ $release['version'] }}"
                                            data-capell-marketplace-update-release-kind="{{ $release['kind'] }}"
                                            class="mt-2"
                                        >
                                            <p class="font-medium text-warning-950 dark:text-warning-100">
                                                {{ __('capell-marketplace::marketplace.updates.changelog_version', ['version' => $release['version']]) }}
                                            </p>
                                            <p class="text-warning-800 dark:text-warning-200">{{ $release['notes'] !== '' ? $release['notes'] : __('capell-marketplace::marketplace.updates.changelog_empty') }}</p>
                                        </div>
                                    @empty
                                        <p data-capell-marketplace-update-changelog-empty class="mt-2 text-warning-800 dark:text-warning-200">
                                            {{ __('capell-marketplace::marketplace.updates.changelog_empty') }}
                                        </p>
                                    @endforelse
                                </div>

                                <x-filament::button
                                    color="warning"
                                    class="w-full"
                                    wire:click="updateExtension"
                                    wire:loading.attr="disabled"
                                    wire:target="updateExtension"
                                    data-capell-marketplace-update-submit="{{ $detail->composerName }}"
                                >
                                    {{ __('capell-marketplace::marketplace.updates.confirm_submit') }}
                                </x-filament::button>
                            </div>
                        @endif

                        @if ($this->requiresLicenceKey())
                            <form
                                wire:submit="activateLicence"
                                class="space-y-3 p-5"
                                data-capell-marketplace-licence-form
                            >
                                <label class="block space-y-1 text-sm">
                                    <span
                                        class="font-semibold text-gray-950 dark:text-white"
                                    >
                                        {{ __('capell-marketplace::marketplace.install.license_key_label') }}
                                    </span>
                                    <x-filament::input.wrapper
                                        :valid="! $errors->has('licenseKey')"
                                    >
                                        <x-filament::input
                                            type="password"
                                            wire:model="licenseKey"
                                            autocomplete="off"
                                            data-capell-marketplace-licence-key
                                        />
                                    </x-filament::input.wrapper>
                                    <span
                                        class="block text-gray-600 dark:text-gray-300"
                                    >
                                        {{ __('capell-marketplace::marketplace.install.license_key_help') }}
                                    </span>
                                    @error('licenseKey')
                                        <span
                                            class="block text-sm text-danger-600 dark:text-danger-400"
                                            data-capell-marketplace-licence-error
                                            >{{ $message }}</span
                                        >
                                    @enderror
                                </label>

                                <x-filament::button
                                    type="submit"
                                    class="w-full"
                                    wire:loading.attr="disabled"
                                    wire:target="activateLicence"
                                >
                                    {{ __('capell-marketplace::marketplace.install.license_key_submit') }}
                                </x-filament::button>
                            </form>
                        @endif

                        @if ($this->requiresManualInstallInstructions())
                            {{-- This host cannot install for the operator, so the commands
                                 they must run are the primary action rather than an
                                 optional disclosure below it. --}}
                            <div
                                data-capell-marketplace-manual-install-cta
                                data-capell-marketplace-readiness-capability="{{ $this->environmentReadiness()->capability->value }}"
                                class="space-y-3 bg-warning-50/70 p-5 text-sm dark:bg-warning-500/10"
                            >
                                <p class="font-semibold text-warning-950 dark:text-warning-100">
                                    {{ __('capell-marketplace::marketplace.readiness.manual_install_cta_heading') }}
                                </p>
                                <p class="text-warning-800 dark:text-warning-200">
                                    {{ __('capell-marketplace::marketplace.readiness.manual_install_cta_body') }}
                                </p>

                                @unless ($showManualInstallCommands)
                                    <x-filament::button
                                        color="warning"
                                        class="w-full"
                                        wire:click="showManualInstallInstructions"
                                    >
                                        {{ __('capell-marketplace::marketplace.readiness.manual_install_cta_button') }}
                                    </x-filament::button>
                                @endunless
                            </div>
                        @endif

                        @if ($manualInstallCommands !== [])
                            <div class="p-5">
                                <button
                                    type="button"
                                    wire:click="$toggle('showManualInstallCommands')"
                                    aria-expanded="{{ $showManualInstallCommands ? 'true' : 'false' }}"
                                    aria-controls="marketplace-manual-install-commands"
                                    class="group flex w-full items-start justify-between gap-3 rounded-md text-start focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-600"
                                >
                                    <span>
                                        <span
                                            class="block text-sm font-medium text-gray-950 dark:text-white"
                                        >
                                            {{ $showManualInstallCommands
                                                ? __('capell-marketplace::marketplace.detail.manual_install_toggle_hide')
                                                : __('capell-marketplace::marketplace.detail.manual_install_checkbox_label') }}
                                        </span>
                                        <span
                                            class="block text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-marketplace::marketplace.detail.manual_install_toggle_body') }}
                                        </span>
                                    </span>
                                    <x-filament::icon
                                        icon="heroicon-m-chevron-down"
                                        class="{{ $showManualInstallCommands ? 'rotate-180' : '' }} mt-0.5 size-5 shrink-0 text-gray-400 transition-transform duration-200"
                                    />
                                </button>

                                @if ($showManualInstallCommands)
                                    <div
                                        id="marketplace-manual-install-commands"
                                        class="mt-4 space-y-4"
                                        data-capell-marketplace-manual-install-commands
                                    >
                                        <div class="space-y-2">
                                            <h3
                                                class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                            >
                                                {{ __('capell-marketplace::marketplace.detail.manual_composer_heading') }}
                                            </h3>
                                            <pre
                                                class="overflow-x-auto rounded-lg bg-gray-950 p-3 text-xs leading-5 text-gray-100 ring-1 ring-white/10"
                                            ><code>{{ $manualInstallCommands['composer'] }}</code></pre>
                                        </div>

                                        <div class="space-y-2">
                                            <h3
                                                class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                            >
                                                {{ __('capell-marketplace::marketplace.detail.manual_install_command_heading') }}
                                            </h3>
                                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                                {{ __('capell-marketplace::marketplace.detail.manual_finish_install_body') }}
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ __('capell-marketplace::marketplace.detail.manual_finish_install_cli') }}
                                            </p>
                                            <pre
                                                class="overflow-x-auto rounded-lg bg-gray-950 p-3 text-xs leading-5 text-gray-100 ring-1 ring-white/10"
                                            ><code>{{ $manualInstallCommands['install'] }}</code></pre>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <dl class="space-y-3 p-5 text-sm">
                            @if ($detail->latestVersion !== null)
                                <div class="flex justify-between gap-3">
                                    <dt
                                        class="text-gray-500 dark:text-gray-400"
                                    >
                                        {{ __('capell-marketplace::marketplace.card.version_label') }}
                                    </dt>
                                    <dd
                                        class="font-medium text-gray-950 tabular-nums dark:text-white"
                                    >
                                        {{ $detail->latestVersion }}
                                    </dd>
                                </div>
                            @endif
                            @foreach ($compatibility as $platform => $constraint)
                                <div class="flex justify-between gap-3">
                                    <dt
                                        class="text-gray-500 dark:text-gray-400"
                                    >
                                        {{ $platform }}
                                    </dt>
                                    <dd
                                        class="font-mono text-xs text-gray-950 dark:text-white"
                                    >
                                        {{ $constraint }}
                                    </dd>
                                </div>
                            @endforeach
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500 dark:text-gray-400">
                                    {{ __('capell-marketplace::marketplace.detail.dependencies_heading') }}
                                </dt>
                                <dd
                                    class="font-medium text-gray-950 tabular-nums dark:text-white"
                                >
                                    {{ count($detail->requiredDependencies) }}
                                </dd>
                            </div>
                        </dl>

                        @if ($detail->documentationUrl !== null)
                            <div
                                class="space-y-3 p-5"
                                data-capell-marketplace-extension-docs
                            >
                                <h3
                                    class="text-sm font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.docs_link_heading') }}
                                </h3>
                                <p
                                    class="{{ $muted }}"
                                >{{ __('capell-marketplace::marketplace.detail.docs_link_body') }}</p>
                                <x-filament::button
                                    tag="a"
                                    color="gray"
                                    size="sm"
                                    icon="heroicon-o-book-open"
                                    href="{{ $detail->documentationUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.docs_link_cta') }}
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                </aside>

                <div class="min-w-0 space-y-10 lg:col-start-1">
                    <nav
                        aria-label="{{ __('capell-marketplace::marketplace.detail.section_nav_label') }}"
                        class="-mx-1 flex gap-1 overflow-x-auto border-b border-gray-950/10 px-1 dark:border-white/10"
                    >
                        @foreach ($sections as $anchor => $label)
                            <a
                                href="#{{ $anchor }}"
                                class="-mb-px shrink-0 border-b-2 border-transparent px-3 py-2.5 text-sm font-medium whitespace-nowrap text-gray-600 transition-colors hover:border-gray-300 hover:text-gray-950 focus-visible:outline-2 focus-visible:outline-primary-600 dark:text-gray-300 dark:hover:border-white/30 dark:hover:text-white"
                            >
                                {{ $label }}
                            </a>
                        @endforeach
                    </nav>

                    <section
                        id="overview"
                        class="scroll-mt-24 space-y-5"
                    >
                        <h2 class="{{ $sectionHeading }}">
                            {{ __('capell-marketplace::marketplace.detail.overview_heading') }}
                        </h2>
                        <p class="max-w-prose text-base leading-7 text-gray-700 dark:text-gray-200">
                            {{ $detail->description ?? $detail->summary ?? __('capell-marketplace::marketplace.card.no_description') }}
                        </p>

                        @if ($suite !== null)
                            <div
                                class="{{ $panel }} space-y-4 p-5"
                                data-capell-marketplace-suite="{{ $suite['bundle'] }}"
                            >
                                <div>
                                    <h3
                                        class="text-base font-semibold text-gray-950 dark:text-white"
                                    >
                                        {{ __('capell-marketplace::marketplace.suites.heading') }}
                                    </h3>
                                    <p
                                        class="mt-1 {{ $muted }}"
                                    >{{ __('capell-marketplace::marketplace.suites.description') }}</p>
                                </div>

                                <ul
                                    class="divide-y divide-gray-950/5 dark:divide-white/10"
                                >
                                    @foreach ($suite['members'] as $member)
                                        <li
                                            class="flex items-center justify-between gap-3 py-2 text-sm"
                                            data-capell-marketplace-suite-member="{{ $member['composer_name'] }}"
                                        >
                                            <span
                                                class="text-gray-950 dark:text-white"
                                                >{{ $member['name'] }}</span
                                            >
                                            @if ($member['price'] !== null)
                                                <span
                                                    class="text-gray-600 tabular-nums dark:text-gray-300"
                                                    >{{ $member['price'] }}</span
                                                >
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>

                                <div
                                    class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-700 tabular-nums dark:text-gray-200"
                                >
                                    <p>{{ __('capell-marketplace::marketplace.suites.combined_price', ['price' => $suite['combined_price']]) }}</p>
                                    @if ($suite['member_total'] !== null)
                                        <p>{{ __('capell-marketplace::marketplace.suites.member_total', ['price' => $suite['member_total']]) }}</p>
                                    @endif
                                    @if ($suite['savings'] !== null)
                                        <p class="font-semibold text-success-700 dark:text-success-300" data-capell-marketplace-suite-savings>
                                            {{ __('capell-marketplace::marketplace.suites.savings', ['price' => $suite['savings']]) }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if ($detail->trial !== [])
                            <div
                                class="{{ $panel }} p-5"
                                data-capell-marketplace-trial
                            >
                                <h3
                                    class="text-base font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ data_get($detail->trial, 'label', __('capell-marketplace::marketplace.suites.trial_heading')) }}
                                </h3>
                                @if (is_string(data_get($detail->trial, 'description')))
                                    <p
                                        class="mt-1 {{ $muted }}"
                                    >{{ data_get($detail->trial, 'description') }}</p>
                                @endif
                                @if (is_numeric(data_get($detail->trial, 'days', data_get($detail->trial, 'duration_days'))))
                                    <p class="mt-1 {{ $muted }}">
                                        {{ trans_choice('capell-marketplace::marketplace.suites.trial_days', (int) data_get($detail->trial, 'days', data_get($detail->trial, 'duration_days')), ['count' => (int) data_get($detail->trial, 'days', data_get($detail->trial, 'duration_days'))]) }}
                                    </p>
                                @endif
                            </div>
                        @endif

                        @if ($this->publicDocumentation() !== [])
                            <div class="space-y-3">
                                <h3
                                    class="text-base font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.documentation_heading') }}
                                </h3>
                                <div class="grid gap-3 md:grid-cols-2">
                                    @foreach ($this->publicDocumentation() as $document)
                                        <article class="{{ $panel }} p-4">
                                            <h4
                                                class="font-semibold text-gray-950 dark:text-white"
                                            >
                                                {{ $document['title'] ?? __('capell-marketplace::marketplace.detail.documentation_item') }}
                                            </h4>
                                            @if (is_string($document['body'] ?? null))
                                                <p
                                                    class="mt-2 {{ $muted }}"
                                                >{{ $document['body'] }}</p>
                                            @endif
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>

                    @if ($screenshots->isNotEmpty())
                        <section
                            id="screenshots"
                            class="scroll-mt-24 space-y-4"
                            aria-labelledby="marketplace-extension-screenshots-heading"
                            data-capell-marketplace-extension-screenshots
                        >
                            <div
                                class="flex items-baseline justify-between gap-3"
                            >
                                <h2
                                    id="marketplace-extension-screenshots-heading"
                                    class="{{ $sectionHeading }}"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.screenshots_heading') }}
                                </h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ trans_choice('capell-marketplace::marketplace.detail.screenshots_count', $screenshots->count(), ['count' => $screenshots->count()]) }}
                                </p>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($screenshots as $image)
                                    @php
                                        $isFeaturedScreenshot = $loop->first;
                                        $caption = is_string($image['caption'] ?? null)
                                            ? $image['caption']
                                            : (is_string($image['title'] ?? null) ? $image['title'] : null);
                                    @endphp

                                    <figure
                                        class="{{ $isFeaturedScreenshot ? 'sm:col-span-2' : '' }} {{ $panel }} overflow-hidden"
                                    >
                                        <a
                                            href="{{ $image['url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                                        >
                                            <img
                                                src="{{ $image['url'] }}"
                                                alt="{{ is_string($image['alt'] ?? null) ? $image['alt'] : __('capell-marketplace::marketplace.card.image_alt', ['name' => $detail->name]) }}"
                                                class="{{ $isFeaturedScreenshot ? 'aspect-[16/9]' : 'aspect-[16/10]' }} w-full bg-gray-100 object-cover dark:bg-gray-800"
                                                loading="{{ $isFeaturedScreenshot ? 'eager' : 'lazy' }}"
                                            />
                                        </a>
                                        @if ($caption !== null)
                                            <figcaption
                                                class="border-t border-gray-950/5 px-4 py-2.5 text-sm text-gray-600 dark:border-white/10 dark:text-gray-300"
                                            >
                                                {{ $caption }}
                                            </figcaption>
                                        @endif
                                    </figure>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($detail->versionHistory !== [])
                        <section
                            id="changelog"
                            class="scroll-mt-24 space-y-4"
                        >
                            <h2 class="{{ $sectionHeading }}">
                                {{ __('capell-marketplace::marketplace.detail.version_history_heading') }}
                            </h2>
                            <ol
                                class="space-y-0 border-s border-gray-950/10 dark:border-white/10"
                            >
                                @foreach ($detail->versionHistory as $version)
                                    <li class="relative ps-6 pb-6 last:pb-0">
                                        <span
                                            aria-hidden="true"
                                            class="{{ $loop->first ? 'bg-primary-600 ring-primary-600/20' : 'bg-gray-300 ring-gray-950/5 dark:bg-gray-600 dark:ring-white/10' }} absolute -start-1.5 top-1.5 size-3 rounded-full ring-4"
                                        ></span>
                                        <p class="text-sm font-semibold text-gray-950 tabular-nums dark:text-white">
                                            {{ $version['version'] ?? __('capell-marketplace::marketplace.card.unknown_version') }}
                                        </p>
                                        <p
                                            class="mt-1 max-w-prose {{ $muted }}"
                                        >{{ resolve(\Capell\Marketplace\Filament\Support\MarketplaceUpdateChangelogPresenter::class)->releaseText($version) ?: __('capell-marketplace::marketplace.updates.changelog_empty') }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endif

                    <section
                        id="reviews"
                        class="scroll-mt-24 space-y-4"
                    >
                        <h2 class="{{ $sectionHeading }}">
                            {{ __('capell-marketplace::marketplace.detail.reviews_heading') }}
                        </h2>

                        @if ($ratingAverage !== null && $ratingsCount > 0)
                            <div class="flex items-center gap-4">
                                <p class="text-4xl font-semibold tracking-tight text-gray-950 tabular-nums dark:text-white">{{ number_format($ratingAverage, 1) }}</p>
                                <div
                                    class="text-sm text-gray-600 dark:text-gray-300"
                                >
                                    <p>{{ __('capell-marketplace::marketplace.detail.rating_label', ['rating' => $ratingAverage]) }}</p>
                                    <p>
                                        {{ trans_choice('capell-marketplace::marketplace.detail.ratings_count', $ratingsCount, ['count' => $ratingsCount]) }}
                                        @if ($this->commentsCount() > 0)
                                            · {{ trans_choice('capell-marketplace::marketplace.detail.comments_count', $this->commentsCount(), ['count' => $this->commentsCount()]) }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @else
                            <p
                                class="{{ $muted }}"
                            >{{ __('capell-marketplace::marketplace.detail.reviews_empty') }}</p>
                        @endif

                        @if ($this->canSubmitFeedback())
                            <div class="{{ $panel }} p-5">
                                <h3
                                    class="text-base font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.feedback.heading') }}
                                </h3>

                                @if ($feedbackStatus !== null)
                                    <p role="status" aria-live="polite" class="mt-2 text-sm font-medium text-success-700 dark:text-success-300">
                                        {{ __('capell-marketplace::marketplace.feedback.status', ['status' => $feedbackStatus]) }}
                                    </p>
                                @endif

                                <form
                                    wire:submit="submitFeedback"
                                    class="mt-4 space-y-4"
                                >
                                    @if ($this->canRate())
                                        <label class="block space-y-1">
                                            <span
                                                class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                            >
                                                {{ __('capell-marketplace::marketplace.feedback.rating_label') }}
                                                @if ($this->ratingIsRequired())
                                                    <span
                                                        class="text-danger-600 dark:text-danger-400"
                                                        >{{ __('capell-marketplace::marketplace.feedback.required_suffix') }}</span
                                                    >
                                                @endif
                                            </span>
                                            <input
                                                id="feedback-rating"
                                                type="number"
                                                min="1"
                                                max="5"
                                                @if ($this->ratingIsRequired())
                                                    required
                                                    aria-required="true"
                                                @endif
                                                wire:model="feedbackRating"
                                                aria-invalid="@error('feedbackRating') true @else false @enderror"
                                                @error('feedbackRating')
                                                    aria-describedby="feedback-rating-error"
                                                @enderror
                                                class="block w-full max-w-32 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                                            />
                                            @error('feedbackRating')
                                                <span
                                                    id="feedback-rating-error"
                                                    class="text-sm text-danger-600 dark:text-danger-400"
                                                    >{{ $message }}</span
                                                >
                                            @enderror
                                        </label>
                                    @endif

                                    @if ($this->canComment())
                                        <label class="block space-y-1">
                                            <span
                                                class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                                >{{ __('capell-marketplace::marketplace.feedback.comment_label') }}</span
                                            >
                                            <textarea
                                                id="feedback-comment"
                                                rows="4"
                                                wire:model="feedbackComment"
                                                aria-invalid="@error('feedbackComment') true @else false @enderror"
                                                @error('feedbackComment')
                                                    aria-describedby="feedback-comment-error"
                                                @enderror
                                                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                                            ></textarea>
                                            @error('feedbackComment')
                                                <span
                                                    id="feedback-comment-error"
                                                    class="text-sm text-danger-600 dark:text-danger-400"
                                                    >{{ $message }}</span
                                                >
                                            @enderror
                                        </label>

                                        <label class="block space-y-1">
                                            <span
                                                class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                                >{{ __('capell-marketplace::marketplace.feedback.tip_label') }}</span
                                            >
                                            <textarea
                                                id="feedback-tip"
                                                rows="3"
                                                wire:model="feedbackTip"
                                                aria-invalid="@error('feedbackTip') true @else false @enderror"
                                                @error('feedbackTip')
                                                    aria-describedby="feedback-tip-error"
                                                @enderror
                                                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                                            ></textarea>
                                            @error('feedbackTip')
                                                <span
                                                    id="feedback-tip-error"
                                                    class="text-sm text-danger-600 dark:text-danger-400"
                                                    >{{ $message }}</span
                                                >
                                            @enderror
                                        </label>
                                    @endif

                                    <x-filament::button type="submit">
                                        {{ __('capell-marketplace::marketplace.feedback.submit') }}
                                    </x-filament::button>
                                </form>
                            </div>
                        @endif
                    </section>

                    <section
                        id="requirements"
                        class="scroll-mt-24 space-y-4"
                    >
                        <div>
                            <h2 class="{{ $sectionHeading }}">
                                {{ __('capell-marketplace::marketplace.detail.requirements_heading') }}
                            </h2>
                            <p
                                class="mt-1 max-w-prose {{ $muted }}"
                            >{{ __('capell-marketplace::marketplace.detail.requirements_body') }}</p>
                        </div>

                        <dl
                            class="{{ $panel }} grid divide-y divide-gray-950/5 text-sm sm:grid-cols-2 sm:divide-x sm:divide-y-0 dark:divide-white/10"
                        >
                            <div class="p-5">
                                <dt
                                    class="font-medium text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.compatibility_heading') }}
                                </dt>
                                <dd
                                    class="mt-2 text-gray-600 dark:text-gray-300"
                                >
                                    @if ($compatibility !== [])
                                        <ul class="space-y-1">
                                            @foreach ($compatibility as $platform => $constraint)
                                                <li
                                                    class="flex justify-between gap-3"
                                                >
                                                    <span>{{ $platform }}</span>
                                                    <span
                                                        class="font-mono text-xs text-gray-950 dark:text-white"
                                                        >{{ $constraint }}</span
                                                    >
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        {{ __('capell-marketplace::marketplace.detail.no_requirements') }}
                                    @endif
                                </dd>
                            </div>
                            <div class="p-5">
                                <dt
                                    class="font-medium text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.dependencies_heading') }}
                                </dt>
                                <dd class="mt-2">
                                    @if ($detail->requiredDependencies !== [])
                                        <ul class="flex flex-wrap gap-2">
                                            @foreach ($detail->requiredDependencies as $dependency)
                                                <li
                                                    class="rounded-md bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 dark:bg-white/10 dark:text-gray-200"
                                                >
                                                    {{ $dependency }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <span
                                            class="text-gray-600 dark:text-gray-300"
                                            >—</span
                                        >
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section
                        id="permissions"
                        class="scroll-mt-24 space-y-4"
                    >
                        <div>
                            <h2 class="{{ $sectionHeading }}">
                                {{ __('capell-marketplace::marketplace.detail.permissions_heading') }}
                            </h2>
                            <p
                                class="mt-1 max-w-prose {{ $muted }}"
                            >{{ __('capell-marketplace::marketplace.detail.permissions_body') }}</p>
                        </div>

                        <div class="{{ $panel }} space-y-5 p-5">
                            <div class="space-y-2">
                                <h3
                                    class="text-sm font-medium text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.contract_heading') }}
                                </h3>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ([
                                        $this->stateLabel($detail->productTier),
                                        $this->stateLabel($detail->effectiveCertification),
                                        $this->stateLabel($detail->supportPolicy),
                                        $this->stateLabel($detail->healthStatus),
                                    ] as $badge)
                                        @if ($badge !== null)
                                            <span
                                                class="{{ $chip }}"
                                                >{{ $badge }}</span
                                            >
                                        @endif
                                    @endforeach

                                    @if ($detail->privateDocsEntitled)
                                        <span
                                            class="{{ $chip }}"
                                            >{{ __('capell-marketplace::marketplace.detail.private_docs_available') }}</span
                                        >
                                    @endif

                                    @if ($this->frontendRenderBudgetLabel() !== null)
                                        <span class="{{ $chip }}">
                                            <x-filament::icon
                                                icon="heroicon-m-bolt"
                                                class="size-3.5"
                                            />
                                            {{ $this->frontendRenderBudgetLabel() }}
                                        </span>
                                    @endif

                                    <span class="{{ $chip }}">
                                        {{ trans_choice('capell-marketplace::marketplace.detail.contribution_count', $this->contributionCount(), ['count' => $this->contributionCount()]) }}
                                    </span>
                                </div>
                            </div>

                            @if ($detail->surfaces !== [])
                                <div class="space-y-2">
                                    <h3
                                        class="text-sm font-medium text-gray-950 dark:text-white"
                                    >
                                        {{ __('capell-marketplace::marketplace.detail.surfaces_heading') }}
                                    </h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">
                                        {{ collect($detail->surfaces)->map(fn (string $surface): ?string => $this->stateLabel($surface))->filter()->implode(', ') }}
                                    </p>
                                </div>
                            @endif

                            <div
                                class="space-y-1 border-t border-gray-950/5 pt-4 dark:border-white/10"
                                data-capell-marketplace-extension-access
                            >
                                <h3
                                    class="text-sm font-medium text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.access_heading') }}
                                </h3>
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('capell-marketplace::marketplace.detail.access_body') }}</p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
