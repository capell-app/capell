<x-filament-panels::page>
    @php
        $detail = $this->detail();
    @endphp

    @if ($this->detailLoadError !== null)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('capell-marketplace::marketplace.detail.unavailable_heading') }}
            </x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ $this->detailLoadError }}
            </p>
        </x-filament::section>
    @elseif ($detail)
        @php
            $screenshots = collect($detail->images)
                ->filter(fn (array $image): bool => is_string($image['url'] ?? null))
                ->values();
        @endphp

        <div class="space-y-6">
            @include('capell-marketplace::filament.pages.partials.extension-health-alerts', [
                'alerts' => $this->criticalHealthAlerts(),
            ])

            <section class="space-y-4">
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                >
                    <div class="max-w-3xl space-y-3">
                        <p
                            class="text-sm font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400"
                        >
                            {{ $this->compatibilityLabel() }}
                        </p>
                        <h1
                            class="text-2xl font-semibold text-gray-950 dark:text-white"
                        >
                            {{ $detail->name }}
                        </h1>
                        <p
                            class="text-base leading-7 text-gray-600 dark:text-gray-300"
                        >
                            {{ $detail->description ?? $detail->summary ?? __('capell-marketplace::marketplace.card.no_description') }}
                        </p>
                    </div>

                    <div
                        class="min-w-56 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                    >
                        <p
                            class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400"
                        >
                            {{ __('capell-marketplace::marketplace.card.price_label') }}
                        </p>
                        <p
                            class="mt-1 text-xl font-semibold text-gray-950 dark:text-white"
                        >
                            {{ $this->priceLabel() }}
                        </p>
                        <p
                            class="mt-3 text-sm text-gray-600 dark:text-gray-300"
                        >
                            {{ __('capell-marketplace::marketplace.detail.licence_status', ['status' => $this->licenceStatusLabel()]) }}
                        </p>
                    </div>
                </div>

                @if ($screenshots->isNotEmpty())
                    <section
                        class="space-y-3"
                        aria-labelledby="marketplace-extension-screenshots-heading"
                        data-marketplace-extension-screenshots
                    >
                        <div
                            class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between"
                        >
                            <div>
                                <h2
                                    id="marketplace-extension-screenshots-heading"
                                    class="text-base font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.screenshots_heading') }}
                                </h2>
                                <p
                                    class="mt-1 text-sm text-gray-600 dark:text-gray-300"
                                >
                                    {{ trans_choice('capell-marketplace::marketplace.detail.screenshots_count', $screenshots->count(), ['count' => $screenshots->count()]) }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-3 lg:grid-cols-4">
                            @foreach ($screenshots as $image)
                                @php
                                    $isFeaturedScreenshot = $loop->first;
                                    $caption = is_string($image['caption'] ?? null)
                                        ? $image['caption']
                                        : (is_string($image['title'] ?? null) ? $image['title'] : null);
                                @endphp

                                <figure
                                    class="{{ $isFeaturedScreenshot ? 'lg:col-span-2 lg:row-span-2' : '' }} overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900"
                                >
                                    <img
                                        src="{{ $image['url'] }}"
                                        alt="{{ is_string($image['alt'] ?? null) ? $image['alt'] : __('capell-marketplace::marketplace.card.image_alt', ['name' => $detail->name]) }}"
                                        class="{{ $isFeaturedScreenshot ? 'aspect-[16/10]' : 'aspect-video' }} w-full object-cover"
                                        loading="{{ $isFeaturedScreenshot ? 'eager' : 'lazy' }}"
                                    />

                                    @if ($caption !== null)
                                        <figcaption
                                            class="border-t border-gray-100 px-3 py-2 text-sm text-gray-600 dark:border-white/10 dark:text-gray-300"
                                        >
                                            {{ $caption }}
                                        </figcaption>
                                    @endif
                                </figure>
                            @endforeach
                        </div>
                    </section>
                @elseif ($detail->imageUrl !== null)
                    <img
                        src="{{ $detail->imageUrl }}"
                        alt="{{ __('capell-marketplace::marketplace.card.image_alt', ['name' => $detail->name]) }}"
                        class="aspect-video rounded-lg border border-gray-200 object-cover shadow-sm dark:border-white/10"
                    />
                @endif
            </section>

            @if ($detail->documentationUrl !== null)
                <section
                    class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                    data-marketplace-extension-docs
                >
                    <h2
                        class="text-base font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-marketplace::marketplace.detail.docs_link_heading') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {{ __('capell-marketplace::marketplace.detail.docs_link_body') }}
                    </p>
                    <div class="mt-4">
                        <x-filament::button
                            tag="a"
                            size="sm"
                            icon="heroicon-o-book-open"
                            href="{{ $detail->documentationUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ __('capell-marketplace::marketplace.detail.docs_link_cta') }}
                        </x-filament::button>
                    </div>
                </section>
            @endif

            <section
                class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                aria-labelledby="marketplace-install-decision-heading"
            >
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <h2
                            id="marketplace-install-decision-heading"
                            class="text-sm font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.detail.can_install_heading') }}
                        </h2>
                        <p
                            class="mt-1 text-lg font-semibold text-gray-950 dark:text-white"
                        >
                            {{ $this->installDecisionLabel() }}
                        </p>
                    </div>

                    <div>
                        <h3
                            class="text-sm font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.detail.why_not_heading') }}
                        </h3>
                        <p
                            class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300"
                        >
                            {{ $this->installDecisionReason() }}
                        </p>
                    </div>

                    <div>
                        <h3
                            class="text-sm font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.detail.what_next_heading') }}
                        </h3>
                        <p
                            class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300"
                        >
                            {{ $this->nextActionLabel() }}
                        </p>
                    </div>
                </div>
            </section>

            @php
                $manualInstallCommands = $this->manualInstallCommands();
            @endphp

            @if ($manualInstallCommands !== [])
                <div class="space-y-3">
                    <label
                        class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300"
                    >
                        <x-filament::input.checkbox
                            wire:model.live="showManualInstallCommands"
                        />

                        <span>
                            {{ __('capell-marketplace::marketplace.detail.manual_install_checkbox_label') }}
                        </span>
                    </label>

                    @if ($showManualInstallCommands)
                        <div
                            class="grid gap-3 md:grid-cols-2"
                            data-marketplace-manual-install-commands
                        >
                            <div class="space-y-2">
                                <h3
                                    class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.manual_composer_heading') }}
                                </h3>
                                <pre
                                    class="overflow-x-auto rounded-md bg-gray-950 p-3 text-sm text-white"
                                ><code>{{ $manualInstallCommands['composer'] }}</code></pre>
                            </div>

                            <div class="space-y-2">
                                <h3
                                    class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.manual_install_command_heading') }}
                                </h3>
                                <pre
                                    class="overflow-x-auto rounded-md bg-gray-950 p-3 text-sm text-white"
                                ><code>{{ $manualInstallCommands['install'] }}</code></pre>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <section class="space-y-3">
                <h2
                    class="text-base font-semibold text-gray-950 dark:text-white"
                >
                    {{ __('capell-marketplace::marketplace.detail.contract_heading') }}
                </h2>

                <div class="flex flex-wrap gap-2 text-sm">
                    @foreach ([
                                  $this->stateLabel($detail->productTier),
                                  $this->stateLabel($detail->effectiveCertification),
                                  $this->stateLabel($detail->supportPolicy),
                                  $this->stateLabel($detail->healthStatus),
                              ] as $badge)
                        @if ($badge !== null)
                            <span
                                class="rounded-md bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200"
                            >
                                {{ $badge }}
                            </span>
                        @endif
                    @endforeach

                    @if ($detail->privateDocsEntitled)
                        <span
                            class="rounded-md bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200"
                        >
                            {{ __('capell-marketplace::marketplace.detail.private_docs_available') }}
                        </span>
                    @endif

                    @if ($this->frontendRenderBudgetLabel() !== null)
                        <span
                            class="rounded-md bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200"
                        >
                            {{ $this->frontendRenderBudgetLabel() }}
                        </span>
                    @endif

                    <span
                        class="rounded-md bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200"
                    >
                        {{ trans_choice('capell-marketplace::marketplace.detail.contribution_count', $this->contributionCount(), ['count' => $this->contributionCount()]) }}
                    </span>
                </div>

                @if ($detail->surfaces !== [] || $detail->requiredDependencies !== [])
                    <dl class="grid gap-3 text-sm md:grid-cols-2">
                        @if ($detail->surfaces !== [])
                            <div>
                                <dt
                                    class="font-medium text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.surfaces_heading') }}
                                </dt>
                                <dd
                                    class="mt-1 text-gray-600 dark:text-gray-300"
                                >
                                    {{ collect($detail->surfaces)->map(fn (string $surface): ?string => $this->stateLabel($surface))->filter()->implode(', ') }}
                                </dd>
                            </div>
                        @endif

                        @if ($detail->requiredDependencies !== [])
                            <div>
                                <dt
                                    class="font-medium text-gray-950 dark:text-white"
                                >
                                    {{ __('capell-marketplace::marketplace.detail.dependencies_heading') }}
                                </dt>
                                <dd
                                    class="mt-1 text-gray-600 dark:text-gray-300"
                                >
                                    {{ implode(', ', $detail->requiredDependencies) }}
                                </dd>
                            </div>
                        @endif
                    </dl>
                @endif
            </section>

            <section
                class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
            >
                <h2
                    class="text-base font-semibold text-gray-950 dark:text-white"
                >
                    {{ __('capell-marketplace::marketplace.detail.access_heading') }}
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('capell-marketplace::marketplace.detail.access_body') }}
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($this->shouldVerifySite())
                        <x-filament::button
                            tag="a"
                            color="warning"
                            size="sm"
                            icon="heroicon-o-arrow-top-right-on-square"
                            href="{{ $this->marketplaceUrl() }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ __('capell-marketplace::marketplace.detail.verify_site_cta') }}
                        </x-filament::button>
                    @endif

                    @if ($this->canDownload())
                        <a
                            href="{{ $this->marketplaceUrl() }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="bg-success-50 text-success-800 ring-success-600/20 dark:bg-success-500/10 dark:text-success-200 rounded-md px-3 py-1.5 text-sm font-medium ring-1"
                        >
                            {{ __('capell-marketplace::marketplace.detail.download_available') }}
                        </a>
                    @endif

                    @if ($this->canInstall())
                        <a
                            href="{{ $this->marketplaceUrl() }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="bg-success-50 text-success-800 ring-success-600/20 dark:bg-success-500/10 dark:text-success-200 rounded-md px-3 py-1.5 text-sm font-medium ring-1"
                        >
                            {{ __('capell-marketplace::marketplace.detail.install_available') }}
                        </a>
                    @endif
                </div>
            </section>

            @if ($this->publicDocumentation() !== [])
                <section class="space-y-3">
                    <h2
                        class="text-base font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-marketplace::marketplace.detail.documentation_heading') }}
                    </h2>

                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($this->publicDocumentation() as $document)
                            <article
                                class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                            >
                                <h3
                                    class="font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ $document['title'] ?? __('capell-marketplace::marketplace.detail.documentation_item') }}
                                </h3>
                                @if (is_string($document['body'] ?? null))
                                    <p
                                        class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300"
                                    >
                                        {{ $document['body'] }}
                                    </p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($detail->versionHistory !== [])
                <section class="space-y-3">
                    <h2
                        class="text-base font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-marketplace::marketplace.detail.version_history_heading') }}
                    </h2>
                    <div class="space-y-2">
                        @foreach ($detail->versionHistory as $version)
                            <article
                                class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                            >
                                <p
                                    class="font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ $version['version'] ?? __('capell-marketplace::marketplace.card.unknown_version') }}
                                </p>
                                @if (is_string($version['summary'] ?? null))
                                    <p
                                        class="mt-1 text-gray-600 dark:text-gray-300"
                                    >
                                        {{ $version['summary'] }}
                                    </p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($this->canSubmitFeedback())
                <section
                    class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                >
                    <h2
                        class="text-base font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-marketplace::marketplace.feedback.heading') }}
                    </h2>

                    @if ($feedbackStatus !== null)
                        <p
                            role="status"
                            aria-live="polite"
                            class="text-success-700 dark:text-success-300 mt-2 text-sm font-medium"
                        >
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
                                        >
                                            {{ __('capell-marketplace::marketplace.feedback.required_suffix') }}
                                        </span>
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
                                    class="block w-full rounded-md border-gray-300 shadow-sm dark:border-white/10 dark:bg-gray-950"
                                />
                                @error('feedbackRating')
                                    <span
                                        id="feedback-rating-error"
                                        class="text-danger-600 dark:text-danger-400 text-sm"
                                    >
                                        {{ $message }}
                                    </span>
                                @enderror
                            </label>
                        @endif

                        @if ($this->canComment())
                            <label class="block space-y-1">
                                <span
                                    class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    {{ __('capell-marketplace::marketplace.feedback.comment_label') }}
                                </span>
                                <textarea
                                    id="feedback-comment"
                                    rows="4"
                                    wire:model="feedbackComment"
                                    aria-invalid="@error('feedbackComment') true @else false @enderror"
                                    @error('feedbackComment')
                                        aria-describedby="feedback-comment-error"
                                    @enderror
                                    class="block w-full rounded-md border-gray-300 shadow-sm dark:border-white/10 dark:bg-gray-950"
                                ></textarea>
                                @error('feedbackComment')
                                    <span
                                        id="feedback-comment-error"
                                        class="text-danger-600 dark:text-danger-400 text-sm"
                                    >
                                        {{ $message }}
                                    </span>
                                @enderror
                            </label>

                            <label class="block space-y-1">
                                <span
                                    class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    {{ __('capell-marketplace::marketplace.feedback.tip_label') }}
                                </span>
                                <textarea
                                    id="feedback-tip"
                                    rows="3"
                                    wire:model="feedbackTip"
                                    aria-invalid="@error('feedbackTip') true @else false @enderror"
                                    @error('feedbackTip')
                                        aria-describedby="feedback-tip-error"
                                    @enderror
                                    class="block w-full rounded-md border-gray-300 shadow-sm dark:border-white/10 dark:bg-gray-950"
                                ></textarea>
                                @error('feedbackTip')
                                    <span
                                        id="feedback-tip-error"
                                        class="text-danger-600 dark:text-danger-400 text-sm"
                                    >
                                        {{ $message }}
                                    </span>
                                @enderror
                            </label>
                        @endif

                        <x-filament::button type="submit">
                            {{ __('capell-marketplace::marketplace.feedback.submit') }}
                        </x-filament::button>
                    </form>
                </section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
