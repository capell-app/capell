<p class="text-sm text-gray-500 dark:text-gray-400">
    {{ __('capell-installer::widgets.delete_installer_reinstall_message') }}

    <x-filament::link
        :href="$installUrl"
        tag="a"
        target="_blank"
    >
        {{ __('capell-installer::widgets.delete_installer_reinstall_link') }}
    </x-filament::link>
</p>
