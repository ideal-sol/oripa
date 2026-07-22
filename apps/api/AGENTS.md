# API Application Rules

## Scope

These instructions apply to the future Laravel application under `apps/api/`.
They refine the Root `AGENTS.md` and must not weaken its security, isolation, or
domain invariants.

## Architecture

- Keep Laravel as a modular monolith with explicit domain boundaries.
- Laravel is the authority for draw selection, point accounting, inventory, and
  payment decisions.
- Keep Public API, Admin API, and Webhook routes, middleware, authentication,
  validation, resources, and rate limits separated.
- Keep User and Admin realms separated by guards, cookies, sessions, and
  authorization policy.
- Do not move business authority into Next.js, generated clients, or providers.
- Do not infer behavior for an undecided payment, identity, or delivery provider.

## Financial And Draw Changes

- Treat draw, point, inventory, payment, refund, chargeback, and authorization
  changes as R3 unless a stricter gate applies.
- Preserve transactional updates across draw results, points, lots, inventory,
  counters, and acquired prizes.
- Require explicit idempotency, row-locking, concurrency, rollback, and audit
  behavior where the domain can be retried or executed concurrently.
- Add transaction, idempotency, concurrency, and regression tests for affected
  critical flows.

## Database

- Migrations must be forward-safe and compatible with the approved rollout and
  rollback plan.
- Separate migration creation, test application, Staging application, and
  Production application in reports and approvals.
- Never run Production migrations or direct Production database operations.
- Do not rewrite or delete applied migration history without an explicit human
  decision.

## Verification

- Run the applicable Unit, Feature, contract, transaction, concurrency, and
  integration checks required by the task and Release Gates.
- Report tests not run separately; do not infer PASS from static review.
