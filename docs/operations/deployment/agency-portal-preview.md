# Agency Portal Shared Preview

AGENCY-002 is Strict Change with immediate Shared Preview activation after merge.
The explicit Human decision replaces only `ad.luxe-pack.biz` V1 routing with the
V2 Agency Portal. Production, other domains, V1 repositories/databases/services,
Storefront contracts and artifacts are outside this change.

## Activation

1. Re-read protected main, final-head required checks, fresh review, image OCI
   revisions, common tree and zero head-to-merge content diff.
2. Acquire the Preview deployment lock. Retain a private exact Nginx vhost backup
   and current API/Admin image identities and environment metadata.
3. Apply migration `000074` to the explicitly verified old Test database only;
   check pending zero. Keep the migration allocation lock through closeout.
4. Activate the API with exact `V2_AGENCY_ORIGIN=https://ad.luxe-pack.biz`,
   `V2_AGENCY_LOGIN_URL=https://ad.luxe-pack.biz/login` and `V2_AGENCY_MAILER=array`
   for synthetic technical acceptance. Never print environment values or tokens.
5. Activate the immutable Agency image as `oripa-v2-preview-agency` on loopback
   `127.0.0.1:3202`, after checking port availability. Internal port is 3000.
6. The Admin mail-template selector gains one necessary key; activate Admin once
   to expose that template. No other Admin feature or realm policy changes.
7. Replace only the Agency vhost: `/agency/api/v2/` to API loopback 8611, Portal
   routes to 3202; `/admin/`, `/api/` and `/webhooks/` return 404. Reuse the existing
   certificate. Run `nginx -t` before reload; verify TLS and V1 route non-reachability.
8. Run synthetic Agency technical acceptance with mail array/fake only; record
   actual source SHAs, cookie/CSRF rotation, revocation and gateway results.

## Rollback

Restore the exact backed-up Agency vhost and validate/reload Nginx. Retain other
domains and V1 services. Restore prior API/Admin image identities using preserved
runtime configuration. Stop only the newly added Agency runtime if necessary.
Once security history exists, retain migration `000074`; rollback its schema is
prohibited. Use a forward correction for data changes.

## Build And Contract Gates

Canonical `preview-image-build.yml` adds opt-in `agency` mode containing exactly
API, Admin and Agency image archives. Existing normal and API-only modes remain
unchanged. Each archive must pass source, platform, checksum, OCI and image-ID
verification. The GitHub read wrapper's safe ZIP inventory accepts this exact
three-image set; no arbitrary extra archive is accepted.

Human Browser Acceptance remains pending after technical acceptance. Use
`https://ad.luxe-pack.biz/` to check login, Admin-style layout, distinct teal,
own profile, contact, email, password, logout and re-login. Do not record QA
passwords in Git, Issues, PRs or reports.
