# DPADMIN-20261119 — Draft Preview browser probe

Verification-only Draft; never merge into the product. OLD Test Admin-only,
Migration none. After normal Admin login, open the existing dashboard `/` and
confirm the small text `Draft Preview 検証 A` above the unchanged dashboard.
The display has no controls, API requests, environment switch or domain behavior.

Existing Vitest/Testing Library dashboard tests check the exact text, passive
markup and page integration while retaining the existing shell and dashboard.
The shell is mocked only in the feature test; no production guard is changed.
Browser acceptance, formal image Build/import and Runtime activation are separate
Human GO steps and have not run in this preparation change.

Only after Human confirms A, update this same Draft to `Draft Preview 検証 B`,
update the focused expectation and record the new exact HEAD/Tree. The approved
Plan can retain its exact four-path inventory and commands. Do not change B now.
