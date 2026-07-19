# Admin Package Instructions

- Keep Filament resources, pages, widgets, forms, and tables thin. Delegate domain work to Actions and carry structured state through Data objects.
- Use explicit types and translation keys for every user-facing label, helper, notification, and validation message.
- Extend schemas and surfaces through the registered extension APIs; do not publish or fork package schemas for ordinary customisation.
- Eager-load data needed by admin views and avoid hidden query multiplication in table columns or form callbacks.
- Admin previews and authoring helpers must not change the anonymous frontend contract or expose signed editor state publicly.
- Verify the smallest affected Pest file first; browser-test changed Filament workflows when behaviour depends on JavaScript, uploads, navigation, or permissions.
