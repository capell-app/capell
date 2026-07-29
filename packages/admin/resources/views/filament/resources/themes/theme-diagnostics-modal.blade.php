@php
    $diagnostics ??= null;
    $errors = is_object($diagnostics) ? $diagnostics->errors : [];
    $warnings = is_object($diagnostics) ? $diagnostics->warnings : [];
@endphp

<div class="space-y-4">
    @if ($errors !== [])
        <div>
            <h3
                class="text-danger-600 dark:text-danger-400 text-sm font-semibold"
            >
                {{ __('capell-admin::theme-library.labels.diagnostics_error') }}
            </h3>
            <ul
                class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200"
            >
                @foreach ($errors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($warnings !== [])
        <div>
            <h3
                class="text-warning-600 dark:text-warning-400 text-sm font-semibold"
            >
                {{ __('capell-admin::theme-library.labels.diagnostics_warning') }}
            </h3>
            <ul
                class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200"
            >
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($errors === [] && $warnings === [])
        <p class="text-sm text-gray-700 dark:text-gray-200">
            {{ __('capell-admin::theme-library.messages.validated_ok') }}
        </p>
    @endif
</div>
