# AGENTS.md

## Purpose

This file governs Codex work in the `ideal-sol/oripa` Platform Repository.

Keep this document concise. Detailed product, architecture, migration, release,
and security decisions belong in the finalized documents and ADRs listed below.
Directory-specific rules may be added by later tasks without weakening this file.

## Project

- Oripa V2 is a package product distributed separately to each customer site.
- Each customer receives a fully independent environment and release lifecycle.
- The Platform Repository is `ideal-sol/oripa`.
- Laravel is the modular-monolith system of record for business behavior.
- Storefront, Admin, Public API, Admin API, and Webhook are separate
  responsibility and HTTP surfaces even when deployed from one product source.
- V1 code and specifications are behavioral references, not the V2 architecture.

## Specification Priority

Resolve conflicts in this order:

1. Latest explicit human decision
2. `V2_RELEASE_GATES_FINAL_2026-07-22.md`
3. `V2_CODEX_GIT_CI_GOVERNANCE_FINAL_2026-07-22.md`
4. `V1_TO_V2_MIGRATION_PLAN_FINAL_2026-07-22.md`
5. `V2_PACKAGE_VERSION_COMPATIBILITY_POLICY_FINAL_2026-07-22.md`
6. `V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md`
7. `V2_DATA_POINT_PAYMENT_BASELINE_FINAL_2026-07-22.md`
8. `API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md`
9. Approved feature ADRs
10. V1 specifications and implementation as behavioral references

The finalized V2 documents are not yet committed to this Repository. Until a
later governance task places them, use the exact filenames above as the reading
order and do not create broken links or unofficial copies.

Do not use the non-revision Identity/Authorization/Security document. Only the
`REV1` filename above is authoritative.

Context-only handoff documents do not override finalized documents or the
latest explicit human decision.

## Codex Roles

### Platform Codex

Platform Codex owns changes to:

- Laravel modules and shared domain behavior
- PostgreSQL schema and migrations
- Public, Admin, and Webhook APIs
- OpenAPI contracts and generated contract artifacts
- Admin application
- Authentication, authorization, sessions, MFA, and audit controls
- Point, payment, refund, chargeback, draw, inventory, and prize behavior
- The thin Storefront Client contract
- Site schema and Storefront testkit
- Infrastructure, CI, release, compatibility, and version policy

Platform Codex may implement, test, commit to an approved task branch, push that
branch through approved tooling, and create a Draft PR. It must not merge,
approve a stable release, or approve Production.

### Site Codex

- Site Codex works only in its assigned customer Site Repository and paths.
- Site Codex must not modify the Platform Repository directly.
- Platform changes required by a Site must be raised as a Platform Change
  Request and handled by Platform Codex.
- Site Repository, credentials, deployment access, and customer data must not be
  shared with another Site Codex.

## Core Invariants

### Site Isolation

- Every Site has independent servers, database, Redis, object storage, secrets,
  providers, backups, deployment, and observability boundaries.
- Do not use a shared database, shared runtime, or `tenant_id` multi-tenancy.
- Do not share User, Admin, payment credentials, storage keys, or backup data
  between Sites.

### Domain Authority and Transactions

- Draw selection, point accounting, wallet/lot accounting, inventory, and
  payment decisions are implemented in Laravel only.
- Storefront and Admin must not contain lottery or accounting authority.
- Draw, point consumption, wallet/lot mutation, inventory, `sold_count`,
  `won_count`, results, and acquired prizes are committed in one transaction.
- Use cryptographically secure randomness and the approved probability model.
- Never allow wallets or point lots to become negative.

### Point and Payment

- One paid point equals one JPY.
- Paid points do not expire.
- Consume free points before paid points; consume paid lots FIFO.
- Points granted as a purchase bonus are free points.
- Refunds and chargebacks are recorded and processed as adjustments.
- Payment provider behavior must follow an approved provider contract.
- Mock payment must never be enabled in Production.

### Identity and Authorization

