@if ($alerts !== [])
    <section
        class="border-danger-200 bg-danger-50 text-danger-900 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-100 rounded-lg border p-4 text-sm shadow-sm"
    >
        <h2 class="font-semibold">
            {{ __('capell-marketplace::marketplace.health_alerts.critical_heading') }}
        </h2>

        <div class="mt-3 space-y-3">
            @foreach ($alerts as $alert)
                <article>
                    <h3 class="font-medium">
                        {{ $alert->title }}
                    </h3>
                    <p class="mt-1">
                        {{ $alert->message }}
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endif
