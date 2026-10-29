# Writing text for Capell interfaces

Write for the person using the screen, not for the code that produced it. Before writing, identify who can act, what has happened and what they need to do next. Check the real behaviour and permissions first; do not promise a retry, recovery or result the application cannot provide.

## Make the next step clear

- Name the task in the heading and use a specific action on the button: “Create page”, “Save draft” or “Remove installer”. Avoid “Continue” when the next screen is not obvious.
- On an error, say what failed and give the next safe action. If a developer or server administrator must act, say so and link to the relevant instructions. Do not tell an editor to inspect a log they cannot access.
- Explain disabled controls next to the control: say which permission, setting or prerequisite is missing and who can change it. A tooltip alone is not enough for touch or keyboard users.
- For a destructive action, name what will be removed, what remains and whether it can be recovered. Do not describe a destructive operation as a reset without explaining its effects.
- For a long-running action, say whether the user must keep the page open, what progress means and where to find a failure or resume safely.
- Show an empty state that distinguishes “nothing exists yet”, “nothing matches the filters” and “you cannot view this”. Offer a useful next action only when the user can perform it.

## Use familiar words

Use the words the intended reader uses for the task. Explain necessary technical terms at the point of use; keep exact configuration keys, command names and diagnostic details in developer guidance. Avoid filler such as “seamlessly”, “robust” and “leverage”. Do not replace precise technical language blindly: a developer who needs to change a queue setting needs the correct setting and command.

For example, instead of “A valid, unexpired operator bootstrap secret is required. Ask the server operator to configure a new grant”, use: “To protect your site, enter a temporary access code before starting the installer.” Follow it with: “Need a code? Ask the person who manages your server. If that is you, follow the instructions to create one.” Link directly to those instructions.

## Check the complete interaction

1. Read the heading, labels, help, button and result together. Remove repeated explanations and make the consequence of the action clear before it is taken.
2. Check the success, empty, disabled, loading, denied and failure states that apply. The message must match the actual state, including partial failure and permissions.
3. Check that the instruction is usable: its link reaches the right section, the named setting or command exists, and the person has the access needed. Never display a secret, access token or private diagnostic in a public page or URL.
4. Use translated strings and preserve placeholders, plural forms and accessible names. Put important guidance in visible text, associate it with its field, and announce errors to assistive technology.
5. Inspect the rendered screen at wide and narrow widths, with keyboard focus and long translated text. Headings, errors, controls and guidance must not overlap or disappear.
6. Verify the behaviour with tests for the states that matter. A copy or source-string assertion alone does not prove a link works or that an action is safe.

When the wording depends on an unresolved product or policy choice, ask the owner rather than making a promise in the interface.
