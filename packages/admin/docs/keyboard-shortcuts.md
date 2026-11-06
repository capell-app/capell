# Keyboard shortcuts

Use Command on macOS and Ctrl on other platforms for the shortcuts shown as Mod. Shortcuts contributed by extensions and any customised shortcuts may differ from the defaults below.

| Shortcut        | Action                            | Where                                        |
| --------------- | --------------------------------- | -------------------------------------------- |
| Mod + K         | Open or close the command palette | Admin                                        |
| Escape          | Close the command palette         | Palette                                      |
| Mod + S         | Save changes                      | Record editors and creation forms            |
| Mod + D         | Open the delete confirmation      | Where the delete action is available         |
| Mod + Shift + S | Create and create another         | Creation forms where the action is available |
| G then P        | Go to Pages                       | Admin, outside text fields                   |
| G then S        | Go to Sites                       | Admin, outside text fields                   |
| G then T        | Go to Themes                      | Admin, outside text fields                   |
| G then ,        | Go to Settings                    | Admin, outside text fields                   |
| G then W        | Go to Publishing Studio           | Admin, outside text fields                   |
| /               | Focus global search               | Admin, outside text fields                   |

Press the keys in a sequence within one second. Navigation shortcuts can be configured with `capell-admin.shortcuts`; their current labels and keys appear under Keyboard shortcuts in the command palette when their destination is an accessible core or registered extension palette command. Themes requires an accessible Themes resource; Publishing Studio requires the optional Workspace resource to be installed, registered on the panel and accessible. Shortcuts for destinations you cannot access are hidden. A shortcut only works when its target is available and you have access to it.

Core navigation shortcuts resolve against accessible core commands only. Extensions cannot replace a reserved core ID or restore a denied shortcut using a matching ID or URL. Extension navigation shortcuts must target an exact extension command ID with an explicit visibility callback that allows the current actor and a URL. Extension commands without a callback remain visible to authenticated panel users, but cannot enable navigation shortcuts. Register commands and their callbacks at boot, rather than per request; callbacks are evaluated per actor and must never be serialised or queued.

Search filters the rendered list locally after a 250 ms debounce, with no server request while typing. The keyboard listener is removed on component destruction and before Livewire navigation, so the next page uses its own authorised shortcuts.

The keyboard handler, command list and shortcut help share one access decision per page render. Palette updates check access again in the original page’s site context. Permission revocation is reflected on the next render, including direct permission and role pivot changes.
