# Site Template CI

## Purpose

The Site Template gate protects the boundary between the Platform repository
and independently deployed Site repositories. A Site consumes versioned
Platform contracts and thin clients; it does not copy or reimplement Platform
source.

## Required Site properties

- One Site uses one repository and one isolated deployment environment.
- `@oripa/storefront-client` and `@oripa/site-schema` use exact versions.
- Storefront API access goes through the thin client. Direct `fetch` to
  `/api/v2` is rejected.
- Laravel, Admin, draw, point, inventory, and payment decisions remain in the
  Platform.
- `site.config.json` declares schema version `1.0`, the Site role, package-only
  Platform consumption, and package versions matching `package.json`.
- `.env.example` contains no secret-bearing names, credential-like values, or
  another Site's configuration.
- Build, typecheck, lint, and contract-test commands are explicitly declared.

## Current validation scope

There is no canonical Site Template in the repository as of GOV-010. The gate
therefore validates a positive policy fixture and proves rejection with a
negative fixture. These fixtures test the gate implementation; they are not a
deployable Site and do not satisfy the Gate G1 canonical-template requirement.

The Site validation runs inside `integration-gate`. A Site failure therefore
also fails `ci-gate`. No additional required check context is introduced; the
five existing required contexts remain unchanged.

## Local reproduction

```text
python3 -m unittest discover -s tests/ci/site-template -p 'test_*.py'
python3 scripts/ci/site_template_gate.py \
  --template tests/ci/site-template/fixtures/positive
python3 scripts/ci/site_template_gate.py \
  --template tests/ci/site-template/fixtures/negative
```

The first two commands must pass. The final command must fail. Backend,
frontend, package, and contract runtime checks become applicable to the real
template when that template is created in a separately approved task.
