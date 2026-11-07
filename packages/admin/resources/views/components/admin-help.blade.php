<details
    class="fi-section rounded-xl border border-gray-200 p-4 dark:border-white/10"
>
    <summary
        aria-label="{{ __('capell-admin::help.open') }}"
        class="cursor-pointer font-medium"
    >
        {{ __('capell-admin::help.open') }}
    </summary>
    <div class="mt-3 space-y-3">
        <h2 class="font-semibold">{{ $entry->title }}</h2>
        <p>{{ $entry->summary }}</p>
        <p>{{ $entry->nextAction }}</p>
        @if ($entry->docsUrl !== null)
            <a
                href="{{ $entry->docsUrl }}"
                >{{ __('capell-admin::help.documentation') }}</a
            >
        @endif
        <h3 class="font-semibold">{{ __('capell-admin::help.glossary') }}</h3>
        <dl>
            @foreach ([
                __('capell-admin::help.terms.blueprint') => __('capell-admin::glossary.blueprint'),
                __('capell-admin::help.terms.schema') => __('capell-admin::glossary.schema'),
                __('capell-admin::help.terms.interceptor') => __('capell-admin::glossary.interceptor'),
                __('capell-admin::help.terms.page_type') => __('capell-admin::glossary.page_type'),
                __('capell-admin::help.terms.workspace') => __('capell-admin::glossary.workspace'),
                __('capell-admin::help.terms.lockdown') => __('capell-admin::glossary.lockdown'),
                __('capell-admin::help.terms.fragment') => __('capell-admin::glossary.fragment'),
                __('capell-admin::help.terms.hydration') => __('capell-admin::glossary.hydration'),
                __('capell-admin::help.terms.canonical') => __('capell-admin::glossary.canonical'),
                __('capell-admin::help.terms.slug') => __('capell-admin::glossary.slug'),
                __('capell-admin::help.terms.locale') => __('capell-admin::glossary.locale'),
            ] as $term => $definition)
                <dt class="font-medium">{{ $term }}</dt>
                <dd>{{ $definition }}</dd>
            @endforeach
        </dl>
    </div>
</details>
