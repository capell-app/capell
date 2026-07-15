<div class="space-y-4 text-sm">
    <div class="grid grid-cols-3 gap-3">
        <div>
            <strong>
                {{ __('capell-admin::bulk_actions.preview_changes') }}
            </strong>
            <br />
            {{ $preview->changed() }}
        </div>
        <div>
            <strong>
                {{ __('capell-admin::bulk_actions.preview_unchanged') }}
            </strong>
            <br />
            {{ $preview->unchanged() }}
        </div>
        <div>
            <strong>
                {{ __('capell-admin::bulk_actions.preview_blocked') }}
            </strong>
            <br />
            {{ $preview->blocked() }}
        </div>
    </div>

    @if ($preview->blocked() > 0)
        <ul class="list-disc space-y-1 ps-5">
            @foreach ($preview->records as $record)
                @if (in_array($record['result']->outcome->value, ['unauthorized', 'invalid-transition', 'failed'], true))
                    <li>
                        {{ $record['label'] }} —
                        {{ __('capell-admin::bulk_actions.outcome_' . $record['result']->outcome->value) }}
                    </li>
                @endif
            @endforeach
        </ul>
    @endif
</div>
