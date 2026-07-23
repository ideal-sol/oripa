# Documentation Rules

## Scope

These instructions apply to documentation under `docs/`. They refine the Root
`AGENTS.md` and do not supersede finalized baselines or explicit human decisions.

## Document Governance

- Maintain architecture baselines, ADRs, runbooks, release records, and migration
  records with clear ownership and status.
- Do not rewrite a finalized baseline without an explicit human decision.
- Keep specification approval, implementation, tests, Staging, release, and
  Production status separate.
- Never report an unexecuted test as PASS or an unverified deployment as complete.
- Do not create links to files that do not exist.
- Do not record secrets, credentials, real-user PII, non-public customer data, or
  private provider configuration.
- Avoid duplicating one decision into several documents in ways that can diverge;
  use one authoritative record and link to it when the target exists.

## Change Discipline

- Distinguish proposals, drafts, approved decisions, as-built evidence, and
  historical references.
- Preserve exact finalized filenames and the Root specification priority.
- Do not promote V1 behavior into V2 architecture without an approved decision.

## Verification

- Run Markdown structure, internal-link, filename, contradiction, secret, PII,
  and scope reviews applicable to the task.
- Record documentation-only test omissions explicitly.
- Follow the Root autonomous GitHub lifecycle. Governance documents require
  fixed-head self-review and contradiction review but do not require GitHub
  Approval or Code Owner Review before a gate-compliant squash merge.
