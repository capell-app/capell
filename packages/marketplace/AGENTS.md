# Marketplace Package Instructions

- Keep remote marketplace transport, signed responses, install coordination, and entitlement checks behind typed clients and Actions.
- Core package lifecycle records remain authoritative for installed and enabled state; do not create a second local truth.
- Validate manifest identity, compatibility, dependencies, and signatures before mutating installation state.
- Never expose tokens, signed payloads, provider errors, or account-only metadata in public output or logs.
- Test Actions directly with faked HTTP and cover failure transitions as well as the successful install path.
