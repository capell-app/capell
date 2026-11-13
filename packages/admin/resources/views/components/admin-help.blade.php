@php
    /**
     * @var \Capell\Admin\Data\AdminHelpEntryData $entry
     * @var list<array{term: string, instead_of: string, meaning: string}> $glossary
     */
@endphp

<details
    class="fi-section rounded-xl border border-gray-200 p-4 dark:border-white/10"
    data-admin-help
>
    <summary class="cursor-pointer font-medium">
        {{ __('capell-admin::help.open') }}
    </summary>
    <div class="mt-3 space-y-3">
        <h2 class="font-semibold">{{ $entry->title }}</h2>
        <p>{{ $entry->summary }}</p>
        <p>{{ $entry->nextAction }}</p>
        @if ($entry->answers !== [])
            <h3 class="font-semibold">
                {{ __('capell-admin::help.answers') }}
            </h3>
            <div
                class="space-y-2"
                data-admin-help-answers
            >
                @foreach ($entry->answers as $answer)
                    <details data-admin-help-answer>
                        <summary class="cursor-pointer">
                            {{ $answer->question }}
                        </summary>
                        <p class="mt-1">{{ $answer->answer }}</p>
                    </details>
                @endforeach
            </div>
        @endif

        @if ($entry->docsUrl !== null)
            <a href="{{ $entry->docsUrl }}">
                {{ __('capell-admin::help.documentation') }}
            </a>
        @endif

        @if ($glossary !== [])
            <h3 class="font-semibold">
                {{ __('capell-admin::help.glossary') }}
            </h3>
            <dl data-admin-help-glossary>
                @foreach ($glossary as $word)
                    <dt class="font-medium">{{ $word['term'] }}</dt>
                    <dd>{{ $word['meaning'] }}</dd>
                @endforeach
            </dl>
        @endif
    </div>
</details>
