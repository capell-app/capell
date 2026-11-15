# Admin Package Instructions

- Keep Filament resources, pages, widgets, forms, and tables thin. Delegate domain work to Actions and carry structured state through Data objects.
- Use explicit types and translation keys for every user-facing label, helper, notification, and validation message.
- Extend schemas and surfaces through the registered extension APIs; do not publish or fork package schemas for ordinary customisation.
- Eager-load data needed by admin views and avoid hidden query multiplication in table columns or form callbacks.
- Admin previews and authoring helpers must not change the anonymous frontend contract or expose signed editor state publicly.
- Verify the smallest affected Pest file first; browser-test changed Filament workflows when behaviour depends on JavaScript, uploads, navigation, or permissions.
- Sidebar placement uses a `NavigationGroupEnum` case; never add groups, rely on URL-based placement or exceed the limits in `capell-admin.navigation`. Name groups and items after what people manage (Users, not People) per [UI writing](../../docs/standards/ui-writing.md).
