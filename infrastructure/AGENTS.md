# Infrastructure Rules

## Scope

These instructions apply to future infrastructure definitions under
`infrastructure/`. They refine the Root `AGENTS.md` and Release Gates.

## Site Isolation

- Give every customer Site independent servers, database, Redis, queues, object
  storage, secrets, providers, backups, deployment, and observability boundaries.
- Do not use a shared database, shared runtime, shared secret set, or `tenant_id`
  multi-tenancy.
- Do not reuse one Site's credentials, backups, provider accounts, or storage in
  another Site.

## Build And Deployment

- Build once in approved CI, identify the immutable image digest, and promote the
  same digest through environments.
- Never build an application image on a Production server.
- Use OIDC or separately scoped Site deployment credentials.
- Never provide Production secrets to Codex or store them in Repository files,
  Issues, PRs, logs, or build output.
- Platform Codex may manage approved environments and execute a deployment only
  after the applicable Release and Site Deployment Gates pass.
- The final GO for initial commercial Production remains human-only.

## Operational Safety

- Docker, volume, network, database, backup, and restore operations that can
  destroy or replace state require explicit human approval.
- Every release must have an approved manifest, rollback procedure, backup and
  restore evidence, and Site-specific deployment record.
- Do not weaken isolation or security controls to simplify deployment.

## Verification

- Validate manifests, digest pinning, policy checks, rollback paths, and
  environment separation without using Production credentials.
- Keep test deployment, Staging deployment, and Production deployment status
  distinct.
- Follow the Root autonomous GitHub lifecycle. Infrastructure PR merge does not
  authorize Production deployment, migration, or commercial GO.
