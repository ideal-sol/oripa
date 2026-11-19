# DPADMIN-20261119 — Draft Preview browser probe

Verification-only Draft; never merge into the product. OLD Test Admin-only,
Migration none. After normal Admin login, open the existing dashboard `/` and
confirm the small text `Draft Preview 検証 B` above the unchanged dashboard.
The display has no controls, API requests, environment switch or domain behavior.

Existing Vitest/Testing Library dashboard tests check the exact text, passive
markup and page integration while retaining the existing shell and dashboard.
The shell is mocked only in the feature test; no production guard is changed.
Human confirmed `Draft Preview 検証 A` in the OLD Test browser after the approved
Admin-only Runtime Technical PASS. A used Source
`aba8e9387e5d224cb362aababac0d31389ff5f29` and Image
`sha256:041937ca10fb3b130a89954f645c17b63f68adb691b5e4462a3fc97d7dba1488`.

This B revision updates only the passive display, focused expectation and records
in the same unmerged Draft. Reuse the protected-main Plan's exact four-path
inventory and commands. The new head needs its own policy/security/focused
evidence, Admin-only Artifact and Image verification. Full DB CI and merge are
not prerequisites for Draft Preview preparation. Keep Runtime A running until
the separate Exact B Runtime GO. B browser acceptance is not yet performed.
