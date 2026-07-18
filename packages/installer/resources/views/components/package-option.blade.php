@props([
    'package',
    'inputName' => 'packages[]',
    'selected' => [],
    'core' => false,
    'extension' => true,
])

@php
    $requirements = $package['requirements'] ?? [];
    $defaultCore = (bool) ($package['defaultCore'] ?? $core);
@endphp

<label
    class="checkbox-row package-option"
    data-package-row="{{ $package['name'] }}"
>
    <input
        type="checkbox"
        name="{{ $inputName }}"
        value="{{ $package['name'] }}"
        data-package-checkbox="{{ $package['name'] }}"
        data-package-core="{{ $core || $defaultCore ? 'true' : 'false' }}"
        data-package-default-core="{{ $defaultCore ? 'true' : 'false' }}"
        data-package-default="{{ ($package['defaultSelected'] ?? false) ? 'true' : 'false' }}"
        data-package-extension="{{ $extension ? 'true' : 'false' }}"
        @checked(in_array($package['name'], $selected, true))
    />
    <span class="text">
        <strong>{{ $package['label'] }}</strong>

        @if ($package['description'])
            <span>{{ $package['description'] }}</span>
        @endif

        @if ($requirements)
            <span class="package-meta">
                {{ __('capell-installer::installer.requires') }}:
                {{ implode(', ', $requirements) }}
            </span>
        @endif

        <span
            class="required-badge"
            data-required-badge="{{ $package['name'] }}"
        ></span>
    </span>
</label>