- User and Admin are separate realms with separate guards, cookies, and sessions.
- Admin MFA is mandatory.
- Password length is 8 through 128 characters.
- Owner, Admin, and Operator permissions remain explicit and auditable.
- Owner may self-approve an Owner-initiated paid-point manual adjustment.
- Security-sensitive operations require the approved re-authentication and audit
  controls; do not infer missing policy.

### API and Storefront

- Public API v2 is contract-first and separate from Admin and Webhook APIs.
- Use the thin `@oripa/storefront-client`; do not build a large Storefront SDK.
- Storefront code must not directly fetch `/api/v2` outside the approved client.
- Do not expose Admin, provider, probability-internal, secret, or infrastructure
  data through Public contracts.

## Task Start Procedure

1. Use one Task, one GitHub Issue, one branch, one worktree, and one PR.
2. Read this file and every applicable nested `AGENTS.md`.
3. Re-read the finalized V2 documents relevant to the Task.
4. Confirm Repository root, `git status`, current branch, HEAD, and Remote refs.
5. Confirm Issue ID, Risk, base SHA, Allowed Paths, Forbidden Paths, and tests.
6. Stop if the worktree contains an unrecognized change or the Remote moved.
7. Work only in the dedicated task worktree; do not switch the main worktree.
8. Do not infer undecided provider, legal, security, or accounting behavior.
9. Do not use Production secrets, credentials, or real-user PII.
10. Record work in `worklogs/new_ver_main.md` and the GitHub Issue/PR.

The latest human decision explicitly adopts both GitHub Issue/PR records and
`worklogs/new_ver_main.md`. Keep the Worklog free of secrets and PII.

## Git and Command Prohibitions

Do not run or perform:

- `git reset --hard`
- `git clean -fd` or `git clean -fdx`
- `git checkout -- .`
- `git restore .`
- direct `git push origin main`
- force push or history rewriting of shared refs
- moving or deleting stable tags
- deleting or overwriting Archive refs
- `docker system prune -a --volumes`
- Production migrations or direct Production database operations
- builds on Production servers
- displaying, logging, committing, or copying secrets
- Codex merge, release approval, or Production approval

Destructive commands require an explicit approved task even outside Production.
Never weaken a security control to make a check pass.

Platform Codex currently runs as root, and human approval permits access to the
GitHub App credential files. That approval does not permit outputting secret
values, recording them in the Worklog, storing them in the Repository, placing
them in process arguments, or persisting them in Git configuration.

## Public Repository Policy

- The Repository is currently Public under a GitHub Free Organization.
- Public status is an explicit human override of the former private-by-default
  policy.
- Never commit Production secrets, customer information, PII, payment
  credentials, private provider configuration, or non-public infrastructure
  details.
- Treat every committed file, Issue, PR, log, and artifact as publicly readable.
- If public-safe disclosure is uncertain, stop and request human review.

## Verification

- Run the checks required by the Issue and applicable Release Gate.
- Keep executed, passed, failed, and not-run tests separate.
- Keep migration creation separate from migration application.
- Keep implementation, commit, push, Staging, and Production status separate.
- Do not claim Browser/E2E or visual verification unless it was executed.
- Documentation-only tasks do not imply Backend, Frontend, Build, or E2E PASS.
- Review changed paths, generated files, binary files, submodules, secrets, PII,
  and contract impact before commit and push.

## Completion and Reporting

Codex completion is a reviewable Draft PR, not a merge.

At completion, report:

- Issue and Risk
- branch, worktree, base SHA, commit SHA, and Remote SHA
- changed files and explicit out-of-scope areas
- tests/checks run, PASS/FAIL, and tests not run with reasons
- migrations created and migrations applied
- API, database, auth, point, payment, draw, and infrastructure impact
- known risks, unresolved decisions, and rollback notes
- Draft PR URL and author

Only a human may review and approve the PR, merge it, create or approve a stable
release, or approve Production deployment.
