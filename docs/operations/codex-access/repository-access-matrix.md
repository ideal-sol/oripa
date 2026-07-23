# Codex Repository Access Matrix

## Status

- Status: CURRENT BASELINE
- Platform access: VERIFIED
- Site access: FUTURE REQUIRED GATE

## Matrix

| Subject | Platform `ideal-sol/oripa` | Site Template | Own customer Site | Other customer Sites |
| --- | --- | --- | --- | --- |
| Platform Codex | Write through task-policy GitHub App workflow | Write only while Platform manages the template | No write by default | No read or write |
| Luxe Pack Site Codex | No write | No write | Write only to the Luxe Pack repository | No read or write |
| Customer Site Codex | No write | No write | Write only to that customer's repository | No read or write |

`Write` never means direct `main` push, force push, gate bypass, or unrestricted
tag mutation. Repository governance and Release Gates still apply.

## Platform Codex

- Current verified repository: `ideal-sol/oripa`
- Current GitHub App: `ideal-sol-oripa-codex`
- Installation selection: selected repositories
- Customer Site access: none by default
- Site Template access: permitted only while that repository is explicitly
  managed as a Platform asset and selected for the Platform credential
- Production secrets, databases, and networks: not permitted

## Site Codex

Each Site Codex must use a repository and credential boundary dedicated to one
Site. A Site Codex must not:

- write to the Platform Repository;
- read or write another Site repository;
- receive another Site's secret or deployment credential;
- modify Platform contracts or packages directly; or
- treat a Site-local workaround as a Platform change.

Required Platform changes use a Platform Change Request and are implemented by
Platform Codex in `ideal-sol/oripa`.

## Provisioning State

The Site rows are policy requirements, not claims that Site repositories,
credentials, Apps, or Codex environments already exist. Provisioning and
verification are required before the first Site Codex task.

## Root Trust Exception

Platform Codex currently runs as `root`; OS user and private-key filesystem
isolation are not implemented. GitHub repository separation is nevertheless
verified through selected-repository Installation scope. This exception does
not permit secret disclosure or Production access.
