# V2 Preview API Egress Boundary

## Status

This is the as-built non-Production V2 Preview runtime boundary. It does not
authorize a Production deployment or commercial Production GO.

## Network Model

- `v2_private` remains `internal: true` and carries API, Admin, PostgreSQL, and
  Redis traffic.
- `v2_api_egress` is a separate bridge network attached only to the API service.
- Its small IPv4 subnet defaults to `192.168.62.0/28` and may be replaced with
  `V2_API_EGRESS_SUBNET`; it must not overlap the private or host networks.
- Admin, PostgreSQL, and Redis must not join `v2_api_egress`.
- PostgreSQL and Redis expose no host ports and retain their isolated volumes.
- No canonical outbound proxy is defined by the current finalized Governance;
  the non-Production Preview therefore uses the API-only bridge directly.

## Deployment

Build the API image from the fixed, reviewed source SHA. Record its immutable
image ID and OCI revision, then recreate only the API service with the canonical
external environment source and Compose override. Do not build or recreate
Admin, PostgreSQL, or Redis as part of an API egress activation.

## Verification

Verify without displaying secret values:

- the API joins both networks while all other services join only `v2_private`;
- `v2_private` is internal and `v2_api_egress` is not internal;
- the canonical notifier and Mailgun mailer resolve as configured;
- Mailgun-required environment variables are non-empty;
- API health, PostgreSQL, Redis, DNS, TCP/443, HTTPS, and Public API checks pass;
- the activation window contains no HTTP 500, 502, or 504 responses.

Real registration, resend, verification-complete, and recipient email delivery
are separate human-operated checks and must not be executed by this procedure.
