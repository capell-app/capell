// The Core workbench cannot capture the external Voyager admin.
export default async function requireVoyagerProfile() {
    throw new Error(
        'Use screenshots.voyager.config.mjs with the verified local Voyager runtime.',
    )
}
