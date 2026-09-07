# Agency Portal

The Agency browser application uses `/agency/api/v2` exclusively. Laravel's
`agencies` record and dedicated Agency session determine the authenticated scope.
No Admin permissions, User identity, wallet, aggregation or attribution authority
belongs in this application.

The Portal imports the existing Admin CSS and presentation-only page header.
`agency-theme` overrides the existing accent tokens with teal. Authentication,
navigation and Account Settings remain Agency-specific.

Run `pnpm --filter @oripa/agency generate` after `pnpm openapi:bundle`.
`pnpm agency:check` checks generated drift, TypeScript, lint, tests and build.
The versioned Agency contract is independent of Storefront artifacts.

The standalone Docker runtime listens on port 3000 internally. Preview host
routing and activation are documented in the AGENCY-002 deployment runbook.
